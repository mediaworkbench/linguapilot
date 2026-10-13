<?php
/**
 * Tests for deriving synced product fields from WPML/WCML configuration.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Gateway;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml\SyncRules;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * Synced = WPML "copy" preference, or synced by WCML code regardless of preferences.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml\SyncRules
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings
 */
final class SyncRulesTest extends TestCase {

	/**
	 * WCML's shipped defaults (wpml-config.xml) as WPML stores them.
	 */
	private const WCML_DEFAULTS = array(
		'_sku'           => 1,
		'_stock'         => 0,
		'_stock_status'  => 1,
		'_manage_stock'  => 1,
		'_tax_class'     => 1,
		'_regular_price' => 1,
		'_sale_price'    => 1,
		'_price'         => 1,
	);

	public function test_wcml_defaults_sync_everything_except_post_status(): void {
		$settings = SyncRules::from_preferences( self::WCML_DEFAULTS );

		foreach ( ProductField::cases() as $field ) {
			$this->assertSame( ProductField::PostStatus !== $field, $settings->is_synced( $field ), $field->value );
		}
	}

	public function test_translate_ignore_and_copy_once_are_not_synced(): void {
		$settings = SyncRules::from_preferences(
			array(
				'_sku'           => 2, // Translate.
				'_tax_class'     => 0, // Ignore.
				'_regular_price' => 3, // Copy once.
			)
		);

		$this->assertFalse( $settings->is_synced( ProductField::Sku ) );
		$this->assertFalse( $settings->is_synced( ProductField::TaxClass ) );
		$this->assertFalse( $settings->is_synced( ProductField::RegularPrice ) );
	}

	public function test_missing_preference_is_not_synced(): void {
		$this->assertFalse( SyncRules::from_preferences( array() )->is_synced( ProductField::Sku ) );
	}

	public function test_preferences_stored_as_strings_are_understood(): void {
		$this->assertTrue( SyncRules::from_preferences( array( '_sku' => '1' ) )->is_synced( ProductField::Sku ) );
	}

	public function test_fields_synced_by_wcml_code_are_synced_even_when_preference_is_ignore(): void {
		$settings = SyncRules::from_preferences(
			array(
				'_stock'        => 0,
				'_stock_status' => 0,
			)
		);

		$this->assertTrue( $settings->is_synced( ProductField::StockQuantity ) );
		$this->assertTrue( $settings->is_synced( ProductField::StockStatus ) );
		$this->assertTrue( $settings->is_synced( ProductField::CatalogVisibility ) );
	}

	public function test_synced_fields_are_listed_in_enum_order(): void {
		$settings = SyncRules::from_preferences( array( '_tax_class' => 1 ) );

		$this->assertSame(
			array( ProductField::StockStatus, ProductField::StockQuantity, ProductField::TaxClass, ProductField::CatalogVisibility ),
			$settings->synced_fields()
		);
	}
}
