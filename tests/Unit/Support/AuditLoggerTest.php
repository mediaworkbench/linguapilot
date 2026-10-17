<?php
/**
 * Tests for the audit logger.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Support;

use Brain\Monkey\Filters;
use MediaWorkbench\LinguaPilot\Support\AuditLogger;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\MemoryLogSink;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Logs who ran what and how it went, never the raw input or any output.
 *
 * @covers \MediaWorkbench\LinguaPilot\Support\AuditLogger
 */
final class AuditLoggerTest extends TestCase {

	/**
	 * Captures log entries.
	 *
	 * @var MemoryLogSink
	 */
	private MemoryLogSink $sink;

	/**
	 * Logger under test.
	 *
	 * @var AuditLogger
	 */
	private AuditLogger $logger;

	/**
	 * Wires the logger to an in-memory sink.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->sink   = new MemoryLogSink();
		$this->logger = new AuditLogger( $this->sink );
	}

	public function test_successful_call_is_logged_with_hash_but_without_raw_input(): void {
		$this->logger->record( 'linguapilot/probe', 7, array( 'search' => 'secret term' ), 12, 34.6, null );

		$this->assertCount( 1, $this->sink->entries );
		$entry = $this->sink->entries[0];
		$this->assertSame( 'info', $entry['level'] );
		$this->assertSame( AuditLogger::SOURCE, $entry['context']['source'] );
		$this->assertSame( 'linguapilot/probe', $entry['context']['ability'] );
		$this->assertSame( 7, $entry['context']['user_id'] );
		$this->assertSame( 12, $entry['context']['result_count'] );
		$this->assertSame( 35, $entry['context']['duration_ms'] );
		$this->assertNull( $entry['context']['error_code'] );
		$this->assertSame( AuditLogger::hash( array( 'search' => 'secret term' ) ), $entry['context']['input_hash'] );
		$this->assertStringNotContainsString( 'secret term', $entry['message'] . (string) json_encode( $entry['context'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
	}

	public function test_failed_call_is_logged_as_warning_with_error_code(): void {
		$this->logger->record( 'linguapilot/probe', 0, array(), null, 1.0, 'linguapilot_invalid_language' );

		$this->assertSame( 'warning', $this->sink->entries[0]['level'] );
		$this->assertSame( 'linguapilot_invalid_language', $this->sink->entries[0]['context']['error_code'] );
		$this->assertStringContainsString( 'linguapilot_invalid_language', $this->sink->entries[0]['message'] );
	}

	public function test_logging_can_be_disabled_per_ability(): void {
		Filters\expectApplied( 'linguapilot_audit_enabled' )->once()->with( true, 'linguapilot/probe' )->andReturn( false );

		$this->logger->record( 'linguapilot/probe', 1, array(), 1, 1.0, null );

		$this->assertSame( array(), $this->sink->entries );
	}

	public function test_hash_ignores_key_order_and_differs_for_different_input(): void {
		$a = AuditLogger::hash(
			array(
				'page'      => 1,
				'languages' => array( 'de', 'fr' ),
			)
		);
		$b = AuditLogger::hash(
			array(
				'languages' => array( 'de', 'fr' ),
				'page'      => 1,
			)
		);

		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, AuditLogger::hash( array( 'languages' => array( 'fr', 'de' ) ) ) );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', $a );
	}

	public function test_exception_is_logged_without_its_message(): void {
		$this->logger->record_exception( 'linguapilot/probe', new \RuntimeException( 'secret internal detail' ) );

		$this->assertSame( 'error', $this->sink->entries[0]['level'] );
		$this->assertSame( \RuntimeException::class, $this->sink->entries[0]['context']['exception'] );
		$this->assertStringNotContainsString( 'secret internal detail', $this->sink->entries[0]['message'] . (string) json_encode( $this->sink->entries[0]['context'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- No WordPress in unit tests.
	}
}
