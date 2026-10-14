<?php
/**
 * Base class for the read-only store suite.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Store;

use MediaWorkbench\MultilingualStoreAbilities\Plugin;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Skips when WPML is not usable and fails when anything wrote to the content tables.
 */
abstract class StoreTestCase extends TestCase {

	/**
	 * Tables whose contents must be identical before and after the suite.
	 */
	private const GUARDED_TABLES = array(
		'posts',
		'postmeta',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'termmeta',
		'icl_translations',
		'wc_order_product_lookup',
	);

	/**
	 * Checksums taken before the first test of the class.
	 *
	 * @var array<string, string>
	 */
	private static array $checksums = array();

	/**
	 * Records table checksums.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$checksums = self::checksums();
	}

	/**
	 * Asserts that no guarded table changed.
	 */
	public static function tear_down_after_class(): void {
		$after = self::checksums();
		parent::tear_down_after_class();

		$changed = array_keys( array_diff_assoc( $after, self::$checksums ) );
		self::assertSame(
			array(),
			$changed,
			'Guarded tables changed during ' . static::class . ': ' . implode( ', ', $changed ) . '. Either a test wrote to the database, '
			. 'or something outside the suite did (WP-Cron/Action Scheduler, a browser tab on the DDEV site). Check wp_actionscheduler_logs.'
		);
	}

	/**
	 * Skips the test unless the plugin found a usable WPML.
	 */
	protected function set_up(): void {
		parent::set_up();

		$status = Plugin::instance()?->status();
		if ( null === $status || ! $status->can_register_abilities() ) {
			$this->markTestSkipped( 'WPML is not active and set up on this site.' );
		}
	}

	/**
	 * The global database object.
	 */
	protected static function wpdb(): \wpdb {
		return $GLOBALS['wpdb'];
	}

	/**
	 * Current checksum of every guarded table.
	 *
	 * @return array<string, string>
	 */
	private static function checksums(): array {
		$checksums = array();
		foreach ( self::GUARDED_TABLES as $table ) {
			$name = self::wpdb()->prefix . $table;
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery -- Table names from a constant.
			$row                 = self::wpdb()->get_row( "CHECKSUM TABLE {$name}", ARRAY_A );
			$checksums[ $table ] = (string) ( $row['Checksum'] ?? 'missing' );
		}

		return $checksums;
	}
}
