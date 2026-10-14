<?php
/**
 * WooCommerce catalog access contract.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogItem;

/**
 * Read-only access to WooCommerce catalog data. Never calls the multilingual plugin.
 */
interface WooGateway {

	/**
	 * Slugs of all global attribute taxonomies (`pa_*`).
	 *
	 * @return list<string>
	 */
	public function attribute_taxonomies(): array;

	/**
	 * Raw values of the comparable product fields, keyed by post ID, then by ProductField value.
	 *
	 * Read from the database directly: WCML filters price meta reads into the visitor's currency
	 * and may write while doing so (docs/api-notes.md §11). Unknown IDs are omitted.
	 *
	 * @param list<int> $ids Product or variation IDs.
	 * @return array<int, array<string, string|null>>
	 */
	public function product_fields( array $ids ): array;

	/**
	 * Display data for posts (products), keyed by ID. Unknown IDs are omitted.
	 *
	 * @param list<int> $ids Post IDs.
	 * @return array<int, CatalogItem>
	 */
	public function describe_posts( array $ids ): array;

	/**
	 * Display data for terms, keyed by term ID. Unknown IDs are omitted.
	 *
	 * @param list<int> $ids Term IDs.
	 * @return array<int, CatalogItem>
	 */
	public function describe_terms( array $ids ): array;
}
