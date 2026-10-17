<?php
/**
 * Registers the plugin's abilities.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Abilities;

/**
 * Loader only: registers each ability on `wp_abilities_api_init`.
 *
 * Abilities go into WooCommerce's shared `woocommerce` category, which WooCommerce 10.9+ registers;
 * this plugin never registers or re-registers a category.
 */
final class Registrar {

	/**
	 * Creates the registrar.
	 *
	 * @param list<AbstractAbility> $abilities Abilities to register.
	 */
	public function __construct( private readonly array $abilities ) {}

	/**
	 * Hooks registration into the Abilities API.
	 */
	public function register_hooks(): void {
		add_action( 'wp_abilities_api_init', array( $this, 'register' ) );
	}

	/**
	 * Registers all abilities. Runs on `wp_abilities_api_init`.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_has_ability_category' ) || ! wp_has_ability_category( AbstractAbility::CATEGORY ) ) {
			return;
		}

		foreach ( $this->abilities as $ability ) {
			wp_register_ability( $ability->name(), $ability->registration_args() );
		}
	}

	/**
	 * The abilities this registrar manages.
	 *
	 * @return list<AbstractAbility>
	 */
	public function abilities(): array {
		return $this->abilities;
	}
}
