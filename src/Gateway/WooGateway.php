<?php
/**
 * WooCommerce catalog access contract.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Gateway;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogItem;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductAttribute;
use MediaWorkbench\LinguaPilot\Domain\Model\SalesRow;
use MediaWorkbench\LinguaPilot\Domain\Model\TermRef;

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
	 * and may write while doing so. Unknown IDs are omitted.
	 *
	 * @param list<int> $ids Product or variation IDs.
	 * @return array<int, array<string, string|null>>
	 */
	public function product_fields( array $ids ): array;

	/**
	 * Order statuses WooCommerce Analytics excludes, as stored (`wc-` prefix), mirroring
	 * `Automattic\WooCommerce\Admin\API\Reports\DataStore::get_excluded_report_order_statuses()` (WC 11.2).
	 *
	 * @return list<string>
	 */
	public function excluded_report_statuses(): array;

	/**
	 * Orders (not refunds) created since a time, in the authoritative order table.
	 *
	 * @param string       $from_gmt          Period start, `Y-m-d H:i:s` UTC.
	 * @param list<string> $excluded_statuses Statuses to exclude (`wc-` prefixed).
	 */
	public function order_count( string $from_gmt, array $excluded_statuses ): int;

	/**
	 * Orders (not refunds) since a time in WooCommerce Analytics, or null when its tables are unavailable.
	 *
	 * @param string       $from_gmt          Period start, `Y-m-d H:i:s` UTC.
	 * @param list<string> $excluded_statuses Statuses to exclude.
	 */
	public function analytics_order_count( string $from_gmt, array $excluded_statuses ): ?int;

	/**
	 * Units and net revenue per product (parent product for variations) from the Analytics lookup table, refunds included.
	 *
	 * @param string       $from_gmt          Period start, `Y-m-d H:i:s` UTC.
	 * @param list<string> $excluded_statuses Statuses to exclude.
	 * @return list<SalesRow>
	 */
	public function sales_from_analytics( string $from_gmt, array $excluded_statuses ): array;

	/**
	 * Units and line totals per product from order line items (fallback when Analytics is unavailable; refunds not subtracted).
	 *
	 * @param string       $from_gmt          Period start, `Y-m-d H:i:s` UTC.
	 * @param list<string> $excluded_statuses Statuses to exclude.
	 * @return list<SalesRow>
	 */
	public function sales_from_orders( string $from_gmt, array $excluded_statuses ): array;

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
