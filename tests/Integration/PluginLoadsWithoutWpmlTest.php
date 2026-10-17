<?php
/**
 * Smoke tests for a store without WPML (the CI setup).
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Integration;

use MediaWorkbench\LinguaPilot\Environment\DependencyProblem;
use MediaWorkbench\LinguaPilot\Plugin;
use WP_UnitTestCase;

/**
 * The plugin must load without fatals, register nothing and tell admins why.
 *
 * @coversNothing
 */
final class PluginLoadsWithoutWpmlTest extends WP_UnitTestCase {

	/**
	 * Skips the whole class when WPML is loaded (local full-stack runs).
	 */
	public function set_up(): void {
		parent::set_up();

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$this->markTestSkipped( 'WPML is loaded; these tests describe a store without WPML.' );
		}
	}

	public function test_plugin_boots_and_blocks_registration_because_wpml_is_missing(): void {
		$plugin = Plugin::instance();

		$this->assertInstanceOf( Plugin::class, $plugin );
		$this->assertNotNull( $plugin->status() );
		$this->assertFalse( $plugin->status()->can_register_abilities() );

		$blocking = array_values(
			array_filter( $plugin->status()->problems(), static fn ( DependencyProblem $problem ): bool => $problem->blocking )
		);
		$this->assertCount( 1, $blocking );
		$this->assertSame( DependencyProblem::WPML, $blocking[0]->dependency );
	}

	public function test_no_linguapilot_abilities_are_registered(): void {
		$names = array_keys( wp_get_abilities() );

		$this->assertNotEmpty( $names, 'Sanity check: WooCommerce registers its own abilities.' );
		$this->assertSame( array(), array_values( array_filter( $names, static fn ( string $name ): bool => str_starts_with( $name, 'linguapilot/' ) ) ) );
	}

	public function test_admin_notice_is_shown_to_administrators(): void {
		wp_set_current_user( $this->create_user( 'administrator' ) );

		$output = $this->render_notices();

		$this->assertStringContainsString( 'notice-error', $output );
		$this->assertStringContainsString( 'WPML Multilingual CMS is not active.', $output );
	}

	public function test_admin_notice_is_hidden_from_shop_managers(): void {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		$this->assertSame( '', $this->render_notices() );
	}

	/**
	 * WooCommerce only lists *activated* plugins in its compatibility registry, and the test
	 * bootstrap loads plugins without activating them. So this asserts the wiring; the
	 * registry itself is checked on the DDEV site (see docs/api-notes.md §6).
	 */
	public function test_hpos_compatibility_declaration_is_hooked(): void {
		$plugin = Plugin::instance();

		$this->assertInstanceOf( Plugin::class, $plugin );
		$this->assertNotFalse( has_action( 'before_woocommerce_init', array( $plugin, 'declare_woocommerce_compatibility' ) ) );
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

	/**
	 * Captures only this plugin's notice output.
	 */
	private function render_notices(): string {
		ob_start();
		Plugin::instance()?->render_dependency_notice();
		return (string) ob_get_clean();
	}
}
