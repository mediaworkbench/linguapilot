<?php
/**
 * Log output contract.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Support;

/**
 * Where audit entries are written.
 */
interface LogSink {

	/**
	 * Writes one entry.
	 *
	 * @param string               $level   PSR-3 level: info, warning or error.
	 * @param string               $message Human-readable line.
	 * @param array<string, mixed> $context Structured fields.
	 */
	public function log( string $level, string $message, array $context ): void;
}
