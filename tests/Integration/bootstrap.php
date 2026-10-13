<?php
/**
 * Bootstrap for integration tests: WordPress test suite + WooCommerce (+ WPML/WCML when available).
 *
 * Environment variables:
 * - WP_TESTS_DIR       WordPress test library (default: <tmp>/wordpress-tests-lib).
 * - MLSTORE_WC_PATH    WooCommerce main file (default: the sibling plugins/woocommerce install).
 * - MLSTORE_WPML_PATH  WPML main file; WPML-dependent tests are skipped when unset.
 * - MLSTORE_WCML_PATH  WCML main file; WCML-dependent tests are skipped when unset.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

$mlstore_plugin_dir = dirname( __DIR__, 2 );

require_once $mlstore_plugin_dir . '/vendor/autoload.php';

$mlstore_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( false === $mlstore_tests_dir || '' === $mlstore_tests_dir ) {
	$mlstore_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! is_readable( $mlstore_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "WordPress test library not found in {$mlstore_tests_dir}. Run bin/install-wp-tests.sh first.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $mlstore_plugin_dir . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Name required by the WP test suite.

$mlstore_wc_path = getenv( 'MLSTORE_WC_PATH' );
if ( false === $mlstore_wc_path || '' === $mlstore_wc_path ) {
	$mlstore_wc_path = dirname( $mlstore_plugin_dir ) . '/woocommerce/woocommerce.php';
}

if ( ! is_readable( $mlstore_wc_path ) ) {
	fwrite( STDERR, "WooCommerce not found at {$mlstore_wc_path}. Set MLSTORE_WC_PATH.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once $mlstore_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $mlstore_plugin_dir, $mlstore_wc_path ): void {
		require_once $mlstore_wc_path;

		foreach ( array( 'MLSTORE_WPML_PATH', 'MLSTORE_WCML_PATH' ) as $mlstore_variable ) {
			$mlstore_path = getenv( $mlstore_variable );
			if ( false !== $mlstore_path && '' !== $mlstore_path ) {
				require_once $mlstore_path;
			}
		}

		require_once $mlstore_plugin_dir . '/multilingual-store-abilities.php';
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

require $mlstore_tests_dir . '/includes/bootstrap.php';
