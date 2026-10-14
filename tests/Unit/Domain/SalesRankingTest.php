<?php
/**
 * Tests for the sales-ranked translation queue.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Domain;

use DateTimeImmutable;
use DateTimeZone;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SalesRow;
use MediaWorkbench\MultilingualStoreAbilities\Domain\SalesRanking;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeWooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * Store (DE default, EN, FR) and Analytics sales in the period:
 * - 1 Hoodie: EN translated, FR missing             — sold 2 units / 50
 * - 2 Beanie: EN + FR translated                    — sold 10 / 100, all via its EN version 12 (complete → not queued)
 * - 3 Cap:    no translations                       — sold 5 / 30
 * - 4 Scarf:  EN 14 draft, FR missing               — sold 1 / 20 directly + 1 / 80 via EN version 14 → 2 / 100
 * - 5 Sock:   EN translated, FR sent to translation — sold 7 / 7
 * - 99:       deleted product (no language)         — sold 3 / 300, ignored
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\SalesRanking
 */
final class SalesRankingTest extends TestCase {

	/**
	 * Fake store.
	 *
	 * @var FakeMultilingualGateway
	 */
	private FakeMultilingualGateway $gateway;

	/**
	 * Fake catalog and sales.
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
				)
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
				array( 'en' => 'draft' )
			)
			->add_group(
				$product,
				array(
					'de' => 5,
					'en' => 15,
				)
			)
			->add_job( $product, 5, 'fr' );

		$this->woo = ( new FakeWooGateway() )
			->add_product( 1, 'Hoodie', 'HD' )
			->add_product( 3, 'Cap' )
			->add_product( 4, 'Scarf' )
			->add_product( 5, 'Sock' )
			->set_analytics(
				array(
					new SalesRow( 1, 2, 50 ),
					new SalesRow( 12, 10, 100 ),
					new SalesRow( 3, 5, 30 ),
					new SalesRow( 4, 1, 20 ),
					new SalesRow( 14, 1, 80 ),
					new SalesRow( 5, 7, 7 ),
					new SalesRow( 99, 3, 300 ),
				),
				40
			)
			->set_orders( 42 );
	}

	public function test_ranks_untranslated_products_by_revenue_with_sales_normalized_to_originals(): void {
		$result = $this->queue();

		$this->assertSame(
			array(
				array( 1, 4, 100.0, 2, array( 'fr' ), array( 'en' ), array() ),
				array( 2, 1, 50.0, 2, array( 'fr' ), array(), array() ),
				array( 3, 3, 30.0, 5, array( 'en', 'fr' ), array(), array() ),
				array( 4, 5, 7.0, 7, array(), array(), array( 'fr' ) ),
			),
			array_map(
				static fn ( array $item ): array => array( $item['rank'], $item['product_id'], $item['revenue'], $item['units_sold'], $item['missing_languages'], $item['unpublished_languages'], $item['in_progress_languages'] ),
				$result['products']
			)
		);
		$this->assertSame( 'Scarf', $result['products'][0]['title'] );
	}

	public function test_rank_by_units_breaks_ties_by_revenue(): void {
		$this->assertSame( array( 5, 3, 4, 1 ), array_column( $this->queue( array( 'en', 'fr' ), 90, 'units' )['products'], 'product_id' ) );
	}

	public function test_limit_and_language_subset(): void {
		$this->assertSame( array( 4, 1 ), array_column( $this->queue( array( 'en', 'fr' ), 90, 'revenue', 2 )['products'], 'product_id' ) );
		$this->assertSame( array( 4, 3 ), array_column( $this->queue( array( 'en' ) )['products'], 'product_id' ) );
	}

	public function test_summary_and_source_metadata(): void {
		$result = $this->queue();

		$this->assertSame(
			array(
				'products_with_sales'     => 5,
				'untranslated_with_sales' => 4,
				'revenue_untranslated'    => 187.0,
				'revenue_total'           => 287.0,
			),
			$result['summary']
		);
		$this->assertSame( 'analytics', $result['data_source'] );
		$this->assertSame( 2, $result['analytics_missing_orders'], '42 orders exist, Analytics has 40' );
		$this->assertSame( '2026-07-16T08:30:00Z', $result['period_start'] );
		$this->assertSame( '2026-07-16 08:30:00', $this->woo->last_from );
	}

	public function test_falls_back_to_order_items_when_analytics_is_empty_but_orders_exist(): void {
		$this->woo->set_analytics( array(), 0 )->set_orders( 3, array( new SalesRow( 3, 1, 9 ) ) );

		$result = $this->queue();

		$this->assertSame( 'orders', $result['data_source'] );
		$this->assertSame( 0, $result['analytics_missing_orders'] );
		$this->assertSame( array( 3 ), array_column( $result['products'], 'product_id' ) );
	}

	public function test_falls_back_when_analytics_tables_are_unavailable(): void {
		$this->woo->set_analytics( null, null )->set_orders( 3, array( new SalesRow( 1, 1, 9 ) ) );

		$this->assertSame( 'orders', $this->queue()['data_source'] );
	}

	public function test_no_orders_at_all_uses_analytics_and_returns_nothing(): void {
		$this->woo->set_analytics( array(), 0 )->set_orders( 0 );

		$result = $this->queue();

		$this->assertSame( 'analytics', $result['data_source'] );
		$this->assertSame( array(), $result['products'] );
	}

	/**
	 * Builds the queue at a fixed time.
	 *
	 * @param list<string> $languages   Languages.
	 * @param int          $period_days Period.
	 * @param string       $rank_by     revenue|units.
	 * @param int          $limit       Limit.
	 * @return array<string, mixed>
	 */
	private function queue( array $languages = array( 'en', 'fr' ), int $period_days = 90, string $rank_by = 'revenue', int $limit = 20 ): array {
		return ( new SalesRanking( $this->gateway, $this->woo ) )->queue(
			$languages,
			$period_days,
			$rank_by,
			$limit,
			new DateTimeImmutable( '2026-10-14 08:30:00', new DateTimeZone( 'UTC' ) )
		);
	}
}
