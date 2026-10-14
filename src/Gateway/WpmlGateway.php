<?php
/**
 * WPML + WooCommerce Multilingual implementation of the multilingual gateway.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoveragePage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoverageRow;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\IdPage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\LanguageCoverage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationPair;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml\CurrencySettingsParser;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml\SyncRules;
use InvalidArgumentException;
use wpdb;

/**
 * The only place in the plugin that talks to WPML or WCML.
 *
 * Uses WPML's public hooks wherever one exists. Direct SQL is used only where no hook exists or
 * hooks would mean one call per original, each explained where it happens: listing originals,
 * translation coverage, and term ID <-> term_taxonomy_id mapping.
 * Behavior verified against WPML 5.1.0 / WCML 5.6.3 (docs/api-notes.md §5).
 *
 * Never calls WCML's custom-price API: `WCML_Custom_Prices::get_product_custom_prices()` writes
 * post meta while reading. Prices are read from raw meta instead.
 */
final class WpmlGateway implements MultilingualGateway {

	use AssertsQueries;

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
		$this->assert_query_succeeded();

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
		$this->assert_query_succeeded();

		return new IdPage( array_map( 'intval', $ids ), $total );
	}

	/**
	 * Number of originals in a scope.
	 *
	 * @param CatalogScope $scope Scope.
	 */
	private function count_scope( CatalogScope $scope ): int {
		if ( ! $scope->is_terms() ) {
			return $this->original_ids( $scope->types[0], 1, 0, $scope->post_statuses )->total;
		}

		$total = 0;
		foreach ( $scope->types as $type ) {
			$total += $this->original_ids( $type, 1, 0 )->total;
		}

		return $total;
	}

	/**
	 * Comma-separated element type values of a scope (for FIND_IN_SET).
	 *
	 * @param CatalogScope $scope Scope.
	 */
	private static function type_list( CatalogScope $scope ): string {
		return implode( ',', array_map( static fn ( ElementType $type ): string => $type->value, $scope->types ) );
	}

	/**
	 * The scope's element type with the given WPML value.
	 *
	 * @param CatalogScope $scope Scope.
	 * @param string       $value WPML element type.
	 */
	private function scope_type( CatalogScope $scope, string $value ): ElementType {
		foreach ( $scope->types as $type ) {
			if ( $type->value === $value ) {
				return $type;
			}
		}

		return $scope->types[0];
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
	public function is_translatable( ElementType $type ): bool {
		return $type->is_term()
			? (bool) apply_filters( 'wpml_is_translated_taxonomy', false, $type->name() )
			: (bool) apply_filters( 'wpml_is_translated_post_type', false, $type->name() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Direct SQL: a per-language summary needs every original's translations; via hooks that is
	 * one round-trip per original on every call (concept §8: 5,000 products x 4 languages, < 2 s).
	 * One grouped query instead; the status rule is TranslationState::from_post_status().
	 */
	public function coverage( CatalogScope $scope, array $languages ): array {
		$counts = array_fill_keys( $languages, array_fill_keys( array( 'translated', 'unpublished', 'missing' ), 0 ) );
		if ( array() === $languages ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$total = $this->count_scope( $scope );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Read-only report query, see method doc.
		if ( $scope->is_terms() ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.language_code, NULL AS post_status, COUNT(*) AS n
					FROM %i o
					INNER JOIN %i ot ON ot.term_taxonomy_id = o.element_id
					INNER JOIN %i t ON t.trid = o.trid AND t.element_type = o.element_type AND FIND_IN_SET( t.language_code, %s )
					INNER JOIN %i tt ON tt.term_taxonomy_id = t.element_id
					WHERE FIND_IN_SET( o.element_type, %s ) AND o.source_language_code IS NULL
					GROUP BY t.language_code',
					$this->translations_table(),
					$wpdb->term_taxonomy,
					$this->translations_table(),
					implode( ',', $languages ),
					$wpdb->term_taxonomy,
					self::type_list( $scope )
				),
				ARRAY_A
			);
		} else {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.language_code, tp.post_status, COUNT(*) AS n
					FROM %i o
					INNER JOIN %i op ON op.ID = o.element_id
					INNER JOIN %i t ON t.trid = o.trid AND t.element_type = o.element_type AND FIND_IN_SET( t.language_code, %s )
					INNER JOIN %i tp ON tp.ID = t.element_id
					WHERE o.element_type = %s AND o.source_language_code IS NULL AND FIND_IN_SET( op.post_status, %s )
					GROUP BY t.language_code, tp.post_status',
					$this->translations_table(),
					$wpdb->posts,
					$this->translations_table(),
					implode( ',', $languages ),
					$wpdb->posts,
					$scope->types[0]->value,
					implode( ',', $scope->post_statuses )
				),
				ARRAY_A
			);
		}
		// phpcs:enable
		$this->assert_query_succeeded();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$language = (string) $row['language_code'];
			if ( ! isset( $counts[ $language ] ) ) {
				continue;
			}

			$state = $scope->is_terms() ? TranslationState::Translated : TranslationState::from_post_status( (string) $row['post_status'] );
			if ( TranslationState::Missing !== $state ) {
				$counts[ $language ][ $state->value ] += (int) $row['n'];
			}
		}

		$coverage = array();
		foreach ( $counts as $language => $count ) {
			$missing    = max( 0, $total - $count['translated'] - $count['unpublished'] );
			$coverage[] = new LanguageCoverage( (string) $language, $count['translated'], $count['unpublished'], $missing );
		}

		return $coverage;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Direct SQL for the same reason as coverage(): filtering "incomplete" before paginating
	 * needs every original's translations.
	 */
	public function incomplete_originals( CatalogScope $scope, array $languages, int $limit, int $offset ): CoveragePage {
		if ( array() === $languages ) {
			return new CoveragePage( array(), 0 );
		}

		$limit    = max( 1, min( 100, $limit ) );
		$offset   = max( 0, $offset );
		$wpdb     = $this->wpdb;
		$table    = $this->translations_table();
		$codes    = implode( ',', $languages );
		$required = count( $languages );
		$types    = self::type_list( $scope );
		$statuses = implode( ',', $scope->post_statuses );

		// An original is incomplete when fewer than all requested languages have a published (posts) or existing (terms) member.
		// $grouped is a literal SQL string; the placeholder sniffs cannot follow it through the variable.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Read-only report query, see method doc.
		if ( $scope->is_terms() ) {
			$grouped = 'SELECT o.element_id, o.element_type, o.trid
				FROM %i o
				INNER JOIN %i ot ON ot.term_taxonomy_id = o.element_id
				LEFT JOIN %i t ON t.trid = o.trid AND t.element_type = o.element_type AND FIND_IN_SET( t.language_code, %s )
				LEFT JOIN %i tt ON tt.term_taxonomy_id = t.element_id
				WHERE FIND_IN_SET( o.element_type, %s ) AND o.source_language_code IS NULL
				GROUP BY o.element_id, o.element_type, o.trid
				HAVING COUNT( tt.term_taxonomy_id ) < %d';
			$args    = array( $table, $wpdb->term_taxonomy, $table, $codes, $wpdb->term_taxonomy, $types, $required );
			$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( {$grouped} ) incomplete", ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $grouped is a literal above.
			$page    = $wpdb->get_results( $wpdb->prepare( "{$grouped} ORDER BY o.element_id ASC LIMIT %d OFFSET %d", ...array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $grouped is a literal above.
		} else {
			$grouped = "SELECT o.element_id, o.element_type, o.trid
				FROM %i o
				INNER JOIN %i op ON op.ID = o.element_id
				LEFT JOIN %i t ON t.trid = o.trid AND t.element_type = o.element_type AND FIND_IN_SET( t.language_code, %s )
				LEFT JOIN %i tp ON tp.ID = t.element_id AND tp.post_status = 'publish'
				WHERE o.element_type = %s AND o.source_language_code IS NULL AND FIND_IN_SET( op.post_status, %s )
				GROUP BY o.element_id, o.element_type, o.trid
				HAVING COUNT( tp.ID ) < %d";
			$args    = array( $table, $wpdb->posts, $table, $codes, $wpdb->posts, $scope->types[0]->value, $statuses, $required );
			$total   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM ( {$grouped} ) incomplete", ...$args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $grouped is a literal above.
			$page    = $wpdb->get_results( $wpdb->prepare( "{$grouped} ORDER BY o.element_id ASC LIMIT %d OFFSET %d", ...array_merge( $args, array( $limit, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $grouped is a literal above.
		}

		$this->assert_query_succeeded();

		$page = is_array( $page ) ? $page : array();
		if ( array() === $page ) {
			return new CoveragePage( array(), $total );
		}

		// Translation members of this page's groups, in one query.
		$trids   = implode( ',', array_map( static fn ( array $row ): int => (int) $row['trid'], $page ) );
		$members = $scope->is_terms()
			? $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.trid, t.language_code, NULL AS post_status
					FROM %i t INNER JOIN %i tt ON tt.term_taxonomy_id = t.element_id
					WHERE FIND_IN_SET( t.trid, %s ) AND FIND_IN_SET( t.language_code, %s ) AND FIND_IN_SET( t.element_type, %s )',
					$table,
					$wpdb->term_taxonomy,
					$trids,
					$codes,
					$types
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					'SELECT t.trid, t.language_code, tp.post_status
					FROM %i t INNER JOIN %i tp ON tp.ID = t.element_id
					WHERE FIND_IN_SET( t.trid, %s ) AND FIND_IN_SET( t.language_code, %s ) AND t.element_type = %s',
					$table,
					$wpdb->posts,
					$trids,
					$codes,
					$scope->types[0]->value
				),
				ARRAY_A
			);
		// phpcs:enable
		$this->assert_query_succeeded();

		$status_by_trid = array();
		foreach ( is_array( $members ) ? $members : array() as $member ) {
			$status_by_trid[ (int) $member['trid'] ][ (string) $member['language_code'] ] = null === $member['post_status'] ? '' : (string) $member['post_status'];
		}

		$rows = array();
		foreach ( $page as $original ) {
			$type   = $this->scope_type( $scope, (string) $original['element_type'] );
			$found  = $status_by_trid[ (int) $original['trid'] ] ?? array();
			$states = array();
			foreach ( $languages as $language ) {
				if ( ! array_key_exists( $language, $found ) ) {
					$states[ $language ] = TranslationState::Missing;
				} else {
					$states[ $language ] = $scope->is_terms() ? TranslationState::Translated : TranslationState::from_post_status( $found[ $language ] );
				}
			}

			$object_id = $this->to_object_id( (int) $original['element_id'], $type );
			if ( null !== $object_id ) {
				$rows[] = new CoverageRow( $object_id, $type, $states );
			}
		}

		return new CoveragePage( $rows, $total );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Direct SQL for the same reason as coverage(): checking every product's translations via
	 * hooks is one round-trip per original.
	 *
	 * @throws InvalidArgumentException For term scopes.
	 */
	public function translation_pairs( CatalogScope $scope, array $languages ): array {
		if ( $scope->is_terms() ) {
			throw new InvalidArgumentException( 'translation_pairs() supports post scopes only.' );
		}
		if ( array() === $languages ) {
			return array();
		}

		$wpdb  = $this->wpdb;
		$table = $this->translations_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only report query, see method doc.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT o.element_id AS original_id, t.element_id AS translation_id, t.language_code
				FROM %i o
				INNER JOIN %i op ON op.ID = o.element_id
				INNER JOIN %i t ON t.trid = o.trid AND t.element_type = o.element_type AND t.element_id <> o.element_id AND FIND_IN_SET( t.language_code, %s )
				INNER JOIN %i tp ON tp.ID = t.element_id AND tp.post_status NOT IN ('trash', 'auto-draft')
				WHERE o.element_type = %s AND o.source_language_code IS NULL AND FIND_IN_SET( op.post_status, %s )
				ORDER BY o.element_id ASC",
				$table,
				$wpdb->posts,
				$table,
				implode( ',', $languages ),
				$wpdb->posts,
				$scope->types[0]->value,
				implode( ',', $scope->post_statuses )
			),
			ARRAY_A
		);
		$this->assert_query_succeeded();

		$order = array_flip( $languages );
		$pairs = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$pairs[] = new TranslationPair( (int) $row['original_id'], (int) $row['translation_id'], (string) $row['language_code'] );
		}

		usort(
			$pairs,
			static fn ( TranslationPair $a, TranslationPair $b ): int => array( $a->original_id, $order[ $a->language ] ?? PHP_INT_MAX ) <=> array( $b->original_id, $order[ $b->language ] ?? PHP_INT_MAX )
		);

		return $pairs;
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

		// Raw read: WCML may filter price meta reads into the visitor's currency (docs/api-notes.md §11).
		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See comment above; read-only, indexed by post_id + meta_key.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE post_id = %d AND meta_key = %s LIMIT 1', $wpdb->postmeta, $original, '_regular_price_' . $currency ) );
		$this->assert_query_succeeded();

		// An empty meta_value is possible at runtime even though the stubs type get_var() as non-empty.
		$price = null === $value ? '' : (string) $value;

		return '' === $price ? null : $price;
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
