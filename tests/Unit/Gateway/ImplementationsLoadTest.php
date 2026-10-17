<?php
/**
 * Guards that every production implementation fulfils its contract.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Gateway;

use MediaWorkbench\LinguaPilot\Environment\RuntimeEnvironment;
use MediaWorkbench\LinguaPilot\Gateway\WooCommerceGateway;
use MediaWorkbench\LinguaPilot\Gateway\WpmlGateway;
use MediaWorkbench\LinguaPilot\Support\WooCommerceLogSink;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;
use ReflectionClass;

/**
 * These classes are only used on a real site, never by other unit tests. Loading them here makes
 * a missing interface method fail the unit suite instead of fataling the site.
 *
 * @coversNothing
 */
final class ImplementationsLoadTest extends TestCase {

	/**
	 * Every production implementation class.
	 *
	 * @dataProvider implementations
	 *
	 * @param class-string $class_name Class.
	 */
	public function test_class_is_instantiable( string $class_name ): void {
		$this->assertTrue( ( new ReflectionClass( $class_name ) )->isInstantiable() );
	}

	/**
	 * Implementation classes.
	 *
	 * @return array<string, array{class-string}>
	 */
	public static function implementations(): array {
		return array(
			'WpmlGateway'        => array( WpmlGateway::class ),
			'WooCommerceGateway' => array( WooCommerceGateway::class ),
			'RuntimeEnvironment' => array( RuntimeEnvironment::class ),
			'WooCommerceLogSink' => array( WooCommerceLogSink::class ),
		);
	}
}
