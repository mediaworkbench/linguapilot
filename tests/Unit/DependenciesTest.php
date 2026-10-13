<?php
/**
 * Tests for the runtime dependency check.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit;

use MediaWorkbench\MultilingualStoreAbilities\Dependencies;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyProblem;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeEnvironment;

/**
 * Dependency rules: what blocks registration, what only degrades it.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Dependencies
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyProblem
 */
final class DependenciesTest extends TestCase {

	public function test_complete_stack_registers_abilities_with_wcml(): void {
		$status = ( new Dependencies( new FakeEnvironment() ) )->check();

		$this->assertTrue( $status->can_register_abilities() );
		$this->assertTrue( $status->has_wcml() );
		$this->assertSame( array(), $status->problems() );
	}

	public function test_missing_woocommerce_blocks_registration(): void {
		$status = ( new Dependencies( new FakeEnvironment( woocommerce_version: null ) ) )->check();

		$this->assertFalse( $status->can_register_abilities() );
		$this->assertSame( array( DependencyProblem::WOOCOMMERCE ), $this->blocking_ids( $status->problems() ) );
		$this->assertTrue( $status->problems()[0]->is_missing() );
	}

	public function test_outdated_woocommerce_blocks_registration_and_reports_versions(): void {
		$status = ( new Dependencies( new FakeEnvironment( woocommerce_version: '10.8.2' ) ) )->check();

		$this->assertFalse( $status->can_register_abilities() );
		$problem = $status->problems()[0];
		$this->assertFalse( $problem->is_missing() );
		$this->assertSame( '10.8.2', $problem->installed_version );
		$this->assertSame( Dependencies::MIN_WOOCOMMERCE, $problem->required_version );
	}

	public function test_minimum_woocommerce_version_is_accepted(): void {
		$status = ( new Dependencies( new FakeEnvironment( woocommerce_version: Dependencies::MIN_WOOCOMMERCE ) ) )->check();

		$this->assertTrue( $status->can_register_abilities() );
	}

	public function test_prerelease_of_minimum_woocommerce_version_is_rejected(): void {
		$status = ( new Dependencies( new FakeEnvironment( woocommerce_version: '10.9.0-beta.1' ) ) )->check();

		$this->assertFalse( $status->can_register_abilities() );
	}

	public function test_missing_abilities_api_blocks_registration(): void {
		$status = ( new Dependencies( new FakeEnvironment( abilities_api: false ) ) )->check();

		$this->assertFalse( $status->can_register_abilities() );
		$this->assertSame( array( DependencyProblem::ABILITIES_API ), $this->blocking_ids( $status->problems() ) );
	}

	public function test_missing_wpml_blocks_registration(): void {
		$status = ( new Dependencies( new FakeEnvironment( wpml_version: null ) ) )->check();

		$this->assertFalse( $status->can_register_abilities() );
		$this->assertSame( array( DependencyProblem::WPML ), $this->blocking_ids( $status->problems() ) );
	}

	public function test_missing_wcml_only_degrades_and_does_not_block(): void {
		$status = ( new Dependencies( new FakeEnvironment( wcml_version: null ) ) )->check();

		$this->assertTrue( $status->can_register_abilities() );
		$this->assertFalse( $status->has_wcml() );
		$this->assertCount( 1, $status->problems() );
		$this->assertSame( DependencyProblem::WCML, $status->problems()[0]->dependency );
		$this->assertFalse( $status->problems()[0]->blocking );
	}

	public function test_nothing_installed_reports_every_problem_in_stable_order(): void {
		$status = ( new Dependencies( new FakeEnvironment( false, null, null, null ) ) )->check();

		$this->assertFalse( $status->can_register_abilities() );
		$this->assertSame(
			array( DependencyProblem::ABILITIES_API, DependencyProblem::WOOCOMMERCE, DependencyProblem::WPML, DependencyProblem::WCML ),
			array_map( static fn ( DependencyProblem $problem ): string => $problem->dependency, $status->problems() )
		);
	}

	/**
	 * Returns the dependency IDs of all blocking problems.
	 *
	 * @param list<DependencyProblem> $problems Problems to filter.
	 * @return list<string>
	 */
	private function blocking_ids( array $problems ): array {
		return array_values(
			array_map(
				static fn ( DependencyProblem $problem ): string => $problem->dependency,
				array_filter( $problems, static fn ( DependencyProblem $problem ): bool => $problem->blocking )
			)
		);
	}
}
