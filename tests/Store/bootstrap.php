<?php
/**
 * Bootstrap for the read-only store suite: loads the real DDEV site (with WPML), no test framework.
 *
 * Every v0.1 code path is read-only, so the suite runs against the dev database (ideally the
 * `wpml-baseline` snapshot). StoreTestCase checksums the content tables to prove nothing was written.
 *
 * Environment variables:
 * - MLSTORE_WP_LOAD  Path to wp-load.php (default: the WordPress install this plugin lives in).
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

$mlstore_wp_load = getenv( 'MLSTORE_WP_LOAD' );
if ( false === $mlstore_wp_load || '' === $mlstore_wp_load ) {
	$mlstore_wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}

if ( ! is_readable( $mlstore_wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at {$mlstore_wp_load}. Set MLSTORE_WP_LOAD.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Load WordPress like WP-CLI does: no theme output, no HTTP request.
define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
require_once $mlstore_wp_load;
