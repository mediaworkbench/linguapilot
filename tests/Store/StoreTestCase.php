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
	 * UTC time (MySQL format) when the class started, to name background jobs if the guard trips.
	 *
	 * @var string
	 */
	private static string $started_gmt = '';

	/**
	 * Records table checksums.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		self::$checksums   = self::checksums();
		self::$started_gmt = gmdate( 'Y-m-d H:i:s' );
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
			. 'or something outside the suite did (WP-Cron/Action Scheduler, a browser tab on the DDEV site). ' . ( array() === $changed ? '' : self::background_jobs_since( self::$started_gmt ) )
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
	 * Names the Action Scheduler actions that ran since a point in time; the usual cause of a tripped guard.
	 *
	 * @param string $since_gmt UTC time in MySQL format.
	 */
	private static function background_jobs_since( string $since_gmt ): string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Diagnostics on Action Scheduler's table, read-only.
		$rows = self::wpdb()->get_results(
			self::wpdb()->prepare(
				'SELECT hook, COUNT(*) AS runs FROM %i WHERE last_attempt_gmt >= %s GROUP BY hook ORDER BY runs DESC',
				self::wpdb()->prefix . 'actionscheduler_actions',
				$since_gmt
			),
			ARRAY_A
		);
		if ( empty( $rows ) ) {
			return 'No Action Scheduler action ran meanwhile; suspect a plain WP-Cron event or the test itself.';
		}

		return 'Action Scheduler ran meanwhile: ' . implode( ', ', array_map( static fn( array $row ): string => $row['hook'] . ' (' . $row['runs'] . ')', $rows ) ) . '.';
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
