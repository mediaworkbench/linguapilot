<?php
/**
 * Audit logging for ability calls.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Support;

use Throwable;

/**
 * Records who ran which ability, how long it took and how it ended.
 *
 * Never logs raw input (it may contain search terms) or output; the input is identified
 * by a short hash so repeated identical calls can still be correlated.
 */
final class AuditLogger {

	public const SOURCE = 'mlstore-abilities';

	/**
	 * Creates the logger.
	 *
	 * @param LogSink $sink Log destination.
	 */
	public function __construct( private readonly LogSink $sink ) {}

	/**
	 * Records one ability call.
	 *
	 * @param string               $ability      Ability name.
	 * @param int                  $user_id      Calling user (0 = anonymous).
	 * @param array<string, mixed> $input        Normalized input (only hashed).
	 * @param int|null             $result_count Number of returned items, null on error or for non-list abilities.
	 * @param float                $duration_ms  Execution time.
	 * @param string|null          $error_code   Error code, null on success.
	 */
	public function record( string $ability, int $user_id, array $input, ?int $result_count, float $duration_ms, ?string $error_code ): void {
		if ( ! $this->enabled( $ability ) ) {
			return;
		}

		$context = array(
			'source'       => self::SOURCE,
			'ability'      => $ability,
			'user_id'      => $user_id,
			'input_hash'   => self::hash( $input ),
			'result_count' => $result_count,
			'duration_ms'  => (int) round( $duration_ms ),
			'error_code'   => $error_code,
		);

		$message = sprintf(
			'%s user=%d input=%s results=%s duration_ms=%d%s',
			$ability,
			$user_id,
			$context['input_hash'],
			null === $result_count ? '-' : (string) $result_count,
			$context['duration_ms'],
			null === $error_code ? '' : ' error=' . $error_code
		);

		$this->sink->log( null === $error_code ? 'info' : 'warning', $message, $context );
	}

	/**
	 * Records an unexpected exception. Its message is only logged with WP_DEBUG, because it may echo input.
	 *
	 * @param string    $ability   Ability name.
	 * @param Throwable $exception The exception.
	 */
	public function record_exception( string $ability, Throwable $exception ): void {
		if ( ! $this->enabled( $ability ) ) {
			return;
		}

		$context = array(
			'source'    => self::SOURCE,
			'ability'   => $ability,
			'exception' => get_class( $exception ),
			'location'  => basename( $exception->getFile() ) . ':' . $exception->getLine(),
		);

		if ( defined( 'WP_DEBUG' ) && true === constant( 'WP_DEBUG' ) ) {
			$context['exception_message'] = $exception->getMessage();
		}

		$this->sink->log( 'error', sprintf( '%s failed with %s at %s', $ability, $context['exception'], $context['location'] ), $context );
	}

	/**
	 * Short, key-order-independent hash of an input array.
	 *
	 * @param array<mixed> $input Input to hash.
	 */
	public static function hash( array $input ): string {
		return substr( hash( 'sha256', (string) json_encode( self::canonical( $input ) ) ), 0, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure helper, no WordPress needed.
	}

	/**
	 * Whether auditing is enabled for an ability.
	 *
	 * @param string $ability Ability name.
	 */
	private function enabled( string $ability ): bool {
		/**
		 * Filters whether ability calls are written to the audit log.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $enabled Whether to log. Default true.
		 * @param string $ability Ability name.
		 */
		return (bool) apply_filters( 'mlstore_audit_enabled', true, $ability );
	}

	/**
	 * Sorts associative arrays by key, recursively; lists keep their order.
	 *
	 * @param mixed $value Value to normalize.
	 * @return mixed
	 */
	private static function canonical( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}

		$value = array_map( array( self::class, 'canonical' ), $value );
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}

		return $value;
	}
}
