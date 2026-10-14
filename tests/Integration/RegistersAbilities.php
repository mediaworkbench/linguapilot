<?php
/**
 * Test helper to register abilities through the real Abilities API.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Integration;

use MediaWorkbench\MultilingualStoreAbilities\Abilities\AbstractAbility;
use MediaWorkbench\MultilingualStoreAbilities\Abilities\Registrar;

/**
 * Registers abilities inside a simulated `wp_abilities_api_init` window and removes them again.
 */
trait RegistersAbilities {

	/**
	 * Names registered by this test.
	 *
	 * @var list<string>
	 */
	private array $registered_abilities = array();

	/**
	 * Registers abilities via the plugin's Registrar, exactly as on a live site.
	 *
	 * @param AbstractAbility ...$abilities Abilities to register.
	 */
	private function register_abilities( AbstractAbility ...$abilities ): void {
		wp_get_abilities(); // Fires the registry's init hooks (WooCommerce registers its category there).

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the registration window core requires.
		try {
			( new Registrar( array_values( $abilities ) ) )->register();
		} finally {
			array_pop( $wp_current_filter );
		}

		foreach ( $abilities as $ability ) {
			$this->registered_abilities[] = $ability->name();
		}
	}

	/**
	 * Unregisters everything this test registered.
	 */
	private function unregister_abilities(): void {
		foreach ( $this->registered_abilities as $name ) {
			wp_unregister_ability( $name );
		}
		$this->registered_abilities = array();
	}

	/**
	 * Creates a user with the given role and returns its ID.
	 *
	 * @param string $role Role slug.
	 */
	private function create_user( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		$this->assertIsInt( $user_id );

		return $user_id;
	}
}
