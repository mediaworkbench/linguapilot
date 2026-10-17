<?php
/**
 * Integration tests for linguapilot/languages-overview.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Integration;

use MediaWorkbench\LinguaPilot\Abilities\LanguagesOverview;
use MediaWorkbench\LinguaPilot\Domain\Model\Currency;
use MediaWorkbench\LinguaPilot\Domain\Model\CurrencySettings;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Environment\DependencyProblem;
use MediaWorkbench\LinguaPilot\Environment\DependencyStatus;
use MediaWorkbench\LinguaPilot\Support\AuditLogger;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\MemoryLogSink;
use WP_Error;
use WP_UnitTestCase;

/**
 * The real ability class, registered through core with a fake store, called like an agent would.
 *
 * @coversNothing
 */
final class LanguagesOverviewTest extends WP_UnitTestCase {

	use RegistersAbilities;

	private const NAME = 'linguapilot/languages-overview';

	/**
	 * Removes the ability after each test.
	 */
	public function tear_down(): void {
		$this->unregister_abilities();
		parent::tear_down();
	}

	/**
	 * Anonymous, customer, shop_manager and administrator.
	 *
	 * @dataProvider roles
	 *
	 * @param string|null $role    Role, or null for anonymous.
	 * @param bool        $allowed Whether the role may run the ability.
	 */
	public function test_permission_per_role( ?string $role, bool $allowed ): void {
		$this->register();
		wp_set_current_user( null === $role ? 0 : $this->create_user( $role ) );

		$result = wp_get_ability( self::NAME )?->execute();

		$this->assertSame( $allowed, is_array( $result ) );
		if ( ! $allowed ) {
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		}
	}

	/**
	 * Roles and whether they may run the ability.
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

	public function test_output_passes_core_schema_validation_and_reports_the_store(): void {
		$gateway = ( new FakeMultilingualGateway() )
			->add_group(
				ElementType::product(),
				array(
					'de' => 1,
					'en' => 2,
				)
			)
			->set_currencies( new CurrencySettings( true, 'EUR', array( new Currency( 'EUR', array( 'de' ) ), new Currency( 'CHF', array( 'de', 'fr' ) ) ) ) );
		$this->register( $gateway );
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		$result = wp_get_ability( self::NAME )?->execute();

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 'de', $result['default_language'] );
		$this->assertTrue( $result['multi_currency_enabled'] );
		$this->assertSame( array( 'EUR', 'CHF' ), array_column( $result['currencies'], 'code' ) );
		$this->assertSame( 1, $result['catalog_size']['products'] );
	}

	public function test_rejects_any_input_argument(): void {
		$this->register();
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		$result = wp_get_ability( self::NAME )?->execute( array( 'languages' => array( 'fr' ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	public function test_works_without_wcml(): void {
		$this->register( null, new DependencyStatus( array( new DependencyProblem( DependencyProblem::WCML, false ) ) ) );
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		$result = wp_get_ability( self::NAME )?->execute();

		$this->assertIsArray( $result );
		$this->assertFalse( $result['wcml_active'] );
	}

	public function test_annotations_declare_a_read_only_ability(): void {
		$this->register();

		$this->assertSame(
			array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			wp_get_ability( self::NAME )?->get_meta_item( 'annotations' )
		);
	}

	/**
	 * Registers the ability with a fake store.
	 *
	 * @param FakeMultilingualGateway|null $gateway Store; default DE (default), EN, FR.
	 * @param DependencyStatus|null        $status  Dependencies; default everything available.
	 */
	private function register( ?FakeMultilingualGateway $gateway = null, ?DependencyStatus $status = null ): void {
		$this->register_abilities(
			new LanguagesOverview(
				$gateway ?? new FakeMultilingualGateway(),
				new AuditLogger( new MemoryLogSink() ),
				$status ?? new DependencyStatus( array() )
			)
		);
	}
}
