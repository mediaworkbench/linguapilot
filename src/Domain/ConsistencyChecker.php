<?php
/**
 * Cross-language consistency service.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogScope;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductField;
use MediaWorkbench\LinguaPilot\Domain\Model\SyncSettings;
use MediaWorkbench\LinguaPilot\Domain\Model\TranslationPair;
use MediaWorkbench\LinguaPilot\Gateway\MultilingualGateway;
use MediaWorkbench\LinguaPilot\Gateway\WooGateway;
use MediaWorkbench\LinguaPilot\Support\Pagination;

/**
 * Compares product fields between each original and its translations.
 *
 * A difference in a field the multilingual plugin syncs is a `sync_error` (data corruption or a
 * failed sync); in any other field a `deviation` (possibly intentional). Translations that are
 * missing or trashed are not compared; that is translation-completeness' job.
 */
final class ConsistencyChecker {

	public const SYNC_ERROR = 'sync_error';
	public const DEVIATION  = 'deviation';

	/**
	 * Originals whose field values are fetched per batch.
	 */
	private const BATCH_SIZE = 200;

	private const PRICE_FIELDS = array( ProductField::RegularPrice, ProductField::SalePrice );

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
	 * Checks every translated product and, optionally, every translated variation.
	 *
	 * Scans all pairs (field values are fetched in batches) so that pagination counts only pairs
	 * with differences. Results are ordered by parent product, variation (product row first), language.
	 *
	 * @param list<ProductField> $fields             Fields to compare.
	 * @param list<string>       $languages          Translation languages (validated by the caller).
	 * @param Pagination         $pagination         Page of pairs with differences.
	 * @param bool               $include_variations Whether to compare variations too.
	 * @return array{
	 *     items: list<array{product_id: int, variation_id: int|null, title: string, sku: string|null, language: string, translation_id: int, edit_url: string, differences: list<array{field: string, original: string|null, translation: string|null, severity: string}>}>,
	 *     total_items: int,
	 *     summary: array{products_checked: int, variations_checked: int, translations_checked: int, sync_errors: int, deviations: int, price_checks_skipped: int}
	 * }
	 */
	public function check( array $fields, array $languages, Pagination $pagination, bool $include_variations = true ): array {
		$product_pairs   = $this->pairs( ElementType::product(), $languages );
		$variation_pairs = $include_variations ? $this->pairs( ElementType::variation(), $languages ) : array();
		$sync            = $this->gateway->sync_settings();
		$prices          = array() !== array_filter( $fields, static fn ( ProductField $field ): bool => in_array( $field, self::PRICE_FIELDS, true ) );
		// Variations have no catalog visibility of their own.
		$variation_fields = array_values( array_filter( $fields, static fn ( ProductField $field ): bool => ProductField::CatalogVisibility !== $field ) );

		$counts = array(
			'skipped' => 0,
			'errors'  => 0,
			'devs'    => 0,
		);
		$found  = array_merge(
			$this->compare_all( $product_pairs, $fields, $sync, $prices, false, $counts ),
			$this->compare_all( $variation_pairs, $variation_fields, $sync, $prices, true, $counts )
		);

		$order = array_flip( $languages );
		usort(
			$found,
			static fn ( array $a, array $b ): int => array( $a['product_id'], $a['variation_id'] ?? 0, $order[ $a['pair']->language ] ?? PHP_INT_MAX )
				<=> array( $b['product_id'], $b['variation_id'] ?? 0, $order[ $b['pair']->language ] ?? PHP_INT_MAX )
		);

		$page = array_slice( $found, $pagination->offset(), $pagination->per_page );
		$ids  = array();
		foreach ( $page as $entry ) {
			$ids[] = $entry['pair']->original_id;
			$ids[] = $entry['pair']->translation_id;
		}
		$items = $this->woo->describe_posts( array_values( array_unique( $ids ) ) );

		$result = array();
		foreach ( $page as $entry ) {
			$pair        = $entry['pair'];
			$item        = $items[ $pair->original_id ] ?? null;
			$translation = $items[ $pair->translation_id ] ?? null;
			$result[]    = array(
				'product_id'     => $entry['product_id'],
				'variation_id'   => $entry['variation_id'],
				'title'          => null === $item ? '' : $item->title,
				'sku'            => null === $item ? null : $item->sku,
				'language'       => $pair->language,
				'translation_id' => $pair->translation_id,
				'edit_url'       => null === $translation ? '' : $translation->edit_url,
				'differences'    => $entry['differences'],
			);
		}

		return array(
			'items'       => $result,
			'total_items' => count( $found ),
			'summary'     => array(
				'products_checked'     => self::count_originals( $product_pairs ),
				'variations_checked'   => self::count_originals( $variation_pairs ),
				'translations_checked' => count( $product_pairs ) + count( $variation_pairs ),
				'sync_errors'          => $counts['errors'],
				'deviations'           => $counts['devs'],
				'price_checks_skipped' => $counts['skipped'],
			),
		);
	}

