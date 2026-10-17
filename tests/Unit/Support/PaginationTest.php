<?php
/**
 * Tests for pagination clamping.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Support;

use Brain\Monkey\Functions;
use MediaWorkbench\LinguaPilot\Support\Pagination;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Output is always bounded: per_page 1-100, page >= 1, whatever the input.
 *
 * @covers \MediaWorkbench\LinguaPilot\Support\Pagination
 */
final class PaginationTest extends TestCase {

	public function test_defaults(): void {
		$pagination = Pagination::from_input( array() );

		$this->assertSame( 1, $pagination->page );
		$this->assertSame( Pagination::DEFAULT_PER_PAGE, $pagination->per_page );
		$this->assertSame( 0, $pagination->offset() );
	}

	/**
	 * Out-of-range and non-numeric values are clamped, never rejected.
	 *
	 * @dataProvider clamping_cases
	 *
	 * @param array<string, mixed> $input         Raw input.
	 * @param int                  $page          Expected page.
	 * @param int                  $per_page      Expected page size.
	 */
	public function test_clamping( array $input, int $page, int $per_page ): void {
		$pagination = Pagination::from_input( $input );

		$this->assertSame( $page, $pagination->page );
		$this->assertSame( $per_page, $pagination->per_page );
	}

	/**
	 * Clamping cases.
	 *
	 * @return array<string, array{array<string, mixed>, int, int}>
	 */
	public static function clamping_cases(): array {
		return array(
			'per_page above max'   => array( array( 'per_page' => 5000 ), 1, 100 ),
			'per_page zero'        => array( array( 'per_page' => 0 ), 1, 1 ),
			'negative page'        => array( array( 'page' => -3 ), 1, 50 ),
			'numeric strings'      => array(
				array(
					'page'     => '3',
					'per_page' => '20',
				),
				3,
				20,
			),
			'garbage'              => array(
				array(
					'page'     => 'x',
					'per_page' => array(),
				),
				1,
				50,
			),
			'floats are truncated' => array(
				array(
					'page'     => 2.9,
					'per_page' => 10.5,
				),
				2,
				10,
			),
		);
	}

	public function test_offset_and_total_pages(): void {
		$pagination = Pagination::from_input(
			array(
				'page'     => 3,
				'per_page' => 25,
			)
		);

		$this->assertSame( 50, $pagination->offset() );
		$this->assertSame( 0, $pagination->total_pages( 0 ) );
		$this->assertSame( 1, $pagination->total_pages( 25 ) );
		$this->assertSame( 2, $pagination->total_pages( 26 ) );
	}

	public function test_custom_default_page_size_is_still_capped(): void {
		$this->assertSame( 20, Pagination::from_input( array(), 20 )->per_page );
		$this->assertSame( 100, Pagination::from_input( array(), 500 )->per_page );
	}

	public function test_input_properties_declare_bounds(): void {
		Functions\stubTranslationFunctions();
		$properties = Pagination::input_properties( 20 );

		$this->assertSame( 1, $properties['page']['minimum'] );
		$this->assertSame( 1, $properties['page']['default'] );
		$this->assertSame( 100, $properties['per_page']['maximum'] );
		$this->assertSame( 20, $properties['per_page']['default'] );
	}
}
