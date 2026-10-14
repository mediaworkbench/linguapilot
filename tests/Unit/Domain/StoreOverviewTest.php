<?php
/**
 * Tests for the store overview.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Domain;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Currency;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\StoreOverview;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * The orientation data an agent reads first.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\StoreOverview
 */
final class StoreOverviewTest extends TestCase {

	public function test_languages_default_first_with_names(): void {
		$overview = ( new StoreOverview( new FakeMultilingualGateway() ) )->build( true );

		$this->assertSame( 'de', $overview['default_language'] );
		$this->assertSame(
			array(
				'code'         => 'de',
				'native_name'  => 'Deutsch',
				'english_name' => 'German',
				'is_default'   => true,
			),
			$overview['languages'][0]
		);
		$this->assertSame( array( 'de', 'en', 'fr' ), array_column( $overview['languages'], 'code' ) );
	}

	public function test_catalog_size_counts_originals_only_and_ignores_trash(): void {
		$gateway = ( new FakeMultilingualGateway() )
			->add_group(
				ElementType::product(),
				array(
					'de' => 1,
					'en' => 2,
					'fr' => 3,
				)
			)
			->add_group( ElementType::product(), array( 'de' => 4 ), null, array( 'de' => 'draft' ) )
			->add_group( ElementType::product(), array( 'de' => 5 ), null, array( 'de' => 'trash' ) )
			->add_group(
				ElementType::variation(),
				array(
					'de' => 10,
					'en' => 11,
				)
			)
			->add_group(
				ElementType::product_category(),
				array(
					'de' => 20,
					'fr' => 21,
				)
			)
			->add_group( ElementType::product_category(), array( 'en' => 22 ), 'en' );

		$this->assertSame(
			array(
				'products'           => 2,
				'variations'         => 1,
				'product_categories' => 2,
				'product_tags'       => 0,
			),
			( new StoreOverview( $gateway ) )->build( true )['catalog_size']
		);
	}

	public function test_enabled_multi_currency_lists_every_currency_with_its_languages(): void {
		$gateway = ( new FakeMultilingualGateway() )->set_currencies(
			new CurrencySettings( true, 'EUR', array( new Currency( 'EUR', array( 'de', 'en' ) ), new Currency( 'CHF', array( 'de', 'fr' ) ) ) )
		);

		$overview = ( new StoreOverview( $gateway ) )->build( true );

		$this->assertTrue( $overview['multi_currency_enabled'] );
		$this->assertSame( 'EUR', $overview['default_currency'] );
		$this->assertSame(
			array(
				array(
					'code'       => 'EUR',
					'is_default' => true,
					'languages'  => array( 'de', 'en' ),
				),
				array(
					'code'       => 'CHF',
					'is_default' => false,
					'languages'  => array( 'de', 'fr' ),
				),
			),
			$overview['currencies']
		);
	}

	public function test_currency_languages_follow_site_language_order_and_skip_inactive_languages(): void {
		$gateway = ( new FakeMultilingualGateway() )->set_currencies(
			new CurrencySettings( true, 'EUR', array( new Currency( 'EUR', array( 'fr', 'it', 'de' ) ) ) )
		);

		$this->assertSame( array( 'de', 'fr' ), ( new StoreOverview( $gateway ) )->build( true )['currencies'][0]['languages'] );
	}

	public function test_without_multi_currency_the_base_currency_applies_to_every_language(): void {
		$gateway = ( new FakeMultilingualGateway() )->set_currencies(
			new CurrencySettings( false, 'EUR', array( new Currency( 'EUR', array() ) ) )
		);

		$overview = ( new StoreOverview( $gateway ) )->build( false );

		$this->assertFalse( $overview['wcml_active'] );
		$this->assertFalse( $overview['multi_currency_enabled'] );
		$this->assertSame(
			array(
				array(
					'code'       => 'EUR',
					'is_default' => true,
					'languages'  => array( 'de', 'en', 'fr' ),
				),
			),
			$overview['currencies']
		);
	}

	public function test_sync_settings_split_all_fields_into_synced_and_unsynced(): void {
		$gateway = ( new FakeMultilingualGateway() )->set_sync_settings( new SyncSettings( array( ProductField::Sku, ProductField::StockQuantity ) ) );

		$sync = ( new StoreOverview( $gateway ) )->build( true )['sync_settings'];

		$this->assertSame( array( 'sku', 'stock_quantity' ), $sync['synced_fields'] );
		$this->assertSame( array( 'stock_status', 'manage_stock', 'tax_class', 'catalog_visibility', 'post_status', 'regular_price', 'sale_price' ), $sync['unsynced_fields'] );
	}
}