	/**
	 * Translation pairs of one element type (originals in any status except trash).
	 *
	 * @param ElementType  $type      Product or variation.
	 * @param list<string> $languages Languages.
	 * @return list<TranslationPair>
	 */
	private function pairs( ElementType $type, array $languages ): array {
		return array() === $languages ? array() : $this->gateway->translation_pairs( new CatalogScope( array( $type ), CatalogScope::ANY_POST_STATUS ), $languages );
	}

	/**
	 * Compares all pairs in batches and returns those with differences.
	 *
	 * @param list<TranslationPair>                       $pairs        Pairs.
	 * @param list<ProductField>                          $fields       Fields to compare.
	 * @param SyncSettings                                $sync         Sync settings.
	 * @param bool                                        $prices       Whether price fields are among the fields.
	 * @param bool                                        $is_variation Whether the pairs are variations.
	 * @param array{skipped: int, errors: int, devs: int} $counts      Running counters (updated).
	 * @return list<array{pair: TranslationPair, product_id: int, variation_id: int|null, differences: list<array{field: string, original: string|null, translation: string|null, severity: string}>}>
	 */
	private function compare_all( array $pairs, array $fields, SyncSettings $sync, bool $prices, bool $is_variation, array &$counts ): array {
		$found = array();

		foreach ( array_chunk( $pairs, self::BATCH_SIZE ) as $batch ) {
			$ids = array();
			foreach ( $batch as $pair ) {
				$ids[] = $pair->original_id;
				$ids[] = $pair->translation_id;
			}
			$values = $this->woo->product_fields( array_values( array_unique( $ids ) ) );

			foreach ( $batch as $pair ) {
				$skip_prices = $prices && $this->has_manual_prices_for_language( $pair );
				if ( $skip_prices ) {
					++$counts['skipped'];
				}

				$differences = $this->differences( $pair, $fields, $values, $sync, $skip_prices );
				if ( array() === $differences ) {
					continue;
				}

				foreach ( $differences as $difference ) {
					self::SYNC_ERROR === $difference['severity'] ? ++$counts['errors'] : ++$counts['devs'];
				}

				$found[] = array(
					'pair'         => $pair,
					'product_id'   => $is_variation ? (int) ( $values[ $pair->original_id ]['parent_id'] ?? 0 ) : $pair->original_id,
					'variation_id' => $is_variation ? $pair->original_id : null,
					'differences'  => $differences,
				);
			}
		}

		return $found;
	}

	/**
	 * Number of distinct originals among pairs.
	 *
	 * @param list<TranslationPair> $pairs Pairs.
	 */
	private static function count_originals( array $pairs ): int {
		return count( array_unique( array_map( static fn ( TranslationPair $pair ): int => $pair->original_id, $pairs ) ) );
	}

	/**
	 * Differences between an original and one translation.
	 *
	 * @param TranslationPair                        $pair        Pair.
	 * @param list<ProductField>                     $fields      Fields to compare.
	 * @param array<int, array<string, string|null>> $values      Field values by post ID.
	 * @param SyncSettings                           $sync        Sync settings.
	 * @param bool                                   $skip_prices Whether price fields are skipped for this pair.
	 * @return list<array{field: string, original: string|null, translation: string|null, severity: string}>
	 */
	private function differences( TranslationPair $pair, array $fields, array $values, SyncSettings $sync, bool $skip_prices ): array {
		$original    = $values[ $pair->original_id ] ?? array();
		$translation = $values[ $pair->translation_id ] ?? array();
		$differences = array();

		foreach ( $fields as $field ) {
			if ( ( $skip_prices && in_array( $field, self::PRICE_FIELDS, true ) ) || ! FieldComparator::applies( $field, $original, $translation ) ) {
				continue;
			}

			$a = $original[ $field->value ] ?? null;
			$b = $translation[ $field->value ] ?? null;
			if ( ! FieldComparator::equal( $field, $a, $b ) ) {
				$differences[] = array(
					'field'       => $field->value,
					'original'    => FieldComparator::display( $a ),
					'translation' => FieldComparator::display( $b ),
					'severity'    => $sync->is_synced( $field ) ? self::SYNC_ERROR : self::DEVIATION,
				);
			}
		}

		return $differences;
	}

	/**
	 * Whether the translation's language uses a secondary currency for which the original has a manual price
	 * (decision 2026-10-14: price comparison is then currency-price-gaps' job).
	 *
	 * @param TranslationPair $pair Pair.
	 */
	private function has_manual_prices_for_language( TranslationPair $pair ): bool {
		$currencies = $this->gateway->currencies();
		if ( ! $currencies->enabled || ! $this->gateway->has_manual_prices( $pair->original_id ) ) {
			return false;
		}

		foreach ( $currencies->currencies as $currency ) {
			if (
				$currency->code !== $currencies->default_currency
				&& in_array( $pair->language, $currency->languages, true )
				&& null !== $this->gateway->manual_regular_price( $pair->original_id, $currency->code )
			) {
				return true;
			}
		}

		return false;
	}
}
