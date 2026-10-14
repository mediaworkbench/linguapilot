<?php
/**
 * Expected ability failure with a stable error code.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Abilities;

use RuntimeException;

/**
 * Thrown by abilities and domain services; mapped to a WP_Error by {@see AbstractAbility}.
 *
 * Error codes are part of the public contract: agents branch on them, so never rename one.
 */
final class AbilityError extends RuntimeException {

	public const DEPENDENCY_MISSING = 'mlstore_dependency_missing';
	public const INVALID_LANGUAGE   = 'mlstore_invalid_language';
	public const CURRENCY_DISABLED  = 'mlstore_currency_disabled';
	public const INVALID_INPUT      = 'mlstore_invalid_input';
	public const NOT_FOUND          = 'mlstore_not_found';
	public const INTERNAL           = 'mlstore_internal_error';

	/**
	 * Creates an error.
	 *
	 * @param string $error_code  One of the class constants.
	 * @param string $message     Translated, user-safe message.
	 * @param int    $http_status HTTP status for REST responses.
	 */
	public function __construct(
		public readonly string $error_code,
		string $message,
		public readonly int $http_status = 400,
	) {
		parent::__construct( $message );
	}

	/**
	 * A required plugin is not active.
	 *
	 * @param string $dependency Human-readable plugin name.
	 */
	public static function dependency_missing( string $dependency ): self {
		return new self(
			self::DEPENDENCY_MISSING,
			/* translators: %s: Plugin name. */
			sprintf( __( 'This ability requires %s, which is not active.', 'multilingual-store-abilities' ), $dependency ),
			424
		);
	}

	/**
	 * Requested languages are not active (or not allowed here).
	 *
	 * @param list<string> $codes Offending language codes.
	 */
	public static function invalid_language( array $codes ): self {
		return new self(
			self::INVALID_LANGUAGE,
			/* translators: %s: Comma-separated language codes. */
			sprintf( __( 'Unknown or not allowed language codes: %s. Use mlstore/languages-overview to list the active languages.', 'multilingual-store-abilities' ), implode( ', ', $codes ) )
		);
	}

	/**
	 * Multi-currency is not enabled.
	 */
	public static function currency_disabled(): self {
		return new self(
			self::CURRENCY_DISABLED,
			__( 'WooCommerce Multilingual multi-currency is not enabled.', 'multilingual-store-abilities' ),
			409
		);
	}
}
