<?php
/**
 * Tests for translation state and coverage models.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Domain;

use InvalidArgumentException;
use MediaWorkbench\LinguaPilot\Domain\Model\CatalogScope;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\LanguageCoverage;
use MediaWorkbench\LinguaPilot\Domain\Model\TranslationState;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * The status rule every coverage number depends on.
 *
 * @covers \MediaWorkbench\LinguaPilot\Domain\Model\TranslationState
 * @covers \MediaWorkbench\LinguaPilot\Domain\Model\CatalogScope
 * @covers \MediaWorkbench\LinguaPilot\Domain\Model\LanguageCoverage
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

	/**
	 * Full classification including translation jobs.
	 *
	 * @dataProvider classifications
	 *
	 * @param bool             $is_term        Whether the element is a term.
	 * @param bool             $element_exists Whether the translated post/term exists.
	 * @param string|null      $post_status    Post status of the translation.
	 * @param int|null         $job_status     WPML translation job status.
	 * @param TranslationState $expected       Expected state.
	 */
	public function test_classification_with_jobs( bool $is_term, bool $element_exists, ?string $post_status, ?int $job_status, TranslationState $expected ): void {
		$this->assertSame( $expected, TranslationState::classify( $is_term, $element_exists, $post_status, $job_status ) );
	}

	/**
	 * Classification cases.
	 *
	 * @return array<string, array{bool, bool, string|null, int|null, TranslationState}>
	 */
	public static function classifications(): array {
		return array(
			'waiting for translator'      => array( false, false, null, 1, TranslationState::InProgress ),
			'in progress'                 => array( false, false, null, 2, TranslationState::InProgress ),
			'ready to download'           => array( false, false, null, 4, TranslationState::InProgress ),
			'needs review'                => array( false, false, null, 30, TranslationState::InProgress ),
			'ATE retry'                   => array( false, false, null, 40, TranslationState::InProgress ),
			'pending translation service' => array( false, false, null, 102, TranslationState::InProgress ),
			'cancelled job'               => array( false, false, null, 42, TranslationState::Missing ),
			'no job'                      => array( false, false, null, null, TranslationState::Missing ),
			'not translated'              => array( false, false, null, 0, TranslationState::Missing ),
			'published, job complete'     => array( false, true, 'publish', 10, TranslationState::Translated ),
			'draft with open job'         => array( false, true, 'draft', 2, TranslationState::Unpublished ),
			'trashed'                     => array( false, true, 'trash', 10, TranslationState::Missing ),
			'term exists'                 => array( true, true, null, null, TranslationState::Translated ),
			'term job open'               => array( true, false, null, 1, TranslationState::InProgress ),
		);
	}

	public function test_coverage_percentage(): void {
		$this->assertSame( 33.3, ( new LanguageCoverage( 'fr', 1, 1, 1 ) )->coverage_pct() );
		$this->assertSame( 100.0, ( new LanguageCoverage( 'fr', 0, 0, 0 ) )->coverage_pct() );
		$this->assertSame( 3, ( new LanguageCoverage( 'fr', 1, 1, 1 ) )->total() );
		$this->assertSame( 4, ( new LanguageCoverage( 'fr', 1, 1, 1, 1, 1 ) )->total(), 'in progress counts, outdated is a subset of translated/unpublished' );
		$this->assertSame( 25.0, ( new LanguageCoverage( 'fr', 1, 1, 1, 1, 1 ) )->coverage_pct() );
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
