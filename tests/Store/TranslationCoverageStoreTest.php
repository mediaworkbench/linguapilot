<?php
/**
 * Cross-checks WpmlGateway's coverage SQL against a hook-based reference.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Store;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoverageRow;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\LanguageCoverage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooCommerceGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway;

/**
 * The SQL fast path (coverage(), incomplete_originals()) must agree with the slow, obvious
 * computation from WPML's public hooks and get_post_status() on the real store.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway
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
			$expected[ $language ] = array(
				count( array_filter( $states, static fn ( TranslationState $state ): bool => TranslationState::Translated === $state ) ),
				count( array_filter( $states, static fn ( TranslationState $state ): bool => TranslationState::Unpublished === $state ) ),
				count( array_filter( $states, static fn ( TranslationState $state ): bool => TranslationState::Missing === $state ) ),
			);
		}
		$actual = array();
		foreach ( $this->gateway->coverage( $scope, $languages ) as $coverage ) {
			$actual[ $coverage->language ] = array( $coverage->translated, $coverage->unpublished, $coverage->missing );
		}
		$this->assertSame( $expected, $actual, 'coverage()' );

		// Incomplete originals, all pages.
		$expected_rows = array_values(
			array_filter( $reference, static fn ( CoverageRow $row ): bool => count( $row->languages_in( TranslationState::Translated ) ) < count( $languages ) )
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
		$rows = array();
		foreach ( $scope->types as $type ) {
			$offset = 0;
			do {
				$page = $this->gateway->original_ids( $type, 100, $offset, $scope->is_terms() ? array() : $scope->post_statuses );
				foreach ( $page->ids as $id ) {
					$group  = $this->gateway->translations( $id, $type );
					$states = array();
					foreach ( $languages as $language ) {
						$member = $group[ $language ] ?? null;
						if ( $type->is_term() ) {
							$states[ $language ] = null === $member ? TranslationState::Missing : TranslationState::Translated;
						} else {
							$status              = null === $member ? false : get_post_status( $member );
							$states[ $language ] = TranslationState::from_post_status( false === $status ? null : $status );
						}
					}
					$rows[] = new CoverageRow( $id, $type, $states );
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
	 * @return list<array{int, string, array<string, string>}>
	 */
	private static function flatten( array $rows ): array {
		return array_map(
			static fn ( CoverageRow $row ): array => array(
				$row->original_id,
				$row->type->value,
				array_map( static fn ( TranslationState $state ): string => $state->value, $row->states ),
			),
			$rows
		);
	}
}
