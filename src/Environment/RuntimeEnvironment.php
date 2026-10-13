<?php
/**
 * Environment detection against the running WordPress install.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Environment;

/**
 * Detects dependencies from constants and functions defined by the running plugins.
 *
 * Only reads version constants; it never calls into WPML or WCML. All actual WPML/WCML
 * calls belong in the WPML gateway. Call this on or after `plugins_loaded`, once every
 * plugin file has been loaded.
 */
final class RuntimeEnvironment implements Environment {

	/**
	 * {@inheritDoc}
	 */
	public function has_abilities_api(): bool {
		return function_exists( 'wp_register_ability' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function woocommerce_version(): ?string {
		return self::string_constant( 'WC_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Constant name to be verified against the installed WPML (docs/api-notes.md §5).
	 */
	public function wpml_version(): ?string {
		return self::string_constant( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Constant name to be verified against the installed WCML (docs/api-notes.md §5).
	 */
	public function wcml_version(): ?string {
		return self::string_constant( 'WCML_VERSION' );
	}

	/**
	 * Returns a constant's value when it is defined as a non-empty string.
	 *
	 * @param string $name Constant name.
	 */
	private static function string_constant( string $name ): ?string {
		if ( ! defined( $name ) ) {
			return null;
		}

		$value = constant( $name );

		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
