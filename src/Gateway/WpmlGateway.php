<?php
/**
 * WPML + WooCommerce Multilingual implementation of the multilingual gateway.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\IdPage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml\CurrencySettingsParser;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml\SyncRules;
use wpdb;

/**
 * The only place in the plugin that talks to WPML or WCML.
 *
 * Uses WPML's public hooks wherever one exists. Direct SQL is used for two things only, each
 * explained where it happens: listing originals, and term ID <-> term_taxonomy_id mapping.
 * Behavior verified against WPML 5.1.0 / WCML 5.6.3 (docs/api-notes.md §5).
 *
 * Never calls WCML's custom-price API: `WCML_Custom_Prices::get_product_custom_prices()` writes
 * post meta while reading. Prices are read from raw meta instead.
 */
final class WpmlGateway implements MultilingualGateway {

	/**
	 * Per-request memo of the active languages.
	 *
	 * @var list<Language>|null
	 */
	private ?array $languages = null;

	/**
	 * Per-request memo of the sync settings.
	 *
	 * @var SyncSettings|null
	 */
	private ?SyncSettings $sync_settings = null;

	/**
	 * Per-request memo of the currency settings.
	 *
	 * @var CurrencySettings|null
	 */
	private ?CurrencySettings $currencies = null;

	/**
	 * Creates the gateway.
	 *
	 * @param wpdb $wpdb WordPress database access.
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * {@inheritDoc}
	 */
	public function is_setup_complete(): bool {
		return (bool) apply_filters( 'wpml_setting', false, 'setup_complete' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function active_languages(): array {
		if ( null !== $this->languages ) {
			return $this->languages;
		}

		$default = $this->default_language();
		$raw     = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
		$found   = array();

		foreach ( is_array( $raw ) ? $raw : array() as $language ) {
			if ( ! is_array( $language ) || ! isset( $language['code'] ) || ! is_string( $language['code'] ) ) {
				continue;
			}

			$code         = $language['code'];
			$native_name  = is_string( $language['native_name'] ?? null ) ? $language['native_name'] : $code;
			$english_name = apply_filters( 'wpml_translated_language_name', null, $code, 'en' );

			// WPML's own `active` key means "current language", not "enabled"; every language here is enabled.
			$found[] = new Language(
				$code,
				$native_name,
				is_string( $english_name ) && '' !== $english_name ? $english_name : $native_name,
				$code === $default
			);
		}

		usort( $found, static fn ( Language $a, Language $b ): int => array( ! $a->is_default, $a->code ) <=> array( ! $b->is_default, $b->code ) );

		$this->languages = $found;

		return $found;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_language(): string {
		$code = apply_filters( 'wpml_default_language', null );

		return is_string( $code ) ? $code : '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function element_language( int $id, ElementType $type ): ?string {
		$element_id = $this->to_element_id( $id, $type );
		if ( null === $element_id ) {
			return null;
		}

		$details = apply_filters(
			'wpml_element_language_details',
			null,
			array(
				'element_id'   => $element_id,
				'element_type' => $type->value,
			)
		);

		return is_object( $details ) && isset( $details->language_code ) && is_string( $details->language_code ) && '' !== $details->language_code
			? $details->language_code
			: null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Includes translations in every post status (draft, pending, private, trash); callers decide what counts.
	 */
	public function translations( int $id, ElementType $type ): array {
		$translations = array();
		foreach ( $this->translation_rows( $id, $type ) as $language => $row ) {
			$translations[ $language ] = $row['id'];
		}

		ksort( $translations );

		return $translations;
	}

	/**
	 * {@inheritDoc}
	 *
	 * One hook round-trip per ID; WPML caches translation groups per request. Replace with a
	 * single `icl_translations` query per page if measurements show it is too slow (concept §8).
	 */
	public function translations_of( array $ids, ElementType $type ): array {
		$result = array();
		foreach ( $ids as $id ) {
			$result[ $id ] = $this->translations( $id, $type );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Derived from the translation group instead of `wpml_original_element_id`, which applies
	 * WPML's front-end visibility policy without a way to opt out (see translation_rows()).
	 */
	public function original_id( int $id, ElementType $type ): ?int {
		foreach ( $this->translation_rows( $id, $type ) as $row ) {
			if ( $row['original'] ) {
				return $row['id'];
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Direct SQL: WPML has no public API to list originals across languages (an original is
	 * the row with `source_language_code IS NULL`, independent of the default language), and
	 * WP_Query is filtered to the current language. Paginated, uses the `el_type_id` index.
	 */
	public function original_ids( ElementType $type, int $limit, int $offset, array $post_statuses = array() ): IdPage {
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );

		return $type->is_term()
			? $this->original_term_ids( $type, $limit, $offset )
			: $this->original_post_ids( $type, $limit, $offset, $post_statuses );
	}

	/**
	 * Original posts of a post type. See {@see self::original_ids()}.
	 *
	 * @param ElementType  $type          Post element type.
	 * @param int          $limit         Page size.
	 * @param int          $offset        Offset.
	 * @param list<string> $post_statuses Statuses to include; empty = any except trash and auto-draft.
	 */
	private function original_post_ids( ElementType $type, int $limit, int $offset, array $post_statuses ): IdPage {
		$wpdb = $this->wpdb;
		$base = array( $this->translations_table(), $wpdb->posts, $type->name(), $type->value );

		// Statuses are passed as one comma-separated value (FIND_IN_SET) to keep the SQL literal; status slugs contain no commas.
		if ( array() === $post_statuses ) {
			$count_sql = "SELECT COUNT(*) FROM %i t INNER JOIN %i p ON p.ID = t.element_id AND p.post_type = %s
				WHERE t.element_type = %s AND t.source_language_code IS NULL AND p.post_status NOT IN ('trash', 'auto-draft')";
			$ids_sql   = "SELECT p.ID FROM %i t INNER JOIN %i p ON p.ID = t.element_id AND p.post_type = %s
				WHERE t.element_type = %s AND t.source_language_code IS NULL AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY p.ID ASC LIMIT %d OFFSET %d";
			$args      = $base;
		} else {
			$count_sql = 'SELECT COUNT(*) FROM %i t INNER JOIN %i p ON p.ID = t.element_id AND p.post_type = %s
				WHERE t.element_type = %s AND t.source_language_code IS NULL AND FIND_IN_SET( p.post_status, %s )';
			$ids_sql   = 'SELECT p.ID FROM %i t INNER JOIN %i p ON p.ID = t.element_id AND p.post_type = %s
				WHERE t.element_type = %s AND t.source_language_code IS NULL AND FIND_IN_SET( p.post_status, %s )
				ORDER BY p.ID ASC LIMIT %d OFFSET %d';
			$args      = array_merge( $base, array( implode( ',', $post_statuses ) ) );
		}

		// The SQL strings are literals chosen above; the sniffs cannot follow them through a variable.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Read-only report query, see original_ids().
		$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( $ids_sql, ...array_merge( $args, array( $limit, $offset ) ) ) );
		// phpcs:enable

		return new IdPage( array_map( 'intval', $ids ), $total );
	}

	/**
	 * Original terms of a taxonomy, as term IDs. See {@see self::original_ids()}.
	 *
	 * @param ElementType $type   Term element type.
	 * @param int         $limit  Page size.
	 * @param int         $offset Offset.
	 */
	private function original_term_ids( ElementType $type, int $limit, int $offset ): IdPage {
		$wpdb  = $this->wpdb;
		$table = $this->translations_table();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Read-only report query, see original_ids().
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i t INNER JOIN %i tt ON tt.term_taxonomy_id = t.element_id AND tt.taxonomy = %s
				WHERE t.element_type = %s AND t.source_language_code IS NULL',
				$table,
				$wpdb->term_taxonomy,
				$type->name(),
				$type->value
			)
		);
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT tt.term_id FROM %i t INNER JOIN %i tt ON tt.term_taxonomy_id = t.element_id AND tt.taxonomy = %s
				WHERE t.element_type = %s AND t.source_language_code IS NULL ORDER BY tt.term_id ASC LIMIT %d OFFSET %d',
				$table,
				$wpdb->term_taxonomy,
				$type->name(),
				$type->value,
				$limit,
				$offset
			)
		);
		// phpcs:enable

		return new IdPage( array_map( 'intval', $ids ), $total );
	}

	/**
	 * Name of WPML's translations table.
	 */
	private function translations_table(): string {
		return $this->wpdb->prefix . 'icl_translations';
	}

	/**
	 * {@inheritDoc}
	 */
	public function sync_settings(): SyncSettings {
		if ( null === $this->sync_settings ) {
			$management  = apply_filters( 'wpml_setting', array(), 'translation-management' );
			$preferences = is_array( $management ) && isset( $management['custom_fields_translation'] ) && is_array( $management['custom_fields_translation'] )
				? $management['custom_fields_translation']
				: array();

			$this->sync_settings = SyncRules::from_preferences( $preferences, $this->is_wcml_running() );
		}

		return $this->sync_settings;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Reads the base currency from the option: `get_woocommerce_currency()` is filtered by WCML to the visitor's currency.
	 */
	public function currencies(): CurrencySettings {
		if ( null === $this->currencies ) {
			$default          = get_option( 'woocommerce_currency' );
			$this->currencies = CurrencySettingsParser::parse( get_option( '_wcml_settings' ), is_string( $default ) ? $default : '', $this->is_wcml_running() );
		}

		return $this->currencies;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_manual_prices( int $product_id ): bool {
		$original = $this->original_product_id( $product_id );

		return null !== $original && (bool) get_post_meta( $original, '_wcml_custom_prices_status', true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function manual_regular_price( int $product_id, string $currency ): ?string {
		$original = $this->original_product_id( $product_id );
		if ( null === $original || 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return null;
		}

		$price = get_post_meta( $original, '_regular_price_' . $currency, true );

		return is_string( $price ) && '' !== $price ? $price : null;
	}

	/**
	 * The element's translation group as object IDs, keyed by language.
	 *
	 * Passes `$all_statuses = true`: outside wp-admin (REST, MCP, CLI) WPML otherwise applies a
	 * front-end visibility policy that drops e.g. variations and non-public statuses
	 * (WPML_Translations, verified in WPML 5.1.0). Abilities are capability-gated and must see
	 * every translation.
	 *
	 * @param int         $id   Object ID of any member of the group.
	 * @param ElementType $type Element type.
	 * @return array<string, array{id: int, original: bool}>
	 */
	private function translation_rows( int $id, ElementType $type ): array {
		$element_id = $this->to_element_id( $id, $type );
		if ( null === $element_id ) {
			return array();
		}

		$trid = apply_filters( 'wpml_element_trid', null, $element_id, $type->value );
		if ( ! is_numeric( $trid ) || (int) $trid <= 0 ) {
			return array();
		}

		$rows   = apply_filters( 'wpml_get_element_translations', null, (int) $trid, $type->value, false, true );
		$result = array();

		foreach ( is_array( $rows ) ? $rows : array() as $language => $row ) {
			// WPML's trid lookup ignores the element type for posts, so check the type of every row.
			if ( ! is_string( $language ) || ! is_object( $row ) || ! isset( $row->element_id, $row->element_type ) || $type->value !== $row->element_type || ! is_numeric( $row->element_id ) ) {
				continue;
			}

			$object_id = $this->to_object_id( (int) $row->element_id, $type );
			if ( null !== $object_id ) {
				$result[ $language ] = array(
					'id'       => $object_id,
					'original' => ! isset( $row->source_language_code ) || '' === $row->source_language_code,
				);
			}
		}

		return $result;
	}

	/**
	 * Whether WCML is loaded and running: WCML only creates this global when its own dependency check passes.
	 */
	private function is_wcml_running(): bool {
		return isset( $GLOBALS['woocommerce_wpml'] );
	}

	/**
	 * Original of a product or variation, falling back to the ID itself when it has no language.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private function original_product_id( int $product_id ): ?int {
		$post_type = get_post_type( $product_id );
		if ( 'product' !== $post_type && 'product_variation' !== $post_type ) {
			return null;
		}

		return $this->original_id( $product_id, ElementType::post_type( $post_type ) ) ?? $product_id;
	}

	/**
	 * Maps an object ID to WPML's element ID (term_taxonomy_id for terms).
	 *
	 * Direct SQL instead of get_term(): WPML can filter get_term() to swap IDs into the current
	 * language ("adjust IDs"), which would silently return the wrong element.
	 *
	 * @param int         $id   Object ID.
	 * @param ElementType $type Element type.
	 */
	private function to_element_id( int $id, ElementType $type ): ?int {
		if ( $id <= 0 ) {
			return null;
		}

		if ( ! $type->is_term() ) {
			return $id;
		}

		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See method doc; read-only lookup on a unique key.
		$element_id = $wpdb->get_var( $wpdb->prepare( 'SELECT term_taxonomy_id FROM %i WHERE term_id = %d AND taxonomy = %s', $wpdb->term_taxonomy, $id, $type->name() ) );

		return is_numeric( $element_id ) ? (int) $element_id : null;
	}

	/**
	 * Maps WPML's element ID back to an object ID (term_id for terms). See {@see self::to_element_id()}.
	 *
	 * @param int         $element_id WPML element ID.
	 * @param ElementType $type       Element type.
	 */
	private function to_object_id( int $element_id, ElementType $type ): ?int {
		if ( ! $type->is_term() ) {
			return $element_id;
		}

		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See to_element_id(); read-only lookup on the primary key.
		$term_id = $wpdb->get_var( $wpdb->prepare( 'SELECT term_id FROM %i WHERE term_taxonomy_id = %d AND taxonomy = %s', $wpdb->term_taxonomy, $element_id, $type->name() ) );

		return is_numeric( $term_id ) ? (int) $term_id : null;
	}
}
