<?php
/**
 * Tests for translation completeness.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Domain;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\TranslationAudit;
use MediaWorkbench\MultilingualStoreAbilities\Support\Pagination;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * Which originals lack translations, and coverage per language.
 *
 * Store (DE default, EN, FR):
 * - 1 "Hoodie":   EN published, FR draft         → FR unpublished
 * - 2 "Beanie":   EN + FR published              → complete, not listed
 * - 3 "Cap":      no translations                → EN + FR missing
 * - 4 "Draft":    draft original, EN published   → only with status "any": FR missing
 * - 5 "Scarf":    EN published, FR in the trash  → FR missing
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\TranslationAudit
 */
final class TranslationAuditTest extends TestCase {

	/**
	 * Fake store.
	 *
	 * @var FakeMultilingualGateway
	 */
	private FakeMultilingualGateway $gateway;

	/**
	 * Fake catalog.
	 *
	 * @var FakeWooGateway
	 */
	private FakeWooGateway $woo;

	/**
	 * Builds the store described in the class doc.
	 */
	protected function set_up(): void {
		parent::set_up();

		$product       = ElementType::product();
		$this->gateway = ( new FakeMultilingualGateway() )
			->add_group(
				$product,
				array(
					'de' => 1,
					'en' => 11,
					'fr' => 21,
				),
				null,
				array( 'fr' => 'draft' )
			)
			->add_group(
				$product,
				array(
					'de' => 2,
					'en' => 12,
					'fr' => 22,
				)
			)
			->add_group( $product, array( 'de' => 3 ) )
			->add_group(
				$product,
				array(
					'de' => 4,
					'en' => 14,
				),
				null,
				array( 'de' => 'draft' )
			)
			->add_group(
				$product,
				array(
					'de' => 5,
					'en' => 15,
					'fr' => 25,
				),
				null,
				array( 'fr' => 'trash' )
			);

		$this->woo = ( new FakeWooGateway() )
			->add_product( 1, 'Hoodie', 'HD-1' )
			->add_product( 2, 'Beanie' )
			->add_product( 3, 'Cap' )
			->add_product( 4, 'Draft' )
			->add_product( 5, 'Scarf', 'SC-5' );
	}

	public function test_lists_only_incomplete_published_originals_with_missing_and_unpublished_languages(): void {
		$result = $this->audit( new CatalogScope( array( ElementType::product() ) ), array( 'en', 'fr' ) );

		$this->assertSame( 3, $result['total_items'] );
		$this->assertSame(
			array(
				array(
					'id'                    => 1,
					'type'                  => 'product',
					'title'                 => 'Hoodie',
					'sku'                   => 'HD-1',
					'missing_languages'     => array(),
					'unpublished_languages' => array( 'fr' ),
					'in_progress_languages' => array(),
					'outdated_languages'    => array(),
					'edit_url'              => 'https://shop.test/wp-admin/post.php?post=1&action=edit',
				),
				array(
					'id'                    => 3,
					'type'                  => 'product',
					'title'                 => 'Cap',
					'sku'                   => null,
					'missing_languages'     => array( 'en', 'fr' ),
					'unpublished_languages' => array(),
					'in_progress_languages' => array(),
					'outdated_languages'    => array(),
					'edit_url'              => 'https://shop.test/wp-admin/post.php?post=3&action=edit',
				),
			),
			array_slice( $result['objects'], 0, 2 )
		);
		$this->assertSame( array( 'fr' ), $result['objects'][2]['missing_languages'], 'a trashed translation is missing' );
	}

	public function test_summary_covers_all_originals_in_scope(): void {
		$summary = $this->audit( new CatalogScope( array( ElementType::product() ) ), array( 'en', 'fr' ) )['summary'];

		$this->assertSame( 4, $summary['total_objects'] );
		$this->assertSame(
			array(
				array(
					'language'     => 'en',
					'translated'   => 3,
					'unpublished'  => 0,
					'in_progress'  => 0,
					'missing'      => 1,
					'outdated'     => 0,
					'coverage_pct' => 75.0,
				),
				array(
					'language'     => 'fr',
					'translated'   => 1,
					'unpublished'  => 1,
					'in_progress'  => 0,
					'missing'      => 2,
					'outdated'     => 0,
					'coverage_pct' => 25.0,
				),
			),
			$summary['languages']
		);
	}

