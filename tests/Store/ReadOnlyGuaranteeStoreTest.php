<?php
/**
 * Runtime proof of the read-only and idempotent annotations.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Store;

/**
 * Runs every registered `linguapilot/*` ability twice on the real store and asserts that
 *
 * - no table in the database changed (all tables, including options: this covers writing listeners
 *   of the WPML/WooCommerce filters the gateways call, which static analysis cannot see), and
 * - the second call returns exactly what the first returned (idempotent; time fields excluded).
 *
 * The audit log is file-based (WooCommerce logger) and therefore outside the database.
 *
 * @coversNothing
 */
final class ReadOnlyGuaranteeStoreTest extends StoreTestCase {

	/**
	 * Representative input per ability (large pages, long periods).
	 */
	private const INPUTS = array(
		'linguapilot/languages-overview'         => null,
		'linguapilot/translation-completeness'   => array( 'per_page' => 100 ),
		'linguapilot/cross-language-consistency' => array( 'per_page' => 100 ),
		'linguapilot/untranslated-variations'    => array(),
		'linguapilot/currency-price-gaps'        => array(),
		'linguapilot/translation-work-queue'     => array( 'period_days' => 365 ),
	);

	public function test_abilities_write_nothing_and_are_idempotent(): void {
		$admins = get_users(
			array(
				'role'   => 'administrator',
				'number' => 1,
				'fields' => 'ID',
			)
		);
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

		$registered = array_values( array_filter( array_keys( wp_get_abilities() ), static fn ( string $name ): bool => str_starts_with( $name, 'linguapilot/' ) ) );
		sort( $registered );
		$expected = array_keys( self::INPUTS );
		sort( $expected );
		$this->assertSame( $expected, $registered, 'Every registered ability is covered by this test.' );

		$before = $this->all_checksums();
		$runs   = array();
		for ( $round = 0; $round < 2; $round++ ) {
			foreach ( self::INPUTS as $name => $input ) {
				$result = wp_get_ability( $name )?->execute( $input );
				$this->assertIsArray( $result, $result instanceof \WP_Error ? "{$name}: " . $result->get_error_message() : $name );
				unset( $result['meta']['generated_at'], $result['meta']['period_start'] );
				$runs[ $round ][ $name ] = $result;
			}
		}
		$after = $this->all_checksums();

		$this->assertSame( array(), array_keys( array_diff_assoc( $after, $before ) ), 'Tables changed while running the abilities.' );
		foreach ( self::INPUTS as $name => $input ) {
			$this->assertSame( $runs[0][ $name ], $runs[1][ $name ], "{$name} is not idempotent" );
		}
	}

	/**
	 * Checksum of every table in the database.
	 *
	 * @return array<string, string>
	 */
	private function all_checksums(): array {
		$wpdb      = self::wpdb();
		$checksums = array();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names come from SHOW TABLES.
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $table ) {
			$row                 = $wpdb->get_row( "CHECKSUM TABLE `{$table}`", ARRAY_A );
			$checksums[ $table ] = (string) ( $row['Checksum'] ?? 'missing' );
		}
		// phpcs:enable

		return $checksums;
	}
}
