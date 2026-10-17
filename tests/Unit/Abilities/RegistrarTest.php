<?php
/**
 * Tests for ability registration.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Abilities;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use MediaWorkbench\LinguaPilot\Abilities\Registrar;
use MediaWorkbench\LinguaPilot\Environment\DependencyStatus;
use MediaWorkbench\LinguaPilot\Support\AuditLogger;
use MediaWorkbench\LinguaPilot\Tests\Support\ProbeAbility;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\MemoryLogSink;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Registers on the core hook, into WooCommerce's category, and never registers a category itself.
 *
 * @covers \MediaWorkbench\LinguaPilot\Abilities\Registrar
 */
final class RegistrarTest extends TestCase {

	/**
	 * Stubs translation functions used by the probe's schema.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\stubTranslationFunctions();
	}

	public function test_hooks_into_abilities_api_init(): void {
		$registrar = new Registrar( array() );
		Actions\expectAdded( 'wp_abilities_api_init' )->once()->with( array( $registrar, 'register' ) );

		$registrar->register_hooks();
	}

	public function test_registers_every_ability_with_its_args(): void {
		$probe = $this->probe();
		Functions\when( 'wp_has_ability_category' )->justReturn( true );
		Functions\expect( 'wp_register_ability' )->once()->with( 'linguapilot/probe', $probe->registration_args() );
		Functions\expect( 'wp_register_ability_category' )->never();

		( new Registrar( array( $probe ) ) )->register();
	}

	public function test_registers_nothing_when_the_woocommerce_category_is_missing(): void {
		Functions\when( 'wp_has_ability_category' )->justReturn( false );
		Functions\expect( 'wp_register_ability' )->never();
		Functions\expect( 'wp_register_ability_category' )->never();

		( new Registrar( array( $this->probe() ) ) )->register();
	}

	/**
	 * A probe ability with a no-op logger.
	 */
	private function probe(): ProbeAbility {
		return new ProbeAbility( new FakeMultilingualGateway(), new AuditLogger( new MemoryLogSink() ), new DependencyStatus( array() ) );
	}
}
