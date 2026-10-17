<?php
/**
 * Bootstrap for integration tests: WordPress test suite + WooCommerce (+ WPML/WCML when available).
 *
 * Environment variables:
 * - WP_TESTS_DIR       WordPress test library (default: <tmp>/wordpress-tests-lib).
 * - LINGUAPILOT_WC_PATH    WooCommerce main file (default: the sibling plugins/woocommerce install).
 * - LINGUAPILOT_WPML_PATH  WPML main file; WPML-dependent tests are skipped when unset.
 * - LINGUAPILOT_WCML_PATH  WCML main file; WCML-dependent tests are skipped when unset.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

$linguapilot_plugin_dir = dirname( __DIR__, 2 );

require_once $linguapilot_plugin_dir . '/vendor/autoload.php';

$linguapilot_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $linguapilot_tests_dir || '' === $linguapilot_tests_dir ) {
	$linguapilot_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! is_readable( $linguapilot_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$linguapilot_tests_dir}. Run bin/install-wp-tests.sh first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $linguapilot_plugin_dir . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name required by the WP test suite.

$linguapilot_wc_path = getenv( 'LINGUAPILOT_WC_PATH' );
if ( false === $linguapilot_wc_path || '' === $linguapilot_wc_path ) {
	$linguapilot_wc_path = dirname( $linguapilot_plugin_dir ) . '/woocommerce/woocommerce.php';
}

if ( ! is_readable( $linguapilot_wc_path ) ) {
	fwrite( STDERR, "WooCommerce not found at {$linguapilot_wc_path}. Set LINGUAPILOT_WC_PATH.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $linguapilot_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $linguapilot_plugin_dir, $linguapilot_wc_path ): void {
		require_once $linguapilot_wc_path;

		foreach ( array( 'LINGUAPILOT_WPML_PATH', 'LINGUAPILOT_WCML_PATH' ) as $linguapilot_variable ) {
			$linguapilot_path = getenv( $linguapilot_variable );
			if ( false !== $linguapilot_path && '' !== $linguapilot_path ) {
				require_once $linguapilot_path;
			}
		}

		require_once $linguapilot_plugin_dir . '/linguapilot.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		// Create WooCommerce tables and roles (shop_manager, customer) in the test database.
		WC_Install::install();
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
	}
);

require $linguapilot_tests_dir . '/includes/bootstrap.php';
