<?php
/**
 * Sales-ranked translation work queue.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoverageRow;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SalesRow;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooGateway;

/**
 * Ranks products that are not translated into every requested language by their sales.
 *
 * Sales come from WooCommerce Analytics (with the same excluded order statuses), or from order
 * items when Analytics is unavailable or empty while orders exist (decision 2026-10-14). Orders
 * placed in a translated shop reference the translated product ID, so every sold ID is mapped to
 * its original before summing; otherwise sales would be split across language versions.
 */
final class SalesRanking {

	public const SOURCE_ANALYTICS = 'analytics';
	public const SOURCE_ORDERS    = 'orders';

	/**
	 * Originals whose translation states are fetched per batch.
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Creates the service.
	 *
	 * @param MultilingualGateway $gateway Multilingual plugin access.
	 * @param WooGateway          $woo     Catalog and sales access.
	 */
	public function __construct(
		private readonly MultilingualGateway $gateway,
		private readonly WooGateway $woo,
	) {}

	/**
	 * Builds the queue.
	 *
	 * @param list<string>      $languages   Translation languages (validated by the caller).
	 * @param int               $period_days Days back from $now (clamped by the caller).
	 * @param string            $rank_by     `revenue` or `units`.
	 * @param int               $limit       Maximum number of products (clamped by the caller).
	 * @param DateTimeImmutable $now         Current time.
	 * @return array{
	 *     products: list<array{rank: int, product_id: int, title: string, sku: string|null, revenue: float, units_sold: int, missing_languages: list<string>, unpublished_languages: list<string>, in_progress_languages: list<string>, edit_url: string}>,
	 *     summary: array{products_with_sales: int, untranslated_with_sales: int, revenue_untranslated: float, revenue_total: float},
	 *     data_source: string,
	 *     analytics_missing_orders: int,
	 *     period_start: string
	 * }
	 */
	public function queue( array $languages, int $period_days, string $rank_by, int $limit, DateTimeImmutable $now ): array {
		$start    = $now->setTimezone( new DateTimeZone( 'UTC' ) )->sub( new DateInterval( 'P' . max( 1, $period_days ) . 'D' ) );
		$from     = $start->format( 'Y-m-d H:i:s' );
		$excluded = $this->woo->excluded_report_statuses();

		$orders    = $this->woo->order_count( $from, $excluded );
		$analytics = $this->woo->analytics_order_count( $from, $excluded );

		if ( null === $analytics || ( 0 === $analytics && $orders > 0 ) ) {
			$source  = self::SOURCE_ORDERS;
			$rows    = $this->woo->sales_from_orders( $from, $excluded );
			$missing = 0;
		} else {
			$source  = self::SOURCE_ANALYTICS;
			$rows    = $this->woo->sales_from_analytics( $from, $excluded );
			$missing = max( 0, $orders - $analytics );
		}

		$totals = $this->totals_by_original( $rows );
		uksort(
			$totals,
			static function ( int $a, int $b ) use ( $totals, $rank_by ): int {
				$primary   = 'units' === $rank_by ? 'units' : 'revenue';
				$secondary = 'units' === $rank_by ? 'revenue' : 'units';
				return array( $totals[ $b ][ $primary ], $totals[ $b ][ $secondary ], $a ) <=> array( $totals[ $a ][ $primary ], $totals[ $a ][ $secondary ], $b );
			}
		);

		$candidates   = array();
		$revenue_open = 0.0;
		foreach ( array_chunk( array_keys( $totals ), self::BATCH_SIZE ) as $batch ) {
			$rows_by_id = array();
			foreach ( $this->gateway->coverage_of( $batch, ElementType::product(), $languages ) as $row ) {
				$rows_by_id[ $row->original_id ] = $row;
			}

			foreach ( $batch as $id ) {
				$row = $rows_by_id[ $id ] ?? null;
				if ( null === $row || count( $row->languages_in( TranslationState::Translated ) ) === count( $languages ) ) {
					continue;
				}
				$revenue_open += $totals[ $id ]['revenue'];
				$candidates[]  = $row;
			}
		}

		$selected = array_slice( $candidates, 0, max( 1, $limit ) );
		$items    = $this->woo->describe_posts( array_map( static fn ( CoverageRow $row ): int => $row->original_id, $selected ) );

		$products = array();
		foreach ( $selected as $index => $row ) {
			$item       = $items[ $row->original_id ] ?? null;
			$products[] = array(
				'rank'                  => $index + 1,
				'product_id'            => $row->original_id,
				'title'                 => null === $item ? '' : $item->title,
				'sku'                   => null === $item ? null : $item->sku,
				'revenue'               => round( $totals[ $row->original_id ]['revenue'], 2 ),
				'units_sold'            => (int) round( $totals[ $row->original_id ]['units'] ),
				'missing_languages'     => $row->languages_in( TranslationState::Missing ),
				'unpublished_languages' => $row->languages_in( TranslationState::Unpublished ),
				'in_progress_languages' => $row->languages_in( TranslationState::InProgress ),
				'edit_url'              => null === $item ? '' : $item->edit_url,
			);
		}

		return array(
			'products'                 => $products,
			'summary'                  => array(
				'products_with_sales'     => count( $totals ),
				'untranslated_with_sales' => count( $candidates ),
				'revenue_untranslated'    => round( $revenue_open, 2 ),
				'revenue_total'           => round( array_sum( array_column( $totals, 'revenue' ) ), 2 ),
			),
			'data_source'              => $source,
			'analytics_missing_orders' => $missing,
			'period_start'             => $start->format( 'Y-m-d\TH:i:s\Z' ),
		);
	}

	/**
	 * Sums sales per original product. Sold IDs without a language (e.g. deleted products) are ignored.
	 *
	 * @param list<SalesRow> $rows Sales as recorded.
	 * @return array<int, array{units: float, revenue: float}>
	 */
	private function totals_by_original( array $rows ): array {
		$originals = $this->gateway->originals_of( array_values( array_unique( array_map( static fn ( SalesRow $row ): int => $row->product_id, $rows ) ) ), ElementType::product() );

		$totals = array();
		foreach ( $rows as $row ) {
			$original = $originals[ $row->product_id ] ?? null;
			if ( null === $original ) {
				continue;
			}
			$totals[ $original ]           ??= array(
				'units'   => 0.0,
				'revenue' => 0.0,
			);
			$totals[ $original ]['units']   += $row->units;
			$totals[ $original ]['revenue'] += $row->revenue;
		}

		return $totals;
	}
}
