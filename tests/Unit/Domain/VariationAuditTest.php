<?php
/**
 * Tests for the variation audit.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Domain;

use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductAttribute;
use MediaWorkbench\LinguaPilot\Domain\Model\TermRef;
use MediaWorkbench\LinguaPilot\Domain\VariationAudit;
use MediaWorkbench\LinguaPilot\Support\Pagination;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Store (DE default, EN, FR):
 *
 * Product 1 "Shirt" (variable): pa_color (terms 100 rot, 101 blau) + local "Grösse" S | M | L; variations 10 (rot/S), 11 (blau/M).
 * - EN 2: Grösse identical (review); label "Size" set; term 101 untranslated (error); variation 11 untranslated (error).
 * - FR 3: Grösse "P | M" (mismatch, error); no FR label (review); variation 30 has Grösse "X" (value invalid, error).
 * Product 4 is simple (ignored). Product 5 is variable and clean in EN (not listed).
 *
 * @covers \MediaWorkbench\LinguaPilot\Domain\VariationAudit
 * @covers \MediaWorkbench\LinguaPilot\Domain\Model\ProductAttribute
 */
final class VariationAuditTest extends TestCase {

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

		$product   = ElementType::product();
		$variation = ElementType::variation();
		$color     = ElementType::taxonomy( 'pa_color' );

		$this->gateway = ( new FakeMultilingualGateway() )
			->add_group(
				$product,
				array(
					'de' => 1,
					'en' => 2,
					'fr' => 3,
				)
			)
			->add_group(
				$product,
				array(
					'de' => 4,
					'en' => 40,
				)
			)
			->add_group(
				$product,
				array(
					'de' => 5,
					'en' => 50,
				)
			)
			->add_group(
				$variation,
				array(
					'de' => 10,
					'en' => 20,
					'fr' => 30,
				)
			)
			->add_group(
				$variation,
				array(
					'de' => 11,
					'fr' => 31,
				)
			)
			->add_group(
				$color,
				array(
					'de' => 100,
					'en' => 110,
					'fr' => 120,
				)
			)
			->add_group(
				$color,
				array(
					'de' => 101,
					'fr' => 121,
				)
			)
			->set_attribute_labels( 2, array( 'en' => array( 'groesse' => 'Size' ) ) );

		$size = static fn ( string $options ): ProductAttribute => new ProductAttribute( 'groesse', 'Grösse', false, true, ProductAttribute::split_options( $options ) );
		$pa   = new ProductAttribute( 'pa_color', 'pa_color', true, true );

