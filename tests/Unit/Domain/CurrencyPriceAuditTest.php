<?php
/**
 * Tests for the manual-price gap audit.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Domain;

use MediaWorkbench\LinguaPilot\Domain\CurrencyPriceAudit;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Support\Pagination;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Secondary currencies CHF and USD. Manual prices:
 * - 1 simple: CHF 20, USD missing        → listed, USD
 * - 2 simple: CHF "", USD ""             → listed, CHF + USD
 * - 3 simple: CHF 5, USD 6               → complete, not listed
 * - 4 variable parent (flag set)         → skipped (no own price)
 *   - variation 40: CHF missing, USD 1   → listed under product 4
 *   - variation 41: no manual prices     → not checked
 * - 5 simple without manual prices       → not checked
 *
 * @covers \MediaWorkbench\LinguaPilot\Domain\CurrencyPriceAudit
 */
final class CurrencyPriceAuditTest extends TestCase {

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
			->add_group( $product, array( 'de' => 1 ) )
			->add_group( $product, array( 'de' => 2 ) )
			->add_group( $product, array( 'de' => 3 ) )
			->add_group( $product, array( 'de' => 4 ) )
			->add_group( $product, array( 'de' => 5 ) )
			->add_group( ElementType::variation(), array( 'de' => 40 ) )
			->add_group( ElementType::variation(), array( 'de' => 41 ) )
			->set_manual_prices( 1, array( 'CHF' => '20' ) )
			->set_manual_prices(
				2,
				array(
					'CHF' => '',
					'USD' => '',
				)
			)
			->set_manual_prices(
				3,
				array(
					'CHF' => '5',
					'USD' => '6',
				)
			)
			->set_manual_prices( 4, array() )
			->set_manual_prices( 40, array( 'USD' => '1' ) );

		$this->woo = ( new FakeWooGateway() )
			->add_product( 1, 'Mug', 'MUG' )
			->add_product( 2, 'Cap' )
			->add_product( 3, 'Scarf' )
			->add_product( 4, 'Shirt' )
			->add_variation( 40, 4, 'Shirt - Red', 'SH-R' )
			->set_variable_product( 4, array(), array( 40, 41 ) )
			->set_fields( 1, array( 'regular_price' => '18' ) )
			->set_fields( 2, array( 'regular_price' => null ) )
			->set_fields( 40, array( 'regular_price' => '25.50' ) );
	}

	public function test_lists_products_and_variations_with_missing_manual_prices(): void {
		$result = $this->audit();

		$this->assertSame(
			array(
				array(
					'product_id'         => 1,
					'variation_id'       => null,
					'title'              => 'Mug',
					'sku'                => 'MUG',
					'manual_pricing'     => true,
					'missing_currencies' => array( 'USD' ),
					'base_regular_price' => '18',
					'edit_url'           => 'https://shop.test/wp-admin/post.php?post=1&action=edit',
				),
				array(
					'product_id'         => 2,
					'variation_id'       => null,
					'title'              => 'Cap',
					'sku'                => null,
					'manual_pricing'     => true,
					'missing_currencies' => array( 'CHF', 'USD' ),
					'base_regular_price' => null,
					'edit_url'           => 'https://shop.test/wp-admin/post.php?post=2&action=edit',
				),
				array(
					'product_id'         => 4,
					'variation_id'       => 40,
					'title'              => 'Shirt - Red',
					'sku'                => 'SH-R',
					'manual_pricing'     => true,
					'missing_currencies' => array( 'CHF' ),
					'base_regular_price' => '25.50',
					'edit_url'           => 'https://shop.test/wp-admin/post.php?post=4&action=edit',
				),
			),
			$result['items']
		);
	}

	public function test_summary(): void {
		$this->assertSame(
			array(
				'currencies_checked'  => array( 'CHF', 'USD' ),
				'objects_checked'     => 4,
				'objects_with_gaps'   => 3,
				'missing_by_currency' => array(
					array(
						'currency' => 'CHF',
						'missing'  => 2,
					),
					array(
						'currency' => 'USD',
						'missing'  => 2,
					),
				),
			),
			$this->audit()['summary']
		);
	}

	public function test_currency_subset(): void {
		$result = $this->audit( array( 'CHF' ) );

		$this->assertSame( array( array( 2, null ), array( 4, 40 ) ), array_map( static fn ( array $item ): array => array( $item['product_id'], $item['variation_id'] ), $result['items'] ) );
		$this->assertSame(
			array(
				array(
					'currency' => 'CHF',
					'missing'  => 2,
				),
			),
			$result['summary']['missing_by_currency']
		);
	}

	public function test_variations_can_be_excluded(): void {
		$result = $this->audit( null, false );

		$this->assertSame( array( 1, 2 ), array_column( $result['items'], 'product_id' ) );
		$this->assertSame( 3, $result['summary']['objects_checked'] );
	}

	public function test_pagination(): void {
		$page = $this->audit(
			null,
			true,
			Pagination::from_input(
				array(
					'page'     => 2,
					'per_page' => 2,
				)
			)
		);

		$this->assertSame( 3, $page['total_items'] );
		$this->assertSame( array( 40 ), array_column( $page['items'], 'variation_id' ) );
	}

	public function test_no_currencies_means_nothing_to_check(): void {
		$result = $this->audit( array() );

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 0, $result['summary']['objects_checked'] );
	}

	/**
	 * Runs the audit.
	 *
	 * @param list<string>|null $currencies         Currencies; default CHF, USD.
	 * @param bool              $include_variations Whether to include variations.
	 * @param Pagination|null   $pagination         Pagination.
	 * @return array<string, mixed>
	 */
	private function audit( ?array $currencies = null, bool $include_variations = true, ?Pagination $pagination = null ): array {
		return ( new CurrencyPriceAudit( $this->gateway, $this->woo ) )->audit( $currencies ?? array( 'CHF', 'USD' ), $include_variations, $pagination ?? Pagination::from_input( array() ) );
	}
}
