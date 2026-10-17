<?php
/**
 * Store tests for linguapilot/languages-overview on the real WPML/WCML site.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Store;

/**
 * Runs the registered ability as an administrator and cross-checks it against WPML/WCML data.
 *
 * @coversNothing
 */
final class LanguagesOverviewStoreTest extends StoreTestCase {

	/**
	 * Runs as the first administrator (read-only; no users are created).
	 */
	protected function set_up(): void {
		parent::set_up();

		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
	}

	public function test_registered_ability_returns_valid_output_matching_the_store(): void {
		$result = wp_get_ability( 'linguapilot/languages-overview' )?->execute();

		$this->assertIsArray( $result, $result instanceof \WP_Error ? $result->get_error_message() : 'ability not registered' );
		$this->assertSame( apply_filters( 'wpml_default_language', null ), $result['default_language'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cross-check against WPML.
		$this->assertTrue( $result['wcml_active'] );
		$this->assertSame( get_option( 'woocommerce_currency' ), $result['default_currency'] );

		$wpdb     = self::wpdb();
		$products = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT COUNT(*) FROM %i t JOIN %i p ON p.ID = t.element_id
				WHERE t.element_type = 'post_product' AND t.source_language_code IS NULL AND p.post_status NOT IN ('trash', 'auto-draft')",
				$wpdb->prefix . 'icl_translations',
				$wpdb->posts
			)
		);
		$this->assertSame( $products, $result['catalog_size']['products'] );
	}

	public function test_anonymous_visitor_is_refused(): void {
		wp_set_current_user( 0 );

		$result = wp_get_ability( 'linguapilot/languages-overview' )?->execute();

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
	}
}
