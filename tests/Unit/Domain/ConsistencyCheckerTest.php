<?php
/**
 * Tests for cross-language consistency.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Domain;

use MediaWorkbench\MultilingualStoreAbilities\Domain\ConsistencyChecker;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Currency;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;
use MediaWorkbench\MultilingualStoreAbilities\Support\Pagination;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * Store (DE default, EN, FR), SKU and stock synced, prices not:
 * - 1 Hoodie: EN SKU differs (sync_error); FR price differs (deviation)
 * - 2 Beanie: EN and FR identical, FR draft → only post_status deviation for FR
 * - 3 Cap:    no translations → nothing to compare
 * - 4 Scarf:  FR trashed → not compared; EN identical
 * - 6 Hoodie variation (parent 1): EN stock quantity differs (sync_error), FR disabled (post_status deviation)
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\ConsistencyChecker
 */
final class ConsistencyCheckerTest extends TestCase {

	private const BASE = array(
		'sku'                => 'X',
		'stock_status'       => 'instock',
		'stock_quantity'     => '5',
		'manage_stock'       => 'yes',
		'tax_class'          => '',
		'catalog_visibility' => 'visible',
		'post_status'        => 'publish',
		'regular_price'      => '20',
		'sale_price'         => null,
	);

	/**
	 * Fake store.
	 *
	 * @var FakeMultilingualGateway
	 */
	private FakeMultilingualGateway $gateway;

	/**
	 * Fake catalog.
	 *
	 * @var FakeWooGateway
	 */
	private FakeWooGateway $woo;

	/**
	 * Builds the store described in the class doc.
	 */
	protected function set_up(): void {
		parent::set_up();

		$product       = ElementType::product();
		$this->gateway = ( new FakeMultilingualGateway() )
			->add_group(
				$product,
				array(
					'de' => 1,
					'en' => 11,
					'fr' => 21,
				)
			)
			->add_group(
				$product,
				array(
					'de' => 2,
					'en' => 12,
					'fr' => 22,
				),
				null,
				array( 'fr' => 'draft' )
			)
			->add_group( $product, array( 'de' => 3 ) )
			->add_group(
				$product,
				array(
					'de' => 4,
					'en' => 14,
					'fr' => 24,
				),
				null,
				array( 'fr' => 'trash' )
			)
			->add_group(
				ElementType::variation(),
				array(
					'de' => 6,
					'en' => 16,
					'fr' => 26,
				),
				null,
				array( 'fr' => 'private' )
			)
			->set_sync_settings( new SyncSettings( array( ProductField::Sku, ProductField::StockStatus, ProductField::StockQuantity, ProductField::ManageStock ) ) );

		$this->woo = ( new FakeWooGateway() )
			->add_product( 1, 'Hoodie', 'HD-1' )
			->add_product( 2, 'Beanie' )
			->add_product( 4, 'Scarf' )
			->add_product( 11, 'Hoodie EN' )
			->add_product( 21, 'Hoodie FR' )
			->add_product( 22, 'Beanie FR' )
			->add_variation( 6, 1, 'Hoodie - Red', 'HD-1-R' )
			->add_variation( 16, 11, 'Hoodie EN - Red' )
			->add_variation( 26, 21, 'Hoodie FR - Red' );

		foreach ( array( 1, 2, 3, 4, 12, 14, 6 ) as $id ) {
			$this->woo->set_fields( $id, self::BASE );
		}
		$this->woo
			->set_fields( 16, array( 'stock_quantity' => '3' ) + self::BASE )
			->set_fields(
				26,
				array(
					'post_status'        => 'private',
					'catalog_visibility' => 'hidden',
				) + self::BASE
			);
		$this->woo
			->set_fields( 11, array( 'sku' => 'HD-1-EN' ) + self::BASE )
			->set_fields( 21, array( 'regular_price' => '25.00' ) + self::BASE )
			->set_fields( 22, array( 'post_status' => 'draft' ) + self::BASE )
			->set_fields( 24, array( 'sku' => 'TRASHED' ) + self::BASE );
	}

	public function test_reports_differences_with_sync_aware_severity(): void {
		$result = $this->check();

		$this->assertSame(
			array(
				array( 1, null, 'en', 11, array( array( 'sku', 'X', 'HD-1-EN', 'sync_error' ) ) ),
				array( 1, null, 'fr', 21, array( array( 'regular_price', '20', '25.00', 'deviation' ) ) ),
				array( 1, 6, 'en', 16, array( array( 'stock_quantity', '5', '3', 'sync_error' ) ) ),
				array( 1, 6, 'fr', 26, array( array( 'post_status', 'publish', 'private', 'deviation' ) ) ),
				array( 2, null, 'fr', 22, array( array( 'post_status', 'publish', 'draft', 'deviation' ) ) ),
			),
			self::compact( $result['items'] )
		);
		$this->assertSame( 'Hoodie - Red', $result['items'][2]['title'], 'variation rows carry the variation title' );
		$this->assertSame( 'HD-1-R', $result['items'][2]['sku'] );
		$this->assertSame( 'Hoodie', $result['items'][0]['title'] );
		$this->assertSame( 'HD-1', $result['items'][0]['sku'] );
		$this->assertSame( 'https://shop.test/wp-admin/post.php?post=11&action=edit', $result['items'][0]['edit_url'], 'edit link of the translation to fix' );
	}

