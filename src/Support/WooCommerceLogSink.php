<?php
/**
 * Log sink writing to WooCommerce's logger.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Support;

/**
 * Writes to `wc_get_logger()`; entries appear under WooCommerce → Status → Logs (source `mlstore-abilities`).
 */
final class WooCommerceLogSink implements LogSink {

	/**
	 * {@inheritDoc}
	 */
	public function log( string $level, string $message, array $context ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		// verify-ignore: all -- audit log (observability, file-based, append-only); the one write approved for v0.1 (docs/api-notes.md §4). Not business state.
		// Appending a line per call would otherwise fail both `readonly` and `idempotent` in wp-abilities-verify.
		wc_get_logger()->log( $level, $message, $context );
	}
}
