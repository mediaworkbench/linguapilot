<?php
/**
 * Bootstrap for unit tests: no WordPress, no database.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/Stubs/WP_Error.php';
}
