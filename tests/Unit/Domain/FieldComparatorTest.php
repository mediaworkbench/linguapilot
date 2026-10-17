<?php
/**
 * Tests for comparing product field values across languages.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Domain;

use MediaWorkbench\LinguaPilot\Domain\FieldComparator;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductField;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Raw meta values differ in form more often than in meaning; only real differences count.
 *
 * @covers \MediaWorkbench\LinguaPilot\Domain\FieldComparator
 */
final class FieldComparatorTest extends TestCase {

	/**
	 * Value pairs and whether they are equal.
	 *
	 * @dataProvider cases
	 *
	 * @param ProductField $field       Field.
	 * @param string|null  $original    Original value.
	 * @param string|null  $translation Translation value.
	 * @param bool         $equal       Expected result.
	 */
	public function test_equality( ProductField $field, ?string $original, ?string $translation, bool $equal ): void {
		$this->assertSame( $equal, FieldComparator::equal( $field, $original, $translation ) );
	}

	/**
	 * Comparison cases.
	 *
	 * @return array<string, array{ProductField, string|null, string|null, bool}>
	 */
	public static function cases(): array {
		return array(
			'same sku'                       => array( ProductField::Sku, 'HD-1', 'HD-1', true ),
			'different sku'                  => array( ProductField::Sku, 'HD-1', 'HD-2', false ),
			'sku case matters'               => array( ProductField::Sku, 'hd-1', 'HD-1', false ),
			'empty vs missing'               => array( ProductField::Sku, '', null, true ),
			'empty vs value'                 => array( ProductField::Sku, '', 'HD-1', false ),
			'price formatting'               => array( ProductField::RegularPrice, '10', '10.00', true ),
			'different price'                => array( ProductField::RegularPrice, '10', '10.5', false ),
			'sale price removed'             => array( ProductField::SalePrice, '8', '', false ),
			'stock as float string'          => array( ProductField::StockQuantity, '5', '5.000000', true ),
			'different stock'                => array( ProductField::StockQuantity, '5', '4', false ),
			'standard tax class spelled out' => array( ProductField::TaxClass, '', 'standard', true ),
			'other tax class'                => array( ProductField::TaxClass, '', 'reduced-rate', false ),
			'status'                         => array( ProductField::PostStatus, 'publish', 'draft', false ),
			'non-numeric price text'         => array( ProductField::RegularPrice, 'abc', 'abc', true ),
		);
	}

	public function test_stock_quantity_only_counts_when_stock_is_managed(): void {
		$this->assertTrue( FieldComparator::applies( ProductField::StockQuantity, array( 'manage_stock' => 'yes' ), array( 'manage_stock' => 'no' ) ) );
		$this->assertFalse( FieldComparator::applies( ProductField::StockQuantity, array( 'manage_stock' => 'no' ), array( 'manage_stock' => null ) ) );
		$this->assertTrue( FieldComparator::applies( ProductField::Sku, array(), array() ) );
	}
}
