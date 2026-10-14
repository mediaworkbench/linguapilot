<?php
/**
 * WPML/WCML sync rules for product fields.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;

/**
 * Derives which product fields are synced, from WPML's custom-field translation preferences.
 *
 * A field is synced when its meta key's preference is "copy", or when WCML keeps it in sync in
 * code regardless of preferences. See docs/api-notes.md §5.4 (verified against WCML 5.6.3).
 */
final class SyncRules {

	/**
	 * WPML_COPY_CUSTOM_FIELD. "Copy once" (3) only copies on creation, so it is not a sync.
	 */
	private const PREFERENCE_COPY = 1;

	/**
	 * Fields whose sync depends on a WPML custom-field preference.
	 */
	private const META_KEYS = array(
		'sku'           => '_sku',
		'stock_status'  => '_stock_status',
		'manage_stock'  => '_manage_stock',
		'tax_class'     => '_tax_class',
		'regular_price' => '_regular_price',
		'sale_price'    => '_sale_price',
	);

	/**
	 * Fields WCML syncs in code: stock quantity (Synchronization\Component\Stock), stock status
	 * (`woocommerce_product_set_stock_status`) and catalog visibility (`product_visibility` terms).
	 */
	private const SYNCED_BY_WCML = array( 'stock_status', 'stock_quantity', 'catalog_visibility' );

	/**
	 * Builds sync settings from WPML's stored preferences.
	 *
	 * @param array<mixed> $preferences `custom_fields_translation` map: meta key => preference (0-3, possibly as string).
	 * @param bool         $wcml_active Whether WCML is running; without it, only WPML's copy preferences apply.
	 */
	public static function from_preferences( array $preferences, bool $wcml_active = true ): SyncSettings {
		$synced = array();

		foreach ( ProductField::cases() as $field ) {
			if ( $wcml_active && in_array( $field->value, self::SYNCED_BY_WCML, true ) ) {
				$synced[] = $field;
				continue;
			}

			$meta_key = self::META_KEYS[ $field->value ] ?? null;
			if ( null !== $meta_key && isset( $preferences[ $meta_key ] ) && self::PREFERENCE_COPY === (int) $preferences[ $meta_key ] ) {
				$synced[] = $field;
			}
		}

		return new SyncSettings( $synced );
	}
}
