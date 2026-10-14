<?php
/**
 * Integration tests for mlstore/currency-price-gaps.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Integration;

use MediaWorkbench\MultilingualStoreAbilities\Abilities\AbilityError;
use MediaWorkbench\MultilingualStoreAbilities\Abilities\CurrencyPriceGaps;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Currency;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyProblem;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\MemoryLogSink;
use WP_Error;
use WP_UnitTestCase;

/**
 * The real ability class through core, with a fake store: EUR base, CHF and USD secondary;
 * product 1 has manual prices without USD.
 *
 * @coversNothing
 */
final class CurrencyPriceGapsTest extends WP_UnitTestCase {

	use RegistersAbilities;

	private const NAME = 'mlstore/currency-price-gaps';

	/**
	 * Removes the ability.
	 */
	public function tear_down(): void {
		$this->unregister_abilities();
		parent::tear_down();
	}

	/**
	 * Concept §12 roles.
	 *
	 * @dataProvider roles
	 *
	 * @param string|null $role    Role, or null for anonymous.
	 * @param bool        $allowed Whether the role may run the ability.
	 */
	public function test_permission_per_role( ?string $role, bool $allowed ): void {
		$this->register();
		wp_set_current_user( null === $role ? 0 : $this->create_user( $role ) );

		$this->assertSame( $allowed, is_array( wp_get_ability( self::NAME )?->execute() ) );
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

	public function test_output_passes_core_validation(): void {
		$this->register();

		$result = $this->run_as_shop_manager();

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( array( 'USD' ), $result['items'][0]['missing_currencies'] );
		$this->assertSame( array( 'CHF', 'USD' ), $result['summary']['currencies_checked'] );
	}

	public function test_multi_currency_disabled(): void {
		$this->register( new CurrencySettings( false, 'EUR', array( new Currency( 'EUR', array() ) ) ) );

		$result = $this->run_as_shop_manager();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( AbilityError::CURRENCY_DISABLED, $result->get_error_code() );
	}

	public function test_wcml_missing(): void {
		$this->register( null, new DependencyStatus( array( new DependencyProblem( DependencyProblem::WCML, false ) ) ) );

		$result = $this->run_as_shop_manager();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( AbilityError::DEPENDENCY_MISSING, $result->get_error_code() );
	}

	/**
	 * Unusable currency inputs.
	 *
	 * @dataProvider invalid_currencies
	 *
	 * @param list<string> $currencies Requested currencies.
	 * @param string       $code       Expected error code.
	 */
	public function test_invalid_currencies( array $currencies, string $code ): void {
		$this->register();

		$result = $this->run_as_shop_manager( array( 'currencies' => $currencies ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Invalid currency inputs.
	 *
	 * @return array<string, array{list<string>, string}>
	 */
	public static function invalid_currencies(): array {
		return array(
			'base currency'      => array( array( 'EUR' ), AbilityError::INVALID_INPUT ),
			'unknown currency'   => array( array( 'JPY' ), AbilityError::INVALID_INPUT ),
			'malformed currency' => array( array( 'chf' ), 'ability_invalid_input' ),
		);
	}

	/**
	 * Registers the ability with a fake store.
	 *
	 * @param CurrencySettings|null $currencies Currency settings; default EUR + CHF + USD enabled.
	 * @param DependencyStatus|null $status     Dependencies; default everything available.
	 */
	private function register( ?CurrencySettings $currencies = null, ?DependencyStatus $status = null ): void {
		$gateway = ( new FakeMultilingualGateway() )
			->add_group( ElementType::product(), array( 'de' => 1 ) )
			->set_manual_prices( 1, array( 'CHF' => '20' ) )
			->set_currencies( $currencies ?? new CurrencySettings( true, 'EUR', array( new Currency( 'EUR', array( 'de' ) ), new Currency( 'CHF', array( 'de' ) ), new Currency( 'USD', array( 'en' ) ) ) ) );
		$woo     = ( new FakeWooGateway() )->add_product( 1, 'Mug' )->set_fields( 1, array( 'regular_price' => '18' ) );

		$this->register_abilities( new CurrencyPriceGaps( $gateway, $woo, new AuditLogger( new MemoryLogSink() ), $status ?? new DependencyStatus( array() ) ) );
	}

	/**
	 * Runs the ability as a shop manager.
	 *
	 * @param array<string, mixed>|null $input Input.
	 * @return mixed
	 */
	private function run_as_shop_manager( ?array $input = null ) {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		return wp_get_ability( self::NAME )?->execute( $input );
	}
}
