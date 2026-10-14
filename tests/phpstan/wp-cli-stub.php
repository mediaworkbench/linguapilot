<?php
/**
 * Minimal WP-CLI stub for PHPStan (only what tests/Fixtures uses).
 *
 * The php-stubs/wp-cli-stubs package requires wordpress-stubs <= 6.x, but this project needs the 7.1 stubs
 * (Abilities API), so the few methods are declared here instead. Never loaded at runtime.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

// phpcs:disable

class WP_CLI {

	/**
	 * @param string $message Message.
	 * @return never
	 */
	public static function error( $message ) {
		exit( 1 );
	}

	/**
	 * @param string $message Message.
	 */
	public static function log( $message ): void {}

	/**
	 * @param string $message Message.
	 */
	public static function success( $message ): void {}
}
