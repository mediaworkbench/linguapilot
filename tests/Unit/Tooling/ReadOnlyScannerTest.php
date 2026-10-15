<?php
/**
 * Tests for the static read-only check.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Tooling;

use MediaWorkbench\MultilingualStoreAbilities\Tests\Tooling\ReadOnlyScanner;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * The scanner must find writes, ignore comments and reads, and honour `verify-ignore` only with a reason.
 */
final class ReadOnlyScannerTest extends TestCase {

	/**
	 * Write calls the scanner must flag, with the rule it should report.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function provide_writes(): array {
		return array(
			'wpdb insert'         => array( '$wpdb->insert( $wpdb->posts, array() );', 'wpdb-write' ),
			'wpdb query'          => array( '$wpdb->query( "SELECT 1" );', 'wpdb-write' ),
			'raw SQL update'      => array( '$wpdb->get_var( "UPDATE {$wpdb->posts} SET post_title = \'x\'" );', 'sql-write' ),
			'raw SQL delete'      => array( '$sql = "DELETE FROM wp_postmeta WHERE 1";', 'sql-write' ),
			'update_post_meta'    => array( 'update_post_meta( 1, "k", "v" );', 'option-meta' ),
			'update_option'       => array( 'update_option( "k", "v" );', 'option-meta' ),
			'set_transient'       => array( 'set_transient( "k", 1, 60 );', 'transient' ),
			'wp_insert_post'      => array( 'wp_insert_post( array() );', 'wp-crud' ),
			'wp_set_object_terms' => array( 'wp_set_object_terms( 1, array(), "product_cat" );', 'wp-crud' ),
			'wp_cache_set'        => array( 'wp_cache_set( "k", 1, "g" );', 'object-cache' ),
			'product save'        => array( '$product->save();', 'wc-crud' ),
			'meta data'           => array( '$product->update_meta_data( "k", "v" );', 'wc-crud' ),
			'remote post'         => array( 'wp_remote_post( "https://example.org" );', 'http-write' ),
			'file write'          => array( 'file_put_contents( "/tmp/x", "y" );', 'filesystem' ),
			'after a keyword'     => array( 'return unlink( $file );', 'filesystem' ),
			'cron'                => array( 'wp_schedule_single_event( time(), "hook" );', 'scheduler' ),
			'action scheduler'    => array( 'as_schedule_single_action( time(), "hook" );', 'scheduler' ),
			'woo queue'           => array( 'WC()->queue()->schedule_single( time(), "hook" );', 'scheduler' ),
			'do_action'           => array( 'do_action( "mlstore_ran" );', 'action' ),
			'logger'              => array( 'wc_get_logger()->info( "x" );', 'logger' ),
		);
	}

	/**
	 * Every known write shape is reported as a violation.
	 *
	 * @dataProvider provide_writes
	 *
	 * @param string $code Statement.
	 * @param string $rule Expected rule.
	 */
	public function test_flags_writes( string $code, string $rule ): void {
		$hits = ReadOnlyScanner::scan_source( "<?php\n" . $code . "\n" );

		$this->assertContains( $rule, array_column( $hits, 'rule' ) );
		$this->assertFalse( $hits[0]['suppressed'] );
		$this->assertSame( 2, $hits[0]['line'] );
	}

	/**
	 * Reads, look-alike names and comments are not writes.
	 */
	public function test_ignores_reads_and_comments(): void {
		$source = <<<'PHP'
		<?php
		// update_option( 'k', 'v' ); in a comment
		/** Docblock: $wpdb->insert(), wp_insert_post(), DELETE FROM x. */
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'product' ) );
		$like = $wpdb->esc_like( 'x' );
		$meta = get_post_meta( 1, 'k', true );
		$value = wp_cache_get( 'k', 'g' );
		$this->sink->log( 'info', 'message', array() );
		$copy = $gateway->copy( $rows );
		$text = 'Updated products';
		$prices = apply_filters( 'wpml_object_id', 1, 'product', true );
		PHP;

		$this->assertSame( array(), ReadOnlyScanner::scan_source( $source ) );
	}

	/**
	 * A suppression with a reason allows the next code line, also across further comment lines, and only that line.
	 */
	public function test_suppression_applies_to_next_code_line_only(): void {
		$source = <<<'PHP'
		<?php
		// verify-ignore: all -- audit log, approved.
		// A second comment line in between.
		wc_get_logger()->log( 'info', 'x', array() );
		wc_get_logger()->log( 'info', 'y', array() );
		$x = 1; // verify-ignore: readonly -- trailing comments target the next line.
		update_option( 'k', 'v' );
		PHP;

		$hits = ReadOnlyScanner::scan_source( $source );

		$this->assertSame(
			array( array( 4, true ), array( 5, false ), array( 7, true ) ),
			array_map( static fn( array $hit ): array => array( $hit['line'], $hit['suppressed'] ), $hits )
		);
	}

	/**
	 * A suppression without a reason or with an unrelated annotation does not count and is reported itself.
	 */
	public function test_suppression_needs_readonly_and_a_reason(): void {
		$source = <<<'PHP'
		<?php
		// verify-ignore: readonly
		update_option( 'a', 1 );
		// verify-ignore: destructive -- wrong annotation.
		update_option( 'b', 1 );
		PHP;

		$hits = ReadOnlyScanner::scan_source( $source );
		$rows = array_map( static fn( array $hit ): array => array( $hit['line'], $hit['rule'], $hit['suppressed'] ), $hits );

		$this->assertSame(
			array(
				array( 2, 'malformed-suppression', false ),
				array( 4, 'malformed-suppression', false ),
				array( 3, 'option-meta', false ),
				array( 5, 'option-meta', false ),
			),
			$rows
		);
	}

	/**
	 * The shipped plugin code passes: its only write is the audit log, and that is marked.
	 */
	public function test_plugin_source_passes(): void {
		$hits = ReadOnlyScanner::scan_paths( array( 'multilingual-store-abilities.php', 'uninstall.php', 'src' ), dirname( __DIR__, 3 ) );

		$this->assertSame(
			array( array( 'src/Support/WooCommerceLogSink.php', 'logger', true ) ),
			array_map( static fn( array $hit ): array => array( $hit['file'], $hit['rule'], $hit['suppressed'] ), $hits )
		);
	}
}
