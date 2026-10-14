<?php
/**
 * Store tests for the raw variation/attribute reads.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Store;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductAttribute;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TermRef;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooCommerceGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway;
use WC_Product_Attribute;

/**
 * WooCommerceGateway's raw SQL reads must agree with WooCommerce's own product API.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Gateway\WooCommerceGateway
 */
final class VariationDataStoreTest extends StoreTestCase {

	/**
	 * Gateway under test.
	 *
	 * @var WooCommerceGateway
	 */
	private WooCommerceGateway $woo;

	/**
	 * Original products checked (first 100).
	 *
	 * @var list<int>
	 */
	private array $ids;

	/**
	 * Loads the first 100 original products.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->woo = new WooCommerceGateway( self::wpdb() );
		$this->ids = ( new WpmlGateway( self::wpdb() ) )->original_ids( ElementType::product(), 100, 0 )->ids;
	}

	public function test_variable_products_and_children_match_woocommerce(): void {
		$variable = $this->woo->variable_product_ids( $this->ids );
		$children = $this->woo->children( $variable );

		$this->assertNotEmpty( $variable, 'Store needs variable products (generated catalog).' );
		foreach ( $this->ids as $id ) {
			$product = wc_get_product( $id );
			$this->assertNotFalse( $product );
			$this->assertNotNull( $product );
			$this->assertSame( $product->is_type( 'variable' ), in_array( $id, $variable, true ), "variable {$id}" );
		}

		foreach ( $variable as $id ) {
			$expected = array_values(
				array_filter(
					array_map(
						'intval',
						get_children(
							array(
								'post_parent' => $id,
								'post_type'   => 'product_variation',
								'fields'      => 'ids',
								'post_status' => array( 'publish', 'private' ),
								'orderby'     => array(
									'menu_order' => 'ASC',
									'ID'         => 'ASC',
								),
							)
						)
					)
				)
			);
			$this->assertSame( $expected, $children[ $id ] ?? array(), "children {$id}" );
		}
	}

	public function test_attributes_match_woocommerce(): void {
		$attributes = $this->woo->product_attributes( $this->ids );

		foreach ( $this->ids as $id ) {
			$product = wc_get_product( $id );
			$this->assertNotFalse( $product );
			$this->assertNotNull( $product );
			$expected = array_map(
				static fn ( WC_Product_Attribute $attribute ): array => array(
					$attribute->get_name(),
					$attribute->is_taxonomy(),
					$attribute->get_variation(),
					$attribute->is_taxonomy() ? array() : array_values( $attribute->get_options() ),
				),
				array_values( $product->get_attributes( 'edit' ) )
			);
			$actual   = array_map(
				static fn ( ProductAttribute $attribute ): array => array( $attribute->name, $attribute->is_taxonomy, $attribute->is_variation, $attribute->options ),
				$attributes[ $id ] ?? array()
			);
			$this->assertSame( $expected, $actual, "attributes {$id}" );
		}
	}

	public function test_variation_values_and_terms_match_woocommerce(): void {
		$variable   = $this->woo->variable_product_ids( $this->ids );
		$taxonomies = $this->woo->attribute_taxonomies();
		$terms      = $this->woo->object_terms( $variable, $taxonomies );
		$variations = array_merge( ...array_values( $this->woo->children( $variable ) ) );
		$values     = $this->woo->variation_attribute_values( $variations );

		foreach ( $variable as $id ) {
			foreach ( $taxonomies as $taxonomy ) {
				$expected = array_map(
					static fn ( \WP_Term $term ): int => $term->term_id,
					wc_get_product_terms(
						$id,
						$taxonomy,
						array(
							'fields'  => 'all',
							'orderby' => 'id',
						)
					)
				);
				$actual   = array_map( static fn ( TermRef $term ): int => $term->id, $terms[ $id ][ $taxonomy ] ?? array() );
				sort( $expected );
				$this->assertSame( $expected, $actual, "terms {$id} {$taxonomy}" );
			}
		}

		foreach ( array_slice( $variations, 0, 200 ) as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			$this->assertNotFalse( $variation );
			$this->assertNotNull( $variation );
			$expected = array();
			foreach ( $variation->get_attributes( 'edit' ) as $key => $value ) {
				$expected[ 'attribute_' . $key ] = (string) $value;
			}
			$actual = $values[ $variation_id ] ?? array();
			ksort( $expected );
			ksort( $actual );
			$this->assertSame( $expected, $actual, "variation values {$variation_id}" );
		}
	}
}
