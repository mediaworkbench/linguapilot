<?php
/**
 * Integration tests for linguapilot/untranslated-variations.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Integration;

use MediaWorkbench\LinguaPilot\Abilities\AbilityError;
use MediaWorkbench\LinguaPilot\Abilities\UntranslatedVariations;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductAttribute;
use MediaWorkbench\LinguaPilot\Environment\DependencyStatus;
use MediaWorkbench\LinguaPilot\Support\AuditLogger;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\MemoryLogSink;
use WP_Error;
use WP_UnitTestCase;

/**
 * The real ability class through core, with a fake store: product 1 (variable) has an EN translation 2
 * missing variation 11; product 4 is simple.
 *
 * @coversNothing
 */
final class UntranslatedVariationsTest extends WP_UnitTestCase {

	use RegistersAbilities;

	private const NAME = 'linguapilot/untranslated-variations';

	/**
	 * Registers the ability.
	 */
	public function set_up(): void {
		parent::set_up();

		$gateway = ( new FakeMultilingualGateway() )
			->add_group(
				ElementType::product(),
				array(
					'de' => 1,
					'en' => 2,
				)
			)
			->add_group(
				ElementType::product(),
				array(
					'de' => 4,
					'en' => 40,
				)
			)
			->add_group(
				ElementType::variation(),
				array(
					'de' => 10,
					'en' => 20,
				)
			)
			->add_group( ElementType::variation(), array( 'de' => 11 ) );

		$size = new ProductAttribute( 'groesse', 'Grösse', false, true, array( 'S', 'M' ) );
		$woo  = ( new FakeWooGateway() )
			->add_product( 1, 'Shirt' )
			->add_product( 2, 'Shirt EN' )
			->set_variable_product( 1, array( $size ), array( 10, 11 ) )
			->set_variable_product( 2, array( new ProductAttribute( 'groesse', 'Grösse', false, true, array( 'S', 'M' ) ) ), array( 20 ) )
			->set_variation_values( 20, array( 'attribute_groesse' => 'S' ) );

		$this->register_abilities( new UntranslatedVariations( $gateway, $woo, new AuditLogger( new MemoryLogSink() ), new DependencyStatus( array() ) ) );
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

	public function test_output_passes_core_validation(): void {
		$result = $this->run_as_shop_manager();

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( array( 1 ), array_column( $result['products'], 'product_id' ) );
		$this->assertSame( 'variation_missing', $result['products'][0]['issues'][0]['type'] );
		$this->assertSame( 11, $result['products'][0]['issues'][0]['variation_id'] );
		$this->assertSame( 1, $result['summary']['products_checked'] );
	}

	public function test_product_id_of_a_translation_is_mapped_to_its_original(): void {
		$result = $this->run_as_shop_manager( array( 'product_id' => 2 ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 1 ), array_column( $result['products'], 'product_id' ) );
	}

	/**
	 * Unusable inputs.
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
			'unknown product'  => array( array( 'product_id' => 999 ), AbilityError::NOT_FOUND ),
			'simple product'   => array( array( 'product_id' => 4 ), AbilityError::INVALID_INPUT ),
			'zero product id'  => array( array( 'product_id' => 0 ), 'ability_invalid_input' ),
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