		$this->woo = ( new FakeWooGateway() )
			->add_product( 1, 'Shirt' )
			->add_product( 2, 'Shirt EN' )
			->add_product( 3, 'Shirt FR' )
			->add_product( 5, 'Plain' )
			->add_product( 50, 'Plain EN' )
			->set_variable_product( 1, array( $pa, $size( 'S | M | L' ) ), array( 10, 11 ), array( 'pa_color' => array( new TermRef( 100, 'rot', 'Rot' ), new TermRef( 101, 'blau', 'Blau' ) ) ) )
			->set_variable_product( 2, array( $pa, $size( 'S | M | L' ) ), array( 20 ), array( 'pa_color' => array( new TermRef( 110, 'red', 'Red' ) ) ) )
			->set_variable_product( 3, array( $pa, $size( 'P | M' ) ), array( 30, 31 ), array( 'pa_color' => array( new TermRef( 120, 'rouge', 'Rouge' ), new TermRef( 121, 'bleu', 'Bleu' ) ) ) )
			->set_variable_product( 5, array() )
			->set_variable_product( 50, array() )
			->set_variation_values(
				20,
				array(
					'attribute_pa_color' => 'red',
					'attribute_groesse'  => 'S',
				)
			)
			->set_variation_values(
				30,
				array(
					'attribute_pa_color' => 'rouge',
					'attribute_groesse'  => 'X',
				)
			)
			->set_variation_values(
				31,
				array(
					'attribute_pa_color' => 'bleu',
					'attribute_groesse'  => 'M',
				)
			);
	}

	public function test_reports_every_issue_type_per_product_and_language(): void {
		$result = $this->audit();

		$this->assertSame( array( array( 1, 'en', 2 ), array( 1, 'fr', 3 ) ), array_map( static fn ( array $item ): array => array( $item['product_id'], $item['language'], $item['translation_id'] ), $result['items'] ) );

		$this->assertSame(
			array(
				array(
					'type'         => 'variation_missing',
					'severity'     => 'error',
					'variation_id' => 11,
				),
				array(
					'type'      => 'attribute_term_untranslated',
					'severity'  => 'error',
					'taxonomy'  => 'pa_color',
					'term_id'   => 101,
					'term_name' => 'Blau',
				),
				array(
					'type'           => 'local_attribute_identical',
					'severity'       => 'review',
					'attribute'      => 'Grösse',
					'original_value' => 'S | M | L',
				),
			),
			$result['items'][0]['issues']
		);

		$this->assertSame(
			array(
				array(
					'type'              => 'local_attribute_options_mismatch',
					'severity'          => 'error',
					'attribute'         => 'Grösse',
					'original_value'    => 'S | M | L',
					'translation_value' => 'P | M',
				),
				array(
					'type'      => 'local_attribute_label_untranslated',
					'severity'  => 'review',
					'attribute' => 'Grösse',
				),
				array(
					'type'              => 'variation_attribute_value_invalid',
					'severity'          => 'error',
					'variation_id'      => 10,
					'attribute'         => 'Grösse',
					'translation_value' => 'X',
				),
			),
			$result['items'][1]['issues']
		);
	}

	public function test_summary(): void {
		$this->assertSame(
			array(
				'products_checked'     => 2,
				'translations_checked' => 3,
				'errors'               => 4,
				'reviews'              => 2,
			),
			$this->audit()['summary']
		);
	}

	public function test_missing_local_attribute_on_the_translation(): void {
		$this->woo->set_variable_product( 2, array( new ProductAttribute( 'pa_color', 'pa_color', true, true ) ), array( 20 ), array( 'pa_color' => array( new TermRef( 110, 'red', 'Red' ) ) ) );

		$issues = $this->audit( array( 'en' ) )['items'][0]['issues'];

		$this->assertContains(
			array(
				'type'      => 'local_attribute_missing',
				'severity'  => 'error',
				'attribute' => 'Grösse',
			),
			$issues
		);
		$this->assertNotContains( 'variation_attribute_value_invalid', array_column( $issues, 'type' ), 'no value check without the attribute' );
	}

	public function test_global_term_value_must_be_a_term_assigned_to_the_translated_parent(): void {
		$this->woo->set_variation_values(
			20,
			array(
				'attribute_pa_color' => 'rot',
				'attribute_groesse'  => 'S',
			)
		);

		$issues = $this->audit( array( 'en' ) )['items'][0]['issues'];

		$this->assertContains(
			array(
				'type'              => 'variation_attribute_value_invalid',
				'severity'          => 'error',
				'variation_id'      => 10,
				'attribute'         => 'pa_color',
				'translation_value' => 'rot',
			),
			$issues
		);
	}

	public function test_any_value_and_non_variation_attributes_are_not_checked(): void {
		$this->woo
			->set_variation_values(
				30,
				array(
					'attribute_pa_color' => '',
					'attribute_groesse'  => '',
				)
			)
			->set_variable_product( 3, array( new ProductAttribute( 'pa_color', 'pa_color', true, true ), new ProductAttribute( 'groesse', 'Grösse', false, false, array( 'P', 'M' ) ) ), array( 30, 31 ), array( 'pa_color' => array( new TermRef( 120, 'rouge', 'Rouge' ), new TermRef( 121, 'bleu', 'Bleu' ) ) ) )
			->set_variable_product( 1, array( new ProductAttribute( 'pa_color', 'pa_color', true, true ), new ProductAttribute( 'groesse', 'Grösse', false, false, array( 'S', 'M', 'L' ) ) ), array( 10, 11 ), array( 'pa_color' => array( new TermRef( 100, 'rot', 'Rot' ), new TermRef( 101, 'blau', 'Blau' ) ) ) );

		$this->assertSame( array(), $this->audit( array( 'fr' ) )['items'] );
	}

	public function test_untranslatable_attribute_taxonomies_are_skipped(): void {
		$this->gateway->set_untranslatable( ElementType::taxonomy( 'pa_color' ) );

		$types = array_column( $this->audit( array( 'en' ) )['items'][0]['issues'], 'type' );

		$this->assertNotContains( 'attribute_term_untranslated', $types );
	}

	public function test_single_product_filter_and_pagination(): void {
		$this->assertSame( array(), $this->audit( null, 5 )['items'] );
		$this->assertSame( 2, $this->audit( null, 1 )['total_items'] );

		$page2 = $this->audit(
			null,
			null,
			Pagination::from_input(
				array(
					'page'     => 2,
					'per_page' => 1,
				)
			)
		);
		$this->assertSame( array( 'fr' ), array_column( $page2['items'], 'language' ) );
	}

	/**
	 * Runs the audit.
	 *
	 * @param list<string>|null $languages  Languages; default EN, FR.
	 * @param int|null          $product_id Limit to one original product.
	 * @param Pagination|null   $pagination Pagination.
	 * @return array<string, mixed>
	 */
	private function audit( ?array $languages = null, ?int $product_id = null, ?Pagination $pagination = null ): array {
		return ( new VariationAudit( $this->gateway, $this->woo ) )->audit( $languages ?? array( 'en', 'fr' ), $product_id, $pagination ?? Pagination::from_input( array() ) );
	}
}
