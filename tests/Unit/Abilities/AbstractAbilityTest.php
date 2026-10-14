<?php
/**
 * Tests for the shared ability behaviour.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Abilities;

use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use DateTimeImmutable;
use DateTimeZone;
use MediaWorkbench\MultilingualStoreAbilities\Abilities\AbilityError;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyProblem;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Plugin;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Support\ProbeAbility;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\MemoryLogSink;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;
use WP_Error;

/**
 * Registration args, permissions, input normalization, error mapping and auditing.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Abilities\AbstractAbility
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Abilities\AbilityError
 */
final class AbstractAbilityTest extends TestCase {

	/**
	 * Captured audit entries.
	 *
	 * @var MemoryLogSink
	 */
	private MemoryLogSink $sink;

	/**
	 * Stubs WordPress functions used by every test.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\stubTranslationFunctions();
		Functions\when( 'get_current_user_id' )->justReturn( 42 );
		$this->sink = new MemoryLogSink();
	}

	public function test_registration_args_follow_the_documented_conventions(): void {
		$args = $this->probe()->registration_args();

		$this->assertSame( 'woocommerce', $args['category'] );
		$this->assertSame(
			array(
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
			$args['meta']
		);
		$this->assertSame( 'object', $args['input_schema']['type'] );
		$this->assertFalse( $args['input_schema']['additionalProperties'] );
		$this->assertSame( array(), $args['input_schema']['default'] );
		$this->assertSame( 'object', $args['output_schema']['type'] );
		$this->assertIsCallable( $args['execute_callback'] );
		$this->assertIsCallable( $args['permission_callback'] );
	}

	public function test_permission_requires_manage_woocommerce_by_default(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'manage_woocommerce' )->andReturn( false );

		$this->assertFalse( $this->probe()->check_permission() );
	}

	public function test_capability_is_filterable_per_ability(): void {
		Filters\expectApplied( 'mlstore_ability_capability' )->once()->with( 'manage_woocommerce', 'mlstore/probe' )->andReturn( 'view_mlstore_reports' );
		Functions\expect( 'current_user_can' )->once()->with( 'view_mlstore_reports' )->andReturn( true );

		$this->assertTrue( $this->probe()->check_permission() );
	}

	public function test_non_string_capability_from_filter_denies(): void {
		Filters\expectApplied( 'mlstore_ability_capability' )->andReturn( null );
		Functions\expect( 'current_user_can' )->never();

		$this->assertFalse( $this->probe()->check_permission() );
	}

	public function test_property_defaults_are_applied_and_integers_clamped(): void {
		$probe = $this->probe();
		$probe->execute( array( 'per_page' => 5000 ) );

		$this->assertSame(
			array(
				'languages' => array(),
				'mode'      => 'ok',
				'page'      => 1,
				'per_page'  => 100,
			),
			$probe->last_input
		);
	}

	public function test_unknown_input_keys_are_dropped(): void {
		$probe = $this->probe();
		$probe->execute( array( 'evil' => 'x' ) );

		$this->assertArrayNotHasKey( 'evil', (array) $probe->last_input );
	}

	public function test_successful_output_has_envelope_and_meta(): void {
		$output = $this->probe()->execute( array( 'per_page' => 1 ) );

		$this->assertIsArray( $output );
		$this->assertSame( array( array( 'code' => 'en' ) ), $output['languages'] );
		$this->assertSame( 2, $output['total_items'] );
		$this->assertSame( 2, $output['total_pages'] );
		$this->assertSame(
			array(
				'generated_at'     => '2026-10-14T08:30:00Z',
				'default_language' => 'de',
				'languages'        => array( 'de', 'en', 'fr' ),
				'plugin_version'   => Plugin::VERSION,
			),
			$output['meta']
		);
	}

	public function test_requested_languages_are_validated_against_active_languages(): void {
		$error = $this->probe()->execute( array( 'languages' => array( 'fr', 'xx', 'yy' ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( AbilityError::INVALID_LANGUAGE, $error->get_error_code() );
		$this->assertStringContainsString( 'xx, yy', $error->get_error_message() );
		$this->assertSame( array( 'status' => 400 ), $error->get_error_data() );
	}

	public function test_requested_languages_are_deduplicated_and_kept_in_language_order(): void {
		$output = $this->probe()->execute( array( 'languages' => array( 'fr', 'en', 'fr' ) ) );

		$this->assertIsArray( $output );
		$this->assertSame( array( array( 'code' => 'en' ), array( 'code' => 'fr' ) ), $output['languages'] );
	}

	public function test_default_language_cannot_be_requested_as_a_translation_target(): void {
		$error = $this->probe()->execute( array( 'languages' => array( 'de' ) ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( AbilityError::INVALID_LANGUAGE, $error->get_error_code() );
	}

	public function test_ability_error_becomes_wp_error_with_its_code_and_status(): void {
		$error = $this->probe()->execute( array( 'mode' => 'fail' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( AbilityError::CURRENCY_DISABLED, $error->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $error->get_error_data() );
	}

	public function test_unexpected_exception_becomes_generic_error_without_leaking_details(): void {
		$error = $this->probe()->execute( array( 'mode' => 'crash' ) );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( AbilityError::INTERNAL, $error->get_error_code() );
		$this->assertStringNotContainsString( 'secret', $error->get_error_message() );
		$this->assertSame( array( 'status' => 500 ), $error->get_error_data() );
		$this->assertSame( array( 'error', 'warning' ), array_column( $this->sink->entries, 'level' ) );
	}

	public function test_wcml_dependent_ability_fails_cleanly_without_wcml(): void {
		$probe             = $this->probe( new DependencyStatus( array( new DependencyProblem( DependencyProblem::WCML, false ) ) ) );
		$probe->needs_wcml = true;

		$error = $probe->execute( array() );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( AbilityError::DEPENDENCY_MISSING, $error->get_error_code() );
		$this->assertNull( $probe->last_input );
	}

	public function test_every_call_is_audited_once_with_count_and_error_code(): void {
		$probe = $this->probe();
		$probe->execute( array() );
		$probe->execute( array( 'mode' => 'fail' ) );

		$this->assertCount( 2, $this->sink->entries );
		$this->assertSame( 42, $this->sink->entries[0]['context']['user_id'] );
		$this->assertSame( 2, $this->sink->entries[0]['context']['result_count'] );
		$this->assertNull( $this->sink->entries[0]['context']['error_code'] );
		$this->assertNull( $this->sink->entries[1]['context']['result_count'] );
		$this->assertSame( AbilityError::CURRENCY_DISABLED, $this->sink->entries[1]['context']['error_code'] );
	}

	/**
	 * Builds the probe with a fake store (DE default, EN, FR), a fixed clock and a capturing logger.
	 *
	 * @param DependencyStatus|null $status Dependency status; default = everything available.
	 */
	private function probe( ?DependencyStatus $status = null ): ProbeAbility {
		return new ProbeAbility(
			new FakeMultilingualGateway(),
			new AuditLogger( $this->sink ),
			$status ?? new DependencyStatus( array() ),
			static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-10-14 08:30:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
