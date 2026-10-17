<?php
/**
 * A product attribute as stored on a product.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * One entry of a product's `_product_attributes` meta.
 */
final class ProductAttribute {

	/**
	 * Creates an attribute.
	 *
	 * @param string       $key          Array key = sanitized name (variation meta key is `attribute_{key}`).
	 * @param string       $name         Name as entered (taxonomy slug for global attributes).
	 * @param bool         $is_taxonomy  Whether it is a global attribute (`pa_*`).
	 * @param bool         $is_variation Whether it is used for variations.
	 * @param list<string> $options      Options of a local attribute (split like wc_get_text_attributes()); empty for global ones.
	 */
	public function __construct(
		public readonly string $key,
		public readonly string $name,
		public readonly bool $is_taxonomy,
		public readonly bool $is_variation,
		public readonly array $options = array(),
	) {}

	/**
	 * Splits a stored local attribute value like WooCommerce's wc_get_text_attributes() (WC 11.2).
	 *
	 * @param string $value Stored value, e.g. "S | M | L".
	 * @return list<string>
	 */
	public static function split_options( string $value ): array {
		return array_values( array_filter( array_map( 'trim', explode( '|', html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) ) ), static fn ( string $option ): bool => '' !== $option ) );
	}
}
