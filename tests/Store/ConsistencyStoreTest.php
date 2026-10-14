<?php
/**
 * Store tests for cross-language consistency data access.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Store;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationPair;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooCommerceGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway;

/**
 * Raw SQL reads must agree with WPML's hooks and WooCommerce's own getters.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Gateway\WooCommerceGateway
 */
final class ConsistencyStoreTest extends StoreTestCase {

	/**
	 * Pairs from SQL equal the hook-based reference.
	 *
	 * @dataProvider pair_types
	 *
	 * @param string $post_type Post type.
	 */
	public function test_translation_pairs_match_hook_reference( string $post_type ): void {
		$type      = ElementType::post_type( $post_type );
		$gateway   = new WpmlGateway( self::wpdb() );
		$languages = array_values(
			array_map(
				static fn ( Language $language ): string => $language->code,
				array_filter( $gateway->active_languages(), static fn ( Language $language ): bool => ! $language->is_default )
			)
		);

		$expected = array();
		$offset   = 0;
		do {
			$page = $gateway->original_ids( $type, 100, $offset, CatalogScope::ANY_POST_STATUS );
			foreach ( $page->ids as $original ) {
				$group = $gateway->translations( $original, $type );
				foreach ( $languages as $language ) {
					$member = $group[ $language ] ?? null;
					if ( null === $member || $member === $original ) {
						continue;
					}
					$status = get_post_status( $member );
					if ( TranslationState::Missing !== TranslationState::from_post_status( false === $status ? null : $status ) ) {
						$expected[] = array( $original, $member, $language );
					}
				}
			}
			$offset += 100;
		} while ( array() !== $page->ids );

		$actual = array_map(
			static fn ( TranslationPair $pair ): array => array( $pair->original_id, $pair->translation_id, $pair->language ),
			$gateway->translation_pairs( new CatalogScope( array( $type ), CatalogScope::ANY_POST_STATUS ), $languages )
		);

		$this->assertSame( $expected, $actual );
	}

	/**
	 * Post types with translation pairs.
	 *
	 * @return array<string, array{string}>
	 */
	public static function pair_types(): array {
		return array(
			'products'   => array( 'product' ),
			'variations' => array( 'product_variation' ),
		);
	}

	public function test_product_fields_match_woocommerce_getters(): void {
		$ids    = ( new WpmlGateway( self::wpdb() ) )->original_ids( ElementType::product(), 50, 0 )->ids;
		$fields = ( new WooCommerceGateway( self::wpdb() ) )->product_fields( $ids );

		$this->assertSame( $ids, array_keys( $fields ) );
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			$this->assertNotFalse( $product );
			$this->assertNotNull( $product );

			$this->assertSame( $product->get_sku( 'edit' ), (string) $fields[ $id ]['sku'], "sku {$id}" );
			$this->assertSame( $product->get_stock_status( 'edit' ), $fields[ $id ]['stock_status'], "stock_status {$id}" );
			$this->assertSame( $product->get_manage_stock( 'edit' ) ? 'yes' : 'no', $fields[ $id ]['manage_stock'] ?? 'no', "manage_stock {$id}" );
			$this->assertSame( $product->get_tax_class( 'edit' ), (string) $fields[ $id ]['tax_class'], "tax_class {$id}" );
			$this->assertSame( $product->get_catalog_visibility( 'edit' ), $fields[ $id ]['catalog_visibility'], "catalog_visibility {$id}" );
			$this->assertSame( get_post_status( $id ), $fields[ $id ]['post_status'], "post_status {$id}" );
		}
	}

	public function test_registered_ability_runs_and_validates_on_the_store(): void {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

		$result = wp_get_ability( 'mlstore/cross-language-consistency' )?->execute();

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : 'ability not registered' );
		$this->assertGreaterThanOrEqual( $result['total_items'], $result['summary']['translations_checked'] );
	}
}
