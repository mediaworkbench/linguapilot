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
			->set_sync_settings( new SyncSettings( array( ProductField::Sku, ProductField::StockStatus, ProductField::StockQuantity, ProductField::ManageStock ) ) );

		$this->woo = ( new FakeWooGateway() )
			->add_product( 1, 'Hoodie', 'HD-1' )
			->add_product( 2, 'Beanie' )
			->add_product( 4, 'Scarf' )
			->add_product( 11, 'Hoodie EN' )
			->add_product( 21, 'Hoodie FR' )
			->add_product( 22, 'Beanie FR' );

		foreach ( array( 1, 2, 3, 4, 12, 14 ) as $id ) {
			$this->woo->set_fields( $id, self::BASE );
		}
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
				array( 1, 'en', 11, array( array( 'sku', 'X', 'HD-1-EN', 'sync_error' ) ) ),
				array( 1, 'fr', 21, array( array( 'regular_price', '20', '25.00', 'deviation' ) ) ),
				array( 2, 'fr', 22, array( array( 'post_status', 'publish', 'draft', 'deviation' ) ) ),
			),
			self::compact( $result['items'] )
		);
		$this->assertSame( 'Hoodie', $result['items'][0]['title'] );
		$this->assertSame( 'HD-1', $result['items'][0]['sku'] );
		$this->assertSame( 'https://shop.test/wp-admin/post.php?post=11&action=edit', $result['items'][0]['edit_url'], 'edit link of the translation to fix' );
	}

	public function test_summary_counts_everything_checked(): void {
		$summary = $this->check()['summary'];

		$this->assertSame(
			array(
				'products_checked'     => 3,
				'translations_checked' => 5,
				'sync_errors'          => 1,
				'deviations'           => 2,
				'price_checks_skipped' => 0,
			),
			$summary
		);
	}

	public function test_field_and_language_subsets(): void {
		$result = $this->check( array( ProductField::Sku ), array( 'fr' ) );

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 2, $result['summary']['translations_checked'] );
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

		$result = $this->check( array( ProductField::StockQuantity ), array( 'en' ) );

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

		$this->assertSame( 3, $page['total_items'] );
		$this->assertSame( array( array( 2, 'fr', 22, array( array( 'post_status', 'publish', 'draft', 'deviation' ) ) ) ), self::compact( $page['items'] ) );
	}

	/**
	 * Runs the check.
	 *
	 * @param list<ProductField>|null $fields     Fields; default all.
	 * @param list<string>|null       $languages  Languages; default EN, FR.
	 * @param Pagination|null         $pagination Pagination; default first page of 50.
	 * @return array<string, mixed>
	 */
	private function check( ?array $fields = null, ?array $languages = null, ?Pagination $pagination = null ): array {
		return ( new ConsistencyChecker( $this->gateway, $this->woo ) )->check(
			$fields ?? ProductField::cases(),
			$languages ?? array( 'en', 'fr' ),
			$pagination ?? Pagination::from_input( array() )
		);
	}

	/**
	 * Compact form of items for assertions: [product_id, language, translation_id, [[field, original, translation, severity]]].
	 *
	 * @param array<int, array<string, mixed>> $items Items.
	 * @return list<array{mixed, mixed, mixed, array<array<mixed>>}>
	 */
	private static function compact( array $items ): array {
		return array_values(
			array_map(
				static fn ( array $item ): array => array(
					$item['product_id'],
					$item['language'],
					$item['translation_id'],
					array_map( static fn ( array $d ): array => array( $d['field'], $d['original'], $d['translation'], $d['severity'] ), $item['differences'] ),
				),
				$items
			)
		);
	}
}
