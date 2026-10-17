<?php
/**
 * Store tests for the sales data reads.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Store;

use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\SalesRow;
use MediaWorkbench\LinguaPilot\Gateway\WooCommerceGateway;
use MediaWorkbench\LinguaPilot\Gateway\WpmlGateway;

/**
 * The two sales sources must agree on a fully imported store, and ID normalization must match WPML's hooks.
 *
 * @covers \MediaWorkbench\LinguaPilot\Gateway\WooCommerceGateway
 * @covers \MediaWorkbench\LinguaPilot\Gateway\WpmlGateway
 */
final class SalesStoreTest extends StoreTestCase {

	private const FROM = '2000-01-01 00:00:00';

	public function test_excluded_statuses_mirror_woocommerce_defaults(): void {
		$statuses = ( new WooCommerceGateway( self::wpdb() ) )->excluded_report_statuses();

		foreach ( array( 'wc-auto-draft', 'wc-trash', 'wc-pending', 'wc-cancelled', 'wc-failed' ) as $expected ) {
			$this->assertContains( $expected, $statuses );
		}
	}

	public function test_analytics_and_order_items_agree_on_units_except_for_orders_analytics_is_missing(): void {
		$woo      = new WooCommerceGateway( self::wpdb() );
		$excluded = $woo->excluded_report_statuses();
		if ( null === $woo->analytics_order_count( self::FROM, $excluded ) ) {
			$this->markTestSkipped( 'Analytics tables unavailable.' );
		}

		$units = static function ( array $rows ): array {
			$result = array();
			foreach ( $rows as $row ) {
				$result[ $row->product_id ] = (int) round( $row->units );
			}

			return $result;
		};

		$from_orders = $units( $woo->sales_from_orders( self::FROM, $excluded ) );

		// Line items of orders Analytics has not imported (it lags behind new orders).
		$wpdb = self::wpdb();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Reference query.
		$missing = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CAST( pid.meta_value AS UNSIGNED ) AS product_id, SUM( qty.meta_value ) AS units
				FROM %i o
				LEFT JOIN %i s ON s.order_id = o.id
				INNER JOIN %i oi ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
				INNER JOIN %i pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
				INNER JOIN %i qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
				WHERE o.type = 'shop_order' AND s.order_id IS NULL AND NOT FIND_IN_SET( o.status, %s )
				GROUP BY product_id",
				$wpdb->prefix . 'wc_orders',
				$wpdb->prefix . 'wc_order_stats',
				$wpdb->prefix . 'woocommerce_order_items',
				$wpdb->prefix . 'woocommerce_order_itemmeta',
				$wpdb->prefix . 'woocommerce_order_itemmeta',
				implode( ',', $excluded )
			),
			ARRAY_A
		);
		foreach ( $missing ?? array() as $row ) {
			$id                 = (int) $row['product_id'];
			$from_orders[ $id ] = ( $from_orders[ $id ] ?? 0 ) - (int) round( (float) $row['units'] );
			if ( 0 === $from_orders[ $id ] ) {
				unset( $from_orders[ $id ] );
			}
		}

		$from_analytics = $units( $woo->sales_from_analytics( self::FROM, $excluded ) );
		ksort( $from_orders );
		ksort( $from_analytics );

		$this->assertNotEmpty( $from_analytics );
		$this->assertSame( $from_analytics, $from_orders );
	}

	public function test_originals_of_matches_wpml_hooks(): void {
		$gateway = new WpmlGateway( self::wpdb() );
		$woo     = new WooCommerceGateway( self::wpdb() );
		$ids     = array_slice( array_map( static fn ( SalesRow $row ): int => $row->product_id, $woo->sales_from_analytics( self::FROM, $woo->excluded_report_statuses() ) ), 0, 300 );

		$expected = array();
		foreach ( $ids as $id ) {
			$original = $gateway->original_id( $id, ElementType::product() );
			if ( null !== $original ) {
				$expected[ $id ] = $original;
			}
		}
		$actual = $gateway->originals_of( $ids, ElementType::product() );
		ksort( $expected );
		ksort( $actual );

		$this->assertSame( $expected, $actual );
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

		$result = wp_get_ability( 'linguapilot/translation-work-queue' )?->execute( array( 'limit' => 5 ) );

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : 'ability not registered' );
		$this->assertLessThanOrEqual( 5, count( $result['products'] ) );
		$revenues = array_column( $result['products'], 'revenue' );
		$sorted   = $revenues;
		rsort( $sorted );
		$this->assertSame( $sorted, $revenues, 'ranked by revenue, descending' );
	}
}
