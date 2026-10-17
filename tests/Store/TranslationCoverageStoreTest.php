<?php
/**
 * Cross-checks WpmlGateway's coverage SQL against a hook-based reference.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Store;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogScope;
use MediaWorkbench\LinguaPilot\Domain\Model\CoverageRow;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\Language;
use MediaWorkbench\LinguaPilot\Domain\Model\LanguageCoverage;
use MediaWorkbench\LinguaPilot\Domain\Model\TranslationState;
use MediaWorkbench\LinguaPilot\Gateway\WooCommerceGateway;
use MediaWorkbench\LinguaPilot\Gateway\WpmlGateway;

/**
 * The SQL fast path (coverage(), incomplete_originals()) must agree with the slow, obvious
 * computation from WPML's public hooks and get_post_status() on the real store.
 *
 * @covers \MediaWorkbench\LinguaPilot\Gateway\WpmlGateway
 */
final class TranslationCoverageStoreTest extends StoreTestCase {

	/**
	 * Gateway under test.
	 *
	 * @var WpmlGateway
	 */
	private WpmlGateway $gateway;

	/**
	 * Fresh gateway per test.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->gateway = new WpmlGateway( self::wpdb() );
	}

	/**
	 * Fast path equals reference for every scope.
	 *
	 * @dataProvider scopes
	 *
	 * @param string $name Scope name (see scope()).
	 */
	public function test_sql_fast_path_matches_hook_reference( string $name ): void {
		$scope     = $this->scope( $name );
		$languages = $this->target_languages();
		$reference = $this->reference_rows( $scope, $languages );

		// Coverage summary.
		$expected = array();
		foreach ( $languages as $language ) {
			$states                = array_map( static fn ( CoverageRow $row ): TranslationState => $row->states[ $language ], $reference );
			$count                 = static fn ( TranslationState $wanted ): int => count( array_filter( $states, static fn ( TranslationState $state ): bool => $wanted === $state ) );
			$expected[ $language ] = array(
				$count( TranslationState::Translated ),
				$count( TranslationState::Unpublished ),
				$count( TranslationState::InProgress ),
				$count( TranslationState::Missing ),
				count( array_filter( $reference, static fn ( CoverageRow $row ): bool => in_array( $language, $row->outdated, true ) ) ),
			);
		}
		$actual = array();
		foreach ( $this->gateway->coverage( $scope, $languages ) as $coverage ) {
			$actual[ $coverage->language ] = array( $coverage->translated, $coverage->unpublished, $coverage->in_progress, $coverage->missing, $coverage->outdated );
		}
		$this->assertSame( $expected, $actual, 'coverage()' );

		// Incomplete originals, all pages.
		$expected_rows = array_values(
			array_filter( $reference, static fn ( CoverageRow $row ): bool => $row->has_gap() )
		);
		$actual_rows   = array();
		$offset        = 0;
		do {
			$page        = $this->gateway->incomplete_originals( $scope, $languages, 3, $offset );
			$actual_rows = array_merge( $actual_rows, $page->rows );
			$offset     += 3;
		} while ( array() !== $page->rows );

		$this->assertSame( count( $expected_rows ), $page->total, 'incomplete total' );
		$this->assertSame( self::flatten( $expected_rows ), self::flatten( $actual_rows ), 'incomplete_originals()' );
	}

	/**
	 * Scope names.
	 *
	 * @return array<string, array{string}>
	 */
	public static function scopes(): array {
		return array(
			'published products'    => array( 'products' ),
			'products in any state' => array( 'products-any' ),
			'product categories'    => array( 'categories' ),
			'all attribute terms'   => array( 'attributes' ),
		);
	}

	public function test_titles_are_plain_text_and_edit_urls_point_to_admin(): void {
		$ids   = $this->gateway->original_ids( ElementType::product(), 5, 0 )->ids;
		$items = ( new WooCommerceGateway( self::wpdb() ) )->describe_posts( $ids );

		$this->assertSame( $ids, array_keys( $items ) );
		foreach ( $items as $item ) {
			$this->assertSame( wp_strip_all_tags( $item->title ), $item->title );
			$this->assertStringStartsWith( admin_url( 'post.php?post=' ), $item->edit_url );
		}
	}

	/**
	 * Builds a scope by name.
	 *
	 * @param string $name Scope name.
	 */
	private function scope( string $name ): CatalogScope {
		return match ( $name ) {
			'products'     => new CatalogScope( array( ElementType::product() ) ),
			'products-any' => new CatalogScope( array( ElementType::product() ), CatalogScope::ANY_POST_STATUS ),
			'categories'   => new CatalogScope( array( ElementType::product_category() ) ),
			default        => new CatalogScope(
				array_map( static fn ( string $taxonomy ): ElementType => ElementType::taxonomy( $taxonomy ), ( new WooCommerceGateway( self::wpdb() ) )->attribute_taxonomies() )
			),
		};
	}

