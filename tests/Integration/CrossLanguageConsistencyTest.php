<?php
/**
 * Integration tests for mlstore/cross-language-consistency.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Integration;

use MediaWorkbench\MultilingualStoreAbilities\Abilities\AbilityError;
use MediaWorkbench\MultilingualStoreAbilities\Abilities\CrossLanguageConsistency;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\MemoryLogSink;
use WP_Error;
use WP_UnitTestCase;

/**
 * The real ability class through core, with a fake store (DE default, EN, FR).
 *
 * @coversNothing
 */
final class CrossLanguageConsistencyTest extends WP_UnitTestCase {

	use RegistersAbilities;

	private const NAME = 'mlstore/cross-language-consistency';

	/**
	 * Registers the ability: product 1 has an EN SKU sync error and an FR price deviation.
	 */
	public function set_up(): void {
		parent::set_up();

		$gateway = ( new FakeMultilingualGateway() )
			->add_group(
				ElementType::product(),
				array(
					'de' => 1,
					'en' => 11,
					'fr' => 21,
				)
			)
			->add_group(
				ElementType::variation(),
				array(
					'de' => 5,
					'en' => 15,
				)
			)
			->set_sync_settings( new SyncSettings( array( ProductField::Sku ) ) );

		$base = array(
			'sku'           => 'X',
			'post_status'   => 'publish',
			'regular_price' => '20',
		);
		$woo  = ( new FakeWooGateway() )
			->add_product( 1, 'Hoodie', 'X' )
			->add_product( 11, 'Hoodie EN' )
			->add_product( 21, 'Hoodie FR' )
			->set_fields( 1, $base )
			->set_fields( 11, array( 'sku' => 'Y' ) + $base )
			->set_fields( 21, array( 'regular_price' => '25' ) + $base )
			->add_variation( 5, 1, 'Hoodie - Red' )
			->add_variation( 15, 11, 'Hoodie EN - Red' )
			->set_fields( 5, $base )
			->set_fields( 15, array( 'sku' => 'Z' ) + $base );

		$this->register_abilities( new CrossLanguageConsistency( $gateway, $woo, new AuditLogger( new MemoryLogSink() ), new DependencyStatus( array() ) ) );
	}

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

	public function test_output_passes_core_validation_with_sync_aware_severities(): void {
		$result = $this->run_as_shop_manager();

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( array( array( null, 'en' ), array( null, 'fr' ), array( 5, 'en' ) ), array_map( static fn ( array $row ): array => array( $row['variation_id'], $row['language'] ), $result['translations'] ) );
		$this->assertSame( 1, $result['translations'][2]['product_id'], 'variation rows point to the original parent' );
		$this->assertSame( 'sync_error', $result['translations'][0]['differences'][0]['severity'] );
		$this->assertSame( 'deviation', $result['translations'][1]['differences'][0]['severity'] );
		$this->assertSame( 2, $result['summary']['sync_errors'] );
		$this->assertSame( 1, $result['summary']['variations_checked'] );
	}

	public function test_variations_can_be_excluded(): void {
		$result = $this->run_as_shop_manager( array( 'include_variations' => false ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( null ), array_values( array_unique( array_column( $result['translations'], 'variation_id' ) ) ) );
	}

	public function test_field_filter(): void {
		$result = $this->run_as_shop_manager( array( 'fields' => array( 'regular_price' ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( array( 'fr' ), array_column( $result['translations'], 'language' ) );
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
			'unknown field'    => array( array( 'fields' => array( 'weight' ) ), 'ability_invalid_input' ),
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