	public function test_open_translation_jobs_are_in_progress_not_missing(): void {
		$this->gateway->add_job( ElementType::product(), 3, 'fr' );

		$result = $this->audit( new CatalogScope( array( ElementType::product() ) ), array( 'en', 'fr' ) );
		$cap    = $result['objects'][1];

		$this->assertSame( 3, $cap['id'] );
		$this->assertSame( array( 'en' ), $cap['missing_languages'] );
		$this->assertSame( array( 'fr' ), $cap['in_progress_languages'] );
		$this->assertSame( 1, $result['summary']['languages'][1]['in_progress'] );
		$this->assertSame( 1, $result['summary']['languages'][1]['missing'] );
	}

	public function test_outdated_translations_are_listed_even_when_otherwise_complete(): void {
		$this->gateway->mark_outdated( ElementType::product(), 22 );

		$result = $this->audit( new CatalogScope( array( ElementType::product() ) ), array( 'en', 'fr' ) );
		$beanie = array_values( array_filter( $result['objects'], static fn ( array $item ): bool => 2 === $item['id'] ) );

		$this->assertCount( 1, $beanie, 'complete but outdated product is listed' );
		$this->assertSame( array( 'fr' ), $beanie[0]['outdated_languages'] );
		$this->assertSame( array(), $beanie[0]['missing_languages'] );
		$this->assertSame( 1, $result['summary']['languages'][1]['outdated'] );
		$this->assertSame( 1, $result['summary']['languages'][1]['translated'], 'outdated still counts as translated (only product 2 has a published FR translation)' );
	}

	public function test_status_any_includes_unpublished_originals(): void {
		$result = $this->audit( new CatalogScope( array( ElementType::product() ), CatalogScope::ANY_POST_STATUS ), array( 'en', 'fr' ) );

		$this->assertSame( 5, $result['summary']['total_objects'] );
		$this->assertContains( 4, array_column( $result['objects'], 'id' ) );
	}

	public function test_language_subset(): void {
		$result = $this->audit( new CatalogScope( array( ElementType::product() ) ), array( 'en' ) );

		$this->assertSame( array( 3 ), array_column( $result['objects'], 'id' ) );
		$this->assertSame( array( 'en' ), array_column( $result['summary']['languages'], 'language' ) );
	}

	public function test_pagination_pages_through_incomplete_originals(): void {
		$scope = new CatalogScope( array( ElementType::product() ) );

		$page2 = $this->audit(
			$scope,
			array( 'en', 'fr' ),
			Pagination::from_input(
				array(
					'page'     => 2,
					'per_page' => 2,
				)
			)
		);

		$this->assertSame( array( 5 ), array_column( $page2['objects'], 'id' ) );
		$this->assertSame( 3, $page2['total_items'] );
	}

	public function test_attribute_terms_across_taxonomies_report_their_taxonomy(): void {
		$color = ElementType::taxonomy( 'pa_color' );
		$size  = ElementType::taxonomy( 'pa_size' );
		$this->gateway
			->add_group(
				$color,
				array(
					'de' => 40,
					'en' => 41,
					'fr' => 42,
				)
			)
			->add_group(
				$color,
				array(
					'de' => 43,
					'en' => 44,
				)
			)
			->add_group( $size, array( 'de' => 50 ) );
		$this->woo
			->add_term( 40, 'pa_color', 'Rot' )
			->add_term( 43, 'pa_color', 'Blau' )
			->add_term( 50, 'pa_size', 'Gross' );

		$result = $this->audit( new CatalogScope( array( $color, $size ) ), array( 'en', 'fr' ) );

		$this->assertSame( 3, $result['summary']['total_objects'] );
		$this->assertSame(
			array(
				array( 43, 'pa_color', array( 'fr' ) ),
				array( 50, 'pa_size', array( 'en', 'fr' ) ),
			),
			array_map( static fn ( array $item ): array => array( $item['id'], $item['type'], $item['missing_languages'] ), $result['objects'] )
		);
		$this->assertSame( array(), $result['objects'][0]['unpublished_languages'], 'terms have no unpublished state' );
	}

	public function test_no_target_languages_means_nothing_to_report(): void {
		$result = $this->audit( new CatalogScope( array( ElementType::product() ) ), array() );

		$this->assertSame( array(), $result['objects'] );
		$this->assertSame( 0, $result['total_items'] );
		$this->assertSame( array(), $result['summary']['languages'] );
	}

	/**
	 * Runs the audit.
	 *
	 * @param CatalogScope    $scope      Scope.
	 * @param list<string>    $languages  Languages.
	 * @param Pagination|null $pagination Pagination; default first page of 50.
	 * @return array<string, mixed>
	 */
	private function audit( CatalogScope $scope, array $languages, ?Pagination $pagination = null ): array {
		return ( new TranslationAudit( $this->gateway, $this->woo ) )->completeness( $scope, $languages, $pagination ?? Pagination::from_input( array() ) );
	}
}
