<?php
/**
 * In-memory log sink for tests.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Fakes;

use MediaWorkbench\LinguaPilot\Support\LogSink;

/**
 * Collects log entries so tests can assert on them.
 */
final class MemoryLogSink implements LogSink {

	/**
	 * Entries in write order.
	 *
	 * @var list<array{level: string, message: string, context: array<string, mixed>}>
	 */
	public array $entries = array();

	/**
	 * {@inheritDoc}
	 */
	public function log( string $level, string $message, array $context ): void {
		$this->entries[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}
}
