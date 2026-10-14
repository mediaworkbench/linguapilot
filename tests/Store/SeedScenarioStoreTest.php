<?php
/**
 * Verifies every ability against the seeded demo cases (tests/Fixtures/seed-demo.php).
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Store;

use DateTimeImmutable;
use DateTimeZone;
use MediaWorkbench\MultilingualStoreAbilities\Domain\SalesRanking;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooCommerceGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway;

/**
 * End-to-end: real WPML/WCML data, registered abilities, expected issues. Skipped unless the store is seeded.
 *
 * @coversNothing
 */
final class SeedScenarioStoreTest extends StoreTestCase {

	/**
	 * Seed IDs by key (originals, by SKU or title).
	 *
	 * @var array<string, int>
	 */
	private array $ids = array();

	/**
	 * Finds the seed objects and runs as an administrator.
	 */
	protected function set_up(): void {
		parent::set_up();

		$wpdb = self::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test lookup.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, sku.meta_value AS sku FROM %i p
				INNER JOIN %i seed ON seed.post_id = p.ID AND seed.meta_key = '_mlstore_seed'
				LEFT JOIN %i sku ON sku.post_id = p.ID AND sku.meta_key = '_sku'
				INNER JOIN %i t ON t.element_id = p.ID AND t.element_type = 'post_product' AND t.source_language_code IS NULL
				WHERE p.post_type = 'product'",
				$wpdb->posts,
				$wpdb->postmeta,
				$wpdb->postmeta,
				$wpdb->prefix . 'icl_translations'
			),
			ARRAY_A
		);
		foreach ( $rows ?? array() as $row ) {
			$key               = '' !== (string) $row['sku'] ? (string) $row['sku'] : (string) $row['post_title'];
			$this->ids[ $key ] = (int) $row['ID'];
		}

		if ( ! isset( $this->ids['SEED-CAP'] ) ) {
			$this->markTestSkipped( 'Store is not seeded (run tests/Fixtures/seed-demo.php).' );
		}

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
	}

	public function test_translation_completeness(): void {
		$products = $this->all_pages( 'mlstore/translation-completeness', array(), 'objects' );

		$this->assertArrayNotHasKey( $this->ids['SEED-TEE'], $products, 'complete product is not listed' );
		$this->assertSame( array( 'fr' ), $products[ $this->ids['SEED-MUG'] ]['missing_languages'] );
		$this->assertSame( array( 'de' ), $products[ $this->ids['SEED-CAP'] ]['unpublished_languages'] );
		$this->assertSame( array(), $products[ $this->ids['SEED-CAP'] ]['missing_languages'] );

		$terms = $this->all_pages(
			'mlstore/translation-completeness',
			array(
				'object_type' => 'attribute_term',
				'taxonomy'    => 'pa_color',
			),
			'objects'
		);
		$blue  = $this->term_id( '[Seed] Blue' );
		$this->assertSame( array( 'fr' ), $terms[ $blue ]['missing_languages'] );
		$this->assertArrayNotHasKey( $this->term_id( '[Seed] Red' ), $terms, 'Red is translated into DE and FR' );

		$categories = $this->all_pages( 'mlstore/translation-completeness', array( 'object_type' => 'product_cat' ), 'objects' );
		$this->assertSame( array( 'fr' ), $categories[ $this->term_id( '[Seed] Category' ) ]['missing_languages'] );
	}

	public function test_cross_language_consistency(): void {
		$result = wp_get_ability( 'mlstore/cross-language-consistency' )?->execute( array( 'per_page' => 100 ) );
		$this->assertIsArray( $result );

		$found = array();
		foreach ( $result['translations'] as $row ) {
			foreach ( $row['differences'] as $difference ) {
				$found[ $row['product_id'] ][ $row['language'] ][] = array( $difference['field'], $difference['original'], $difference['translation'], $difference['severity'] );
			}
		}

		$this->assertSame( array( array( 'sku', 'SEED-SCARF', 'SEED-SCARF-DE', 'sync_error' ) ), $found[ $this->ids['SEED-SCARF'] ]['de'] );
		$this->assertSame( array( array( 'stock_quantity', '12', '3', 'sync_error' ) ), $found[ $this->ids['SEED-SCARF'] ]['fr'] );
		$this->assertSame( array( array( 'post_status', 'publish', 'draft', 'deviation' ) ), $found[ $this->ids['SEED-CAP'] ]['de'] );
		$this->assertArrayNotHasKey( $this->ids['SEED-TEE'], $found );
		$this->assertArrayNotHasKey( $this->ids['SEED-LAMP'], $found, 'Lamp FR price differs, but FR uses CHF with a manual price' );
		$this->assertGreaterThanOrEqual( 4, $result['summary']['price_checks_skipped'] );
	}

	public function test_untranslated_variations(): void {
		$result = wp_get_ability( 'mlstore/untranslated-variations' )?->execute( array( 'product_id' => $this->ids['[Seed] Variable Hoodie'] ) );
		$this->assertIsArray( $result );

		$issues = array();
		foreach ( $result['products'] as $row ) {
			$issues[ $row['language'] ] = array_column( $row['issues'], 'type' );
		}

		$this->assertSame( array( 'local_attribute_identical' ), $issues['de'] );
		$this->assertSame(
			array( 'variation_missing', 'attribute_term_untranslated', 'local_attribute_options_mismatch', 'local_attribute_label_untranslated', 'variation_attribute_value_invalid' ),
			$issues['fr']
		);
	}

	public function test_currency_price_gaps(): void {
		$items = array();
		foreach ( $this->all_pages( 'mlstore/currency-price-gaps', array(), 'items', 'variation_id' ) as $key => $item ) {
			$items[ $key ] = $item['missing_currencies'];
		}

		$this->assertSame( array( 'USD' ), $items[ 'p' . $this->ids['SEED-LAMP'] ] );
		$this->assertArrayNotHasKey( 'p' . $this->ids['SEED-CHAIR'], $items, 'Chair has CHF and USD' );
		$variations = array_filter( $items, static fn ( int|string $key ): bool => str_starts_with( (string) $key, 'v' ), ARRAY_FILTER_USE_KEY );
		$this->assertContains( array( 'CHF' ), $variations, 'Blue hoodie variation lacks CHF' );
	}

	public function test_work_queue_sums_sales_of_translations_under_the_original(): void {
		$queue = ( new SalesRanking( new WpmlGateway( self::wpdb() ), new WooCommerceGateway( self::wpdb() ) ) )->queue(
			array( 'de', 'fr' ),
			7,
			'units',
			10000,
			new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) )
		);

		$by_id = array_column( $queue['products'], null, 'product_id' );
		$this->assertSame( 6, $by_id[ $this->ids['SEED-CAP'] ]['units_sold'], '5 via the FR translation + 1 via the original' );
		$this->assertSame( array( 'de' ), $by_id[ $this->ids['SEED-CAP'] ]['unpublished_languages'] );
		$this->assertSame( 3, $by_id[ $this->ids['SEED-MUG'] ]['units_sold'], 'all 3 via the DE translation' );
		$this->assertSame( array( 'fr' ), $by_id[ $this->ids['SEED-MUG'] ]['missing_languages'] );
	}

	/**
	 * Collects all pages of a list ability, keyed by ID (or by "p"/"v" + ID when a variation key is given).
	 *
	 * @param string               $name          Ability name.
	 * @param array<string, mixed> $input         Input.
	 * @param string               $key           Collection key.
	 * @param string|null          $variation_key Variation ID field, if items can be variations.
	 * @return array<int|string, array<string, mixed>>
	 */
	private function all_pages( string $name, array $input, string $key, ?string $variation_key = null ): array {
		$items = array();
		$page  = 1;
		do {
			$result = wp_get_ability( $name )?->execute(
				$input + array(
					'page'     => $page,
					'per_page' => 100,
				)
			);
			$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : $name );
			foreach ( $result[ $key ] as $item ) {
				$id           = null === $variation_key ? $item['id'] ?? $item['product_id'] : ( null === $item[ $variation_key ] ? 'p' . $item['product_id'] : 'v' . $item[ $variation_key ] );
				$items[ $id ] = $item;
			}
			++$page;
		} while ( $page <= $result['total_pages'] );

		return $items;
	}

	/**
	 * Term ID of a seed term by name.
	 *
	 * @param string $name Term name.
	 */
	private function term_id( string $name ): int {
		$wpdb = self::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Test lookup.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT term_id FROM %i WHERE name = %s', $wpdb->terms, $name ) );
	}
}