	public function test_summary_counts_everything_checked(): void {
		$summary = $this->check()['summary'];

		$this->assertSame(
			array(
				'products_checked'     => 3,
				'variations_checked'   => 1,
				'translations_checked' => 7,
				'sync_errors'          => 2,
				'deviations'           => 3,
				'price_checks_skipped' => 0,
			),
			$summary
		);
	}

	public function test_field_and_language_subsets(): void {
		$result = $this->check( array( ProductField::Sku ), array( 'fr' ) );

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 3, $result['summary']['translations_checked'] );
	}

	public function test_variations_can_be_excluded(): void {
		$result = $this->check( null, null, null, false );

		$this->assertSame( array( null ), array_values( array_unique( array_column( $result['items'], 'variation_id' ) ) ) );
		$this->assertSame( 0, $result['summary']['variations_checked'] );
		$this->assertSame( 5, $result['summary']['translations_checked'] );
	}

	public function test_catalog_visibility_is_not_compared_for_variations(): void {
		$result = $this->check( array( ProductField::CatalogVisibility ), array( 'fr' ) );

		$this->assertSame( array(), $result['items'], 'variation 26 has a different visibility value, but variations have no catalog visibility' );
	}

	public function test_variation_prices_are_skipped_when_the_variation_has_manual_prices(): void {
		$this->woo->set_fields( 26, array( 'regular_price' => '99' ) + self::BASE );
		$this->gateway
			->set_currencies( new CurrencySettings( true, 'EUR', array( new Currency( 'EUR', array( 'de', 'en' ) ), new Currency( 'CHF', array( 'fr' ) ) ) ) )
			->set_manual_prices( 6, array( 'CHF' => '30' ) );

		$result = $this->check( array( ProductField::RegularPrice ), array( 'fr' ) );

		$this->assertSame( array( 21 ), array_column( $result['items'], 'translation_id' ) );
		$this->assertSame( 1, $result['summary']['price_checks_skipped'] );
	}

	public function test_prices_are_skipped_only_for_languages_whose_currency_has_manual_prices(): void {
		$this->gateway
			->set_currencies( new CurrencySettings( true, 'EUR', array( new Currency( 'EUR', array( 'de', 'en' ) ), new Currency( 'CHF', array( 'fr' ) ) ) ) )
			->set_manual_prices( 1, array( 'CHF' => '30' ) );

		$result = $this->check();

		$this->assertNotContains( array( 1, 'fr', 21 ), array_map( static fn ( array $item ): array => array( $item['product_id'], $item['language'], $item['translation_id'] ), $result['items'] ) );
		$this->assertSame( 1, $result['summary']['price_checks_skipped'] );
	}

	public function test_manual_prices_flag_without_a_price_in_that_currency_does_not_skip(): void {
		$this->gateway
			->set_currencies( new CurrencySettings( true, 'EUR', array( new Currency( 'EUR', array( 'de', 'en' ) ), new Currency( 'CHF', array( 'fr' ) ) ) ) )
			->set_manual_prices( 1, array( 'CHF' => null ) );

		$this->assertSame( 0, $this->check()['summary']['price_checks_skipped'] );
	}

	public function test_stock_quantity_is_ignored_when_stock_is_not_managed(): void {
		$this->woo
			->set_fields( 1, array( 'manage_stock' => 'no' ) + self::BASE )
			->set_fields(
				11,
				array(
					'manage_stock'   => 'no',
					'stock_quantity' => '99',
				) + self::BASE
			);

		$result = $this->check( array( ProductField::StockQuantity ), array( 'en' ), null, false );

		$this->assertSame( array(), $result['items'] );
	}

	public function test_pagination_over_items_with_differences(): void {
		$page = $this->check(
			null,
			null,
			Pagination::from_input(
				array(
					'page'     => 2,
					'per_page' => 2,
				)
			)
		);

		$this->assertSame( 5, $page['total_items'] );
		$this->assertSame( array( 16, 26 ), array_column( $page['items'], 'translation_id' ) );
	}

	/**
	 * Runs the check.
	 *
	 * @param list<ProductField>|null $fields     Fields; default all.
	 * @param list<string>|null       $languages  Languages; default EN, FR.
	 * @param Pagination|null         $pagination Pagination; default first page of 50.
	 * @param bool                    $variations Whether to include variations.
	 * @return array<string, mixed>
	 */
	private function check( ?array $fields = null, ?array $languages = null, ?Pagination $pagination = null, bool $variations = true ): array {
		return ( new ConsistencyChecker( $this->gateway, $this->woo ) )->check(
			$fields ?? ProductField::cases(),
			$languages ?? array( 'en', 'fr' ),
			$pagination ?? Pagination::from_input( array() ),
			$variations
		);
	}

	/**
	 * Compact form of items: [product_id, variation_id, language, translation_id, [[field, original, translation, severity]]].
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return list<array{mixed, mixed, mixed, mixed, array<array<mixed>>}>
	 */
	private static function compact( array $items ): array {
		return array_values(
			array_map(
				static fn ( array $item ): array => array(
					$item['product_id'],
					$item['variation_id'],
					$item['language'],
					$item['translation_id'],
					array_map( static fn ( array $d ): array => array( $d['field'], $d['original'], $d['translation'], $d['severity'] ), $item['differences'] ),
				),
				$items
			)
		);
	}
}
