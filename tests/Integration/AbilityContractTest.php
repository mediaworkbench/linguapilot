<?php
/**
 * Contract tests for AbstractAbility against the real Abilities API.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Integration;

use MediaWorkbench\MultilingualStoreAbilities\Abilities\AbilityError;
use MediaWorkbench\MultilingualStoreAbilities\Abilities\Registrar;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Support\ProbeAbility;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\MemoryLogSink;
use WP_Error;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Registers the probe ability through core, then exercises it only via the public boundary
 * (`wp_get_ability()->execute()` and the REST route), the way MCP and agents call it.
 *
 * @coversNothing
 */
final class AbilityContractTest extends WP_UnitTestCase {

	private const NAME = 'mlstore/probe';

	/**
	 * Registers the probe as if during `wp_abilities_api_init`.
	 */
	public function set_up(): void {
		parent::set_up();

		wp_get_abilities(); // Fires the registry's init hooks (WooCommerce registers its category there).

		$registrar = new Registrar( array( new ProbeAbility( new FakeMultilingualGateway(), new AuditLogger( new MemoryLogSink() ), new DependencyStatus( array() ) ) ) );

		global $wp_current_filter;
		$wp_current_filter[] = 'wp_abilities_api_init'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulates the registration window core requires.
		try {
			$registrar->register();
		} finally {
			array_pop( $wp_current_filter );
		}
	}

	/**
	 * Removes the probe again.
	 */
	public function tear_down(): void {
		wp_unregister_ability( self::NAME );
		parent::tear_down();
	}

	public function test_core_accepts_the_registration_into_the_woocommerce_category(): void {
		$ability = wp_get_ability( self::NAME );

		$this->assertNotNull( $ability );
		$this->assertSame( 'woocommerce', $ability->get_category() );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest' ) );
		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			$ability->get_meta_item( 'annotations' )
		);
	}

	/**
	 * Every role in concept §12: only roles with manage_woocommerce may run abilities.
	 *
	 * @dataProvider roles
	 *
	 * @param string|null $role    Role, or null for an anonymous visitor.
	 * @param bool        $allowed Whether the role may run the ability.
	 */
	public function test_permission_per_role( ?string $role, bool $allowed ): void {
		wp_set_current_user( null === $role ? 0 : $this->create_user( $role ) );

		$result = wp_get_ability( self::NAME )?->execute();

		if ( $allowed ) {
			$this->assertIsArray( $result );
		} else {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}
	}

	/**
	 * Roles and whether they may run abilities.
	 *
	 * @return array<string, array{string|null, bool}>
	 */
	public static function roles(): array {
		return array(
			'anonymous'     => array( null, false ),
			'customer'      => array( 'customer', false ),
			'shop_manager'  => array( 'shop_manager', true ),
			'administrator' => array( 'administrator', true ),
		);
	}

	public function test_zero_argument_call_passes_core_input_and_output_validation(): void {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		$result = wp_get_ability( self::NAME )?->execute();

		$this->assertIsArray( $result );
		$this->assertSame( array( array( 'code' => 'en' ), array( 'code' => 'fr' ) ), $result['languages'] );
		$this->assertSame( 2, $result['per_page'] );
		$this->assertSame( 'de', $result['meta']['default_language'] );
	}

	public function test_core_rejects_out_of_range_and_unknown_input(): void {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );
		$ability = wp_get_ability( self::NAME );

		$too_big = $ability?->execute( array( 'per_page' => 500 ) );
		$unknown = $ability?->execute( array( 'evil' => 1 ) );

		$this->assertInstanceOf( WP_Error::class, $too_big );
		$this->assertSame( 'ability_invalid_input', $too_big->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $unknown );
		$this->assertSame( 'ability_invalid_input', $unknown->get_error_code() );
	}

	public function test_ability_errors_reach_the_caller_with_stable_codes(): void {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );
		$ability = wp_get_ability( self::NAME );

		$invalid_language = $ability?->execute( array( 'languages' => array( 'xx' ) ) );
		$crash            = $ability?->execute( array( 'mode' => 'crash' ) );

		$this->assertInstanceOf( WP_Error::class, $invalid_language );
		$this->assertSame( AbilityError::INVALID_LANGUAGE, $invalid_language->get_error_code() );
		$this->assertInstanceOf( WP_Error::class, $crash );
		$this->assertSame( AbilityError::INTERNAL, $crash->get_error_code() );
		$this->assertStringNotContainsString( 'secret', $crash->get_error_message() );
	}

	public function test_rest_route_runs_read_only_ability_via_get_with_query_input(): void {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		$request = new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/' . self::NAME . '/run' );
		$request->set_query_params(
			array(
				'input' => array(
					'per_page'  => '1',
					'languages' => array( 'fr' ),
				),
			)
		);
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );
		$this->assertSame( array( array( 'code' => 'fr' ) ), $data['languages'] );
	}

	public function test_rest_route_refuses_post_for_read_only_ability(): void {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		$request = new WP_REST_Request( 'POST', '/wp-abilities/v1/abilities/' . self::NAME . '/run' );
		$request->set_body_params( array( 'input' => array() ) );

		$this->assertGreaterThanOrEqual( 400, rest_do_request( $request )->get_status() );
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