	/**
	 * Active non-default languages.
	 *
	 * @return list<string>
	 */
	private function target_languages(): array {
		return array_values(
			array_map(
				static fn ( Language $language ): string => $language->code,
				array_filter( $this->gateway->active_languages(), static fn ( Language $language ): bool => ! $language->is_default )
			)
		);
	}

	/**
	 * Reference: every original's states, computed one by one via public hooks.
	 *
	 * @param CatalogScope $scope     Scope.
	 * @param list<string> $languages Languages.
	 * @return list<CoverageRow>
	 */
	private function reference_rows( CatalogScope $scope, array $languages ): array {
		// WPML registers `wpml_tm_translation_status` lazily; outside wp-admin nothing loads it (docs/api-notes.md §14).
		if ( function_exists( 'wpml_tm_load_element_translations' ) ) {
			wpml_tm_load_element_translations();
		}

		$rows = array();
		foreach ( $scope->types as $type ) {
			$offset = 0;
			do {
				$page = $this->gateway->original_ids( $type, 100, $offset, $scope->is_terms() ? array() : $scope->post_statuses );
				foreach ( $page->ids as $id ) {
					$group    = $this->gateway->translations( $id, $type );
					$trid     = (int) apply_filters( 'wpml_element_trid', null, $this->element_id( $id, $type ), $type->value ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML reference.
					$states   = array();
					$outdated = array();
					foreach ( $languages as $language ) {
						$member = $group[ $language ] ?? null;
						$job    = (int) apply_filters(
							'wpml_tm_translation_status',
							null,
							array(
								'trid'          => $trid,
								'language_code' => $language,
							)
						); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML reference.
						$status = null;
						if ( null !== $member && ! $type->is_term() ) {
							$found  = get_post_status( $member );
							$status = false === $found ? null : $found;
						}
						$exists              = null !== $member && ( $type->is_term() || null !== $status );
						$states[ $language ] = TranslationState::classify( $type->is_term(), $exists, $status, 0 === $job ? null : $job );
						if ( $exists && in_array( $states[ $language ], array( TranslationState::Translated, TranslationState::Unpublished ), true ) && $this->needs_update( $trid, $language ) ) {
							$outdated[] = $language;
						}
					}
					$rows[] = new CoverageRow( $id, $type, $states, $outdated );
				}
				$offset += 100;
			} while ( array() !== $page->ids );
		}

		usort( $rows, static fn ( CoverageRow $a, CoverageRow $b ): int => $a->original_id <=> $b->original_id );

		return $rows;
	}

	/**
	 * Comparable representation of rows.
	 *
	 * @param list<CoverageRow> $rows Rows.
	 * @return list<array{int, string, array<string, string>, list<string>}>
	 */
	private static function flatten( array $rows ): array {
		return array_map(
			static fn ( CoverageRow $row ): array => array(
				$row->original_id,
				$row->type->value,
				array_map( static fn ( TranslationState $state ): string => $state->value, $row->states ),
				$row->outdated,
			),
			$rows
		);
	}

	/**
	 * WPML element ID of an object (term_taxonomy_id for terms).
	 *
	 * @param int         $id   Object ID.
	 * @param ElementType $type Element type.
	 */
	private function element_id( int $id, ElementType $type ): int {
		if ( ! $type->is_term() ) {
			return $id;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reference lookup, bypasses WPML's get_term() ID adjustment.
		return (int) self::wpdb()->get_var( self::wpdb()->prepare( 'SELECT term_taxonomy_id FROM %i WHERE term_id = %d AND taxonomy = %s', self::wpdb()->term_taxonomy, $id, $type->name() ) );
	}

	/**
	 * Whether WPML marks a translation as needing an update (no public hook exists; read from the job table).
	 *
	 * @param int    $trid     Translation group.
	 * @param string $language Language.
	 */
	private function needs_update( int $trid, string $language ): bool {
		$wpdb = self::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reference lookup.
		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT s.needs_update FROM %i t JOIN %i s ON s.translation_id = t.translation_id WHERE t.trid = %d AND t.language_code = %s LIMIT 1',
				$wpdb->prefix . 'icl_translations',
				$wpdb->prefix . 'icl_translation_status',
				$trid,
				$language
			)
		);
	}
}
