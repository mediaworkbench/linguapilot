<?php
/**
 * Environment detection against the running WordPress install.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Environment;

use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;

/**
 * Detects dependencies from constants and functions defined by the running plugins.
 *
 * Reads version constants itself; the one check that needs a WPML call (setup complete)
 * is delegated to the gateway. Call this on or after `plugins_loaded`, once every
 * plugin file has been loaded.
 */
final class RuntimeEnvironment implements Environment {

	/**
	 * Creates the environment.
	 *
	 * @param MultilingualGateway $gateway Used for the setup check, which needs a WPML call.
	 */
	public function __construct( private readonly MultilingualGateway $gateway ) {}

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
	 * WPML defines its version constant on load, but sets `ICL_PLUGIN_INACTIVE` to true
	 * when it cannot run (e.g. unmet requirements). Verified against WPML 5.1.0.
	 */
	public function wpml_version(): ?string {
		if ( defined( 'ICL_PLUGIN_INACTIVE' ) && true === constant( 'ICL_PLUGIN_INACTIVE' ) ) {
			return null;
		}

		return self::string_constant( 'ICL_SITEPRESS_VERSION' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_wpml_setup_complete(): bool {
		return null !== $this->wpml_version() && $this->gateway->is_setup_complete();
	}

	/**
	 * {@inheritDoc}
	 *
	 * WCML defines its version constant unconditionally, but only creates the global
	 * `$woocommerce_wpml` when its own WPML version check passes. Verified against WCML 5.6.3.
	 */
	public function wcml_version(): ?string {
		if ( ! isset( $GLOBALS['woocommerce_wpml'] ) ) {
			return null;
		}

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
