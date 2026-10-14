<?php
/**
 * WooCommerce catalog access contract.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogItem;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductAttribute;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TermRef;

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
	 * Raw values of the comparable product fields, keyed by post ID, then by ProductField value,
	 * plus `parent_id` (the parent product's ID for variations, otherwise "0").
	 *
	 * Read from the database directly: WCML filters price meta reads into the visitor's currency
	 * and may write while doing so (docs/api-notes.md §11). Unknown IDs are omitted.
	 *
	 * @param list<int> $ids Product or variation IDs.
	 * @return array<int, array<string, string|null>>
	 */
	public function product_fields( array $ids ): array;

	/**
	 * Which of the given products are variable products.
	 *
	 * @param list<int> $ids Product IDs.
	 * @return list<int>
	 */
	public function variable_product_ids( array $ids ): array;

	/**
	 * Attributes of each product, parsed from raw `_product_attributes` meta.
	 *
	 * @param list<int> $ids Product IDs.
	 * @return array<int, list<ProductAttribute>>
	 */
	public function product_attributes( array $ids ): array;

	/**
	 * Variation IDs of each parent (any status except trash), in menu order.
	 *
	 * @param list<int> $parent_ids Product IDs.
	 * @return array<int, list<int>>
	 */
	public function children( array $parent_ids ): array;

	/**
	 * Raw `attribute_*` meta of each variation.
	 *
	 * @param list<int> $variation_ids Variation IDs.
	 * @return array<int, array<string, string>>
	 */
	public function variation_attribute_values( array $variation_ids ): array;

	/**
	 * Terms of the given taxonomies assigned to each product.
	 *
	 * @param list<int>    $ids        Product IDs.
	 * @param list<string> $taxonomies Taxonomies.
	 * @return array<int, array<string, list<TermRef>>>
	 */
	public function object_terms( array $ids, array $taxonomies ): array;

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
