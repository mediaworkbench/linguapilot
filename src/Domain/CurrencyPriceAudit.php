<?php
/**
 * Manual-price gap audit.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogScope;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Gateway\MultilingualGateway;
use MediaWorkbench\LinguaPilot\Gateway\WooGateway;
use MediaWorkbench\LinguaPilot\Support\Pagination;

/**
 * Finds products and variations with WCML "set prices manually" enabled but no regular price in a
 * secondary currency. WCML then silently falls back to exchange-rate conversion.
 *
 * Rule (Patrick, 2026-10-14): a currency is missing when `_regular_price_{CUR}` on the original is
 * empty or absent. Variable parents are skipped: their prices live on the variations.
 */
final class CurrencyPriceAudit {

	/**
	 * Objects whose data is fetched per batch.
	 */
	private const BATCH_SIZE = 200;

	/**
	 * Creates the service.
	 *
	 * @param MultilingualGateway $gateway Multilingual plugin access.
	 * @param WooGateway          $woo     Catalog access.
	 */
	public function __construct(
		private readonly MultilingualGateway $gateway,
		private readonly WooGateway $woo,
	) {}

	/**
	 * Audits manual prices.
	 *
	 * @param list<string> $currencies         Secondary currency codes to check (validated by the caller).
	 * @param bool         $include_variations Whether to check variations.
	 * @param Pagination   $pagination         Page of objects with gaps.
	 * @return array{
	 *     items: list<array{product_id: int, variation_id: int|null, title: string, sku: string|null, manual_pricing: true, missing_currencies: list<string>, base_regular_price: string|null, edit_url: string}>,
	 *     total_items: int,
	 *     summary: array{currencies_checked: list<string>, objects_checked: int, objects_with_gaps: int, missing_by_currency: list<array{currency: string, missing: int}>}
	 * }
	 */
	public function audit( array $currencies, bool $include_variations, Pagination $pagination ): array {
		$products   = array();
		$variations = array();
		if ( array() !== $currencies ) {
			$flagged  = $this->gateway->manual_price_originals( new CatalogScope( array( ElementType::product() ), CatalogScope::ANY_POST_STATUS ) );
			$variable = array_flip( $this->woo->variable_product_ids( $flagged ) );
			$products = array_values( array_filter( $flagged, static fn ( int $id ): bool => ! isset( $variable[ $id ] ) ) );
			if ( $include_variations ) {
				$variations = $this->gateway->manual_price_originals( new CatalogScope( array( ElementType::variation() ), CatalogScope::ANY_POST_STATUS ) );
			}
		}

		$missing_by_currency = array_fill_keys( $currencies, 0 );
		$found               = array();
		foreach ( array_chunk( array_merge( $products, $variations ), self::BATCH_SIZE ) as $batch ) {
			$prices = $this->gateway->manual_regular_prices( $batch, $currencies );
			$fields = $this->woo->product_fields( $batch );

			foreach ( $batch as $id ) {
				$missing = array_values( array_filter( $currencies, static fn ( string $currency ): bool => null === ( $prices[ $id ][ $currency ] ?? null ) ) );
				if ( array() === $missing ) {
					continue;
				}

				foreach ( $missing as $currency ) {
					++$missing_by_currency[ $currency ];
				}

				$is_variation = in_array( $id, $variations, true );
				$parent       = (int) ( $fields[ $id ]['parent_id'] ?? 0 );
				$found[]      = array(
					'id'                 => $id,
					'product_id'         => $is_variation ? $parent : $id,
					'variation_id'       => $is_variation ? $id : null,
					'missing_currencies' => $missing,
					'base_regular_price' => FieldComparator::display( $fields[ $id ]['regular_price'] ?? null ),
				);
			}
		}

		usort( $found, static fn ( array $a, array $b ): int => array( $a['product_id'], $a['variation_id'] ?? 0 ) <=> array( $b['product_id'], $b['variation_id'] ?? 0 ) );

		$page  = array_slice( $found, $pagination->offset(), $pagination->per_page );
		$items = $this->woo->describe_posts( array_map( static fn ( array $entry ): int => $entry['id'], $page ) );

		$result = array();
		foreach ( $page as $entry ) {
			$item     = $items[ $entry['id'] ] ?? null;
			$result[] = array(
				'product_id'         => $entry['product_id'],
				'variation_id'       => $entry['variation_id'],
				'title'              => null === $item ? '' : $item->title,
				'sku'                => null === $item ? null : $item->sku,
				'manual_pricing'     => true,
				'missing_currencies' => $entry['missing_currencies'],
				'base_regular_price' => $entry['base_regular_price'],
				'edit_url'           => null === $item ? '' : $item->edit_url,
			);
		}

		return array(
			'items'       => $result,
			'total_items' => count( $found ),
			'summary'     => array(
				'currencies_checked'  => $currencies,
				'objects_checked'     => count( $products ) + count( $variations ),
				'objects_with_gaps'   => count( $found ),
				'missing_by_currency' => array_map(
					static fn ( string $currency, int $missing ): array => array(
						'currency' => $currency,
						'missing'  => $missing,
					),
					array_keys( $missing_by_currency ),
					array_values( $missing_by_currency )
				),
			),
		);
	}
}
