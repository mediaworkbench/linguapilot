<?php
/**
 * Store tests for the manual-price reads.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Store;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogScope;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Gateway\WpmlGateway;

/**
 * WpmlGateway's manual-price SQL must agree with a meta-by-meta reference over every original.
 *
 * @covers \MediaWorkbench\LinguaPilot\Gateway\WpmlGateway
 */
final class CurrencyPriceStoreTest extends StoreTestCase {

	/**
	 * Fast path equals reference per post type.
	 *
	 * @dataProvider post_types
	 *
	 * @param string $post_type Post type.
	 */
	public function test_manual_price_originals_match_meta_reference( string $post_type ): void {
		$gateway  = new WpmlGateway( self::wpdb() );
		$type     = ElementType::post_type( $post_type );
		$expected = array();
		$offset   = 0;
		do {
			$page = $gateway->original_ids( $type, 100, $offset, CatalogScope::ANY_POST_STATUS );
			update_meta_cache( 'post', $page->ids );
			foreach ( $page->ids as $id ) {
				$flag = get_post_meta( $id, '_wcml_custom_prices_status', true );
				if ( '' !== $flag && '0' !== $flag && false !== $flag ) {
					$expected[] = $id;
				}
			}
			$offset += 100;
		} while ( array() !== $page->ids );

		$this->assertSame( $expected, $gateway->manual_price_originals( new CatalogScope( array( $type ), CatalogScope::ANY_POST_STATUS ) ) );

		$currencies = $gateway->currencies()->secondary_codes();
		$prices     = $gateway->manual_regular_prices( array_slice( $expected, 0, 100 ), $currencies );
		foreach ( array_slice( $expected, 0, 100 ) as $id ) {
			foreach ( $currencies as $currency ) {
				$raw = get_post_meta( $id, '_regular_price_' . $currency, true );
				$this->assertSame( is_string( $raw ) && '' !== $raw ? $raw : null, $prices[ $id ][ $currency ] ?? null, "{$id} {$currency}" );
			}
		}
	}

	/**
	 * Post types with manual prices.
	 *
	 * @return array<string, array{string}>
	 */
	public static function post_types(): array {
		return array(
			'products'   => array( 'product' ),
			'variations' => array( 'product_variation' ),
		);
	}

	public function test_registered_ability_runs_on_the_store(): void {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

		$result = wp_get_ability( 'linguapilot/currency-price-gaps' )?->execute();

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : 'ability not registered' );
		$this->assertSame( ( new WpmlGateway( self::wpdb() ) )->currencies()->secondary_codes(), $result['summary']['currencies_checked'] );
	}
}
