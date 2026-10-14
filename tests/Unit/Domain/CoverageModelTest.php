<?php
/**
 * Tests for translation state and coverage models.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Domain;

use InvalidArgumentException;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\LanguageCoverage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * The status rule every coverage number depends on.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\Model\LanguageCoverage
 */
final class CoverageModelTest extends TestCase {

	/**
	 * Post status → state.
	 *
	 * @dataProvider statuses
	 *
	 * @param string|null      $status   Post status.
	 * @param TranslationState $expected Expected state.
	 */
	public function test_post_status_classification( ?string $status, TranslationState $expected ): void {
		$this->assertSame( $expected, TranslationState::from_post_status( $status ) );
	}

	/**
	 * Post statuses and their states.
	 *
	 * @return array<string, array{string|null, TranslationState}>
	 */
	public static function statuses(): array {
		return array(
			'published'      => array( 'publish', TranslationState::Translated ),
			'no translation' => array( null, TranslationState::Missing ),
			'trashed'        => array( 'trash', TranslationState::Missing ),
			'auto-draft'     => array( 'auto-draft', TranslationState::Missing ),
			'draft'          => array( 'draft', TranslationState::Unpublished ),
			'pending'        => array( 'pending', TranslationState::Unpublished ),
			'private'        => array( 'private', TranslationState::Unpublished ),
			'scheduled'      => array( 'future', TranslationState::Unpublished ),
			'custom status'  => array( 'wc-custom', TranslationState::Unpublished ),
		);
	}

	public function test_coverage_percentage(): void {
		$this->assertSame( 33.3, ( new LanguageCoverage( 'fr', 1, 1, 1 ) )->coverage_pct() );
		$this->assertSame( 100.0, ( new LanguageCoverage( 'fr', 0, 0, 0 ) )->coverage_pct() );
		$this->assertSame( 3, ( new LanguageCoverage( 'fr', 1, 1, 1 ) )->total() );
	}

	public function test_scope_accepts_one_post_type_or_several_taxonomies(): void {
		$this->assertFalse( ( new CatalogScope( array( ElementType::product() ) ) )->is_terms() );
		$this->assertTrue( ( new CatalogScope( array( ElementType::taxonomy( 'pa_color' ), ElementType::taxonomy( 'pa_size' ) ) ) )->is_terms() );
	}

	/**
	 * Invalid scopes.
	 *
	 * @dataProvider invalid_scopes
	 *
	 * @param list<ElementType> $types Types.
	 */
	public function test_scope_rejects_invalid_type_sets( array $types ): void {
		$this->expectException( InvalidArgumentException::class );
		new CatalogScope( $types );
	}

	/**
	 * Invalid type sets.
	 *
	 * @return array<string, array{list<ElementType>}>
	 */
	public static function invalid_scopes(): array {
		return array(
			'empty'             => array( array() ),
			'two post types'    => array( array( ElementType::product(), ElementType::variation() ) ),
			'posts mixed terms' => array( array( ElementType::product(), ElementType::product_category() ) ),
		);
	}
}
