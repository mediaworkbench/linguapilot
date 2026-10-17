<?php
/**
 * Integration tests for linguapilot/translation-work-queue.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use MediaWorkbench\LinguaPilot\Abilities\AbilityError;
use MediaWorkbench\LinguaPilot\Abilities\TranslationWorkQueue;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\SalesRow;
use MediaWorkbench\LinguaPilot\Environment\DependencyStatus;
use MediaWorkbench\LinguaPilot\Support\AuditLogger;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\MemoryLogSink;
use WP_Error;
use WP_UnitTestCase;

/**
 * The real ability class through core, with a fake store: product 1 (untranslated) sold via itself and via its EN version 11.
 *
 * @coversNothing
 */
final class TranslationWorkQueueTest extends WP_UnitTestCase {

	use RegistersAbilities;

	private const NAME = 'linguapilot/translation-work-queue';

	/**
	 * Fake catalog and sales.
	 *
	 * @var FakeWooGateway
	 */
	private FakeWooGateway $woo;

	/**
	 * Registers the ability with a fixed clock.
	 */
	public function set_up(): void {
		parent::set_up();

		$gateway   = ( new FakeMultilingualGateway() )->add_group(
			ElementType::product(),
			array(
				'de' => 1,
				'en' => 11,
			)
		);
		$this->woo = ( new FakeWooGateway() )
			->add_product( 1, 'Hoodie' )
			->set_analytics( array( new SalesRow( 1, 1, 10 ), new SalesRow( 11, 2, 40 ) ), 5 )
			->set_orders( 6 );

		$this->register_abilities(
			new TranslationWorkQueue(
				$gateway,
				$this->woo,
				new AuditLogger( new MemoryLogSink() ),
				new DependencyStatus( array() ),
				static fn (): DateTimeImmutable => new DateTimeImmutable( '2026-10-14 12:00:00', new DateTimeZone( 'UTC' ) )
			)
		);
	}

	/**
	 * Removes the ability.
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

	public function test_output_passes_core_validation_and_sums_language_versions(): void {
		$result = $this->run_as_shop_manager();

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( 1, $result['products'][0]['product_id'] );
		$this->assertSame( 50.0, (float) $result['products'][0]['revenue'] );
		$this->assertSame( 3, $result['products'][0]['units_sold'] );
		$this->assertSame( 'analytics', $result['meta']['data_source'] );
		$this->assertSame( 1, $result['meta']['analytics_missing_orders'] );
		$this->assertSame( 'EUR', $result['meta']['currency'] );
		$this->assertSame( '2026-07-16T12:00:00Z', $result['meta']['period_start'] );
		$this->assertSame( '2026-07-16 12:00:00', $this->woo->last_from );
	}

	/**
	 * Out-of-range inputs are rejected by core.
	 *
	 * @dataProvider invalid_inputs
	 *
	 * @param array<string, mixed> $input Input.
	 * @param string               $code  Expected error code.
	 */
	public function test_invalid_inputs( array $input, string $code ): void {
		$result = $this->run_as_shop_manager( $input );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	/**
	 * Invalid inputs and codes.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_inputs(): array {
		return array(
			'period too short' => array( array( 'period_days' => 6 ), 'ability_invalid_input' ),
			'period too long'  => array( array( 'period_days' => 366 ), 'ability_invalid_input' ),
			'limit too high'   => array( array( 'limit' => 51 ), 'ability_invalid_input' ),
			'unknown rank_by'  => array( array( 'rank_by' => 'margin' ), 'ability_invalid_input' ),
			'unknown language' => array( array( 'languages' => array( 'es' ) ), AbilityError::INVALID_LANGUAGE ),
		);
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
