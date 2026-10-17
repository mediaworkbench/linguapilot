<?php
/**
 * Integration tests for linguapilot/translation-completeness.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Integration;

use MediaWorkbench\LinguaPilot\Abilities\AbilityError;
use MediaWorkbench\LinguaPilot\Abilities\TranslationCompleteness;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Environment\DependencyStatus;
use MediaWorkbench\LinguaPilot\Support\AuditLogger;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\MemoryLogSink;
use WP_Error;
use WP_UnitTestCase;

/**
 * The real ability class through core, with a fake store (DE default, EN, FR).
 *
 * @coversNothing
 */
final class TranslationCompletenessTest extends WP_UnitTestCase {

	use RegistersAbilities;

	private const NAME = 'linguapilot/translation-completeness';

	/**
	 * Fake store.
	 *
	 * @var FakeMultilingualGateway
	 */
	private FakeMultilingualGateway $gateway;

	/**
	 * Registers the ability with a small fake catalog.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->gateway = ( new FakeMultilingualGateway() )
			->add_group(
				ElementType::product(),
				array(
					'de' => 1,
					'en' => 11,
				)
			)
			->add_group(
				ElementType::product(),
				array(
					'de' => 2,
					'en' => 12,
					'fr' => 22,
				)
			)
			->add_group( ElementType::product_category(), array( 'de' => 30 ) )
			->add_group(
				ElementType::taxonomy( 'pa_color' ),
				array(
					'de' => 40,
					'fr' => 41,
				)
			);

		$woo = ( new FakeWooGateway() )
			->add_product( 1, 'Hoodie', 'HD-1' )
			->add_product( 2, 'Beanie' )
			->add_term( 30, 'product_cat', 'Kleidung' )
			->add_term( 40, 'pa_color', 'Rot' );

		$this->register_abilities( new TranslationCompleteness( $this->gateway, $woo, new AuditLogger( new MemoryLogSink() ), new DependencyStatus( array() ) ) );
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

		$result = wp_get_ability( self::NAME )?->execute();

		$this->assertSame( $allowed, is_array( $result ) );
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

	public function test_default_call_reports_products_and_passes_core_output_validation(): void {
		$result = $this->run_as_shop_manager();

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( array( 1 ), array_column( $result['objects'], 'id' ) );
		$this->assertSame( array( 'fr' ), $result['objects'][0]['missing_languages'] );
		$this->assertSame( 2, $result['summary']['total_objects'] );
		$this->assertSame( 50, $result['per_page'] );
	}

	/**
	 * Every object type returns schema-valid output.
	 *
	 * @dataProvider object_types
	 *
	 * @param array<string, mixed> $input       Input.
	 * @param list<int>            $expected_ids Expected object IDs.
	 */
	public function test_object_types( array $input, array $expected_ids ): void {
		$result = $this->run_as_shop_manager( $input );

		$this->assertIsArray( $result, $result instanceof WP_Error ? $result->get_error_message() : '' );
		$this->assertSame( $expected_ids, array_column( $result['objects'], 'id' ) );
	}

	/**
	 * Inputs per object type.
	 *
	 * @return array<string, array{array<string, mixed>, list<int>}>
	 */
	public static function object_types(): array {
		return array(
			'categories'     => array( array( 'object_type' => 'product_cat' ), array( 30 ) ),
			'tags (none)'    => array( array( 'object_type' => 'product_tag' ), array() ),
			'all attributes' => array( array( 'object_type' => 'attribute_term' ), array( 40 ) ),
			'one attribute'  => array(
				array(
					'object_type' => 'attribute_term',
					'taxonomy'    => 'pa_size',
				),
				array(),
			),
			'french only'    => array( array( 'languages' => array( 'fr' ) ), array( 1 ) ),
			'english only'   => array( array( 'languages' => array( 'en' ) ), array() ),
		);
	}

	/**
	 * Unusable inputs return stable error codes.
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
	 * Invalid inputs and their error codes.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public static function invalid_inputs(): array {
		return array(
			'taxonomy without attribute_term' => array( array( 'taxonomy' => 'pa_color' ), AbilityError::INVALID_INPUT ),
			'unknown attribute taxonomy'      => array(
				array(
					'object_type' => 'attribute_term',
					'taxonomy'    => 'pa_material',
				),
				AbilityError::INVALID_INPUT,
			),
			'non-attribute taxonomy'          => array(
				array(
					'object_type' => 'attribute_term',
					'taxonomy'    => 'product_cat',
				),
				'ability_invalid_input',
			),
			'unknown language'                => array( array( 'languages' => array( 'es' ) ), AbilityError::INVALID_LANGUAGE ),
			'default language'                => array( array( 'languages' => array( 'de' ) ), AbilityError::INVALID_LANGUAGE ),
			'unknown object type'             => array( array( 'object_type' => 'order' ), 'ability_invalid_input' ),
			'per_page above 100'              => array( array( 'per_page' => 101 ), 'ability_invalid_input' ),
		);
	}

	public function test_untranslatable_categories_are_reported_as_invalid_input(): void {
		$this->gateway->set_untranslatable( ElementType::product_category() );

		$result = $this->run_as_shop_manager( array( 'object_type' => 'product_cat' ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( AbilityError::INVALID_INPUT, $result->get_error_code() );
	}

	public function test_untranslatable_attributes_are_skipped_when_auditing_all(): void {
		$this->gateway->set_untranslatable( ElementType::taxonomy( 'pa_color' ) );

		$result = $this->run_as_shop_manager( array( 'object_type' => 'attribute_term' ) );

		$this->assertIsArray( $result );
		$this->assertSame( array(), $result['objects'] );
	}

	/**
	 * Runs the ability as a shop manager.
	 *
	 * @param array<string, mixed>|null $input Input, or null for none.
	 * @return mixed
	 */
	private function run_as_shop_manager( ?array $input = null ) {
		wp_set_current_user( $this->create_user( 'shop_manager' ) );

		return wp_get_ability( self::NAME )?->execute( $input );
	}
}
