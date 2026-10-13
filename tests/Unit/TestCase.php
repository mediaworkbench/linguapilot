<?php
/**
 * Base class for unit tests.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit;

use Brain\Monkey;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

/**
 * Sets up and tears down Brain Monkey around every unit test.
 */
abstract class TestCase extends PolyfillTestCase {

	/**
	 * Sets up Brain Monkey.
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
	}

	/**
	 * Tears down Brain Monkey.
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
