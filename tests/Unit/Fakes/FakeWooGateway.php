<?php
/**
 * In-memory WooCommerce gateway for unit tests.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Fakes;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogItem;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductAttribute;
use MediaWorkbench\LinguaPilot\Domain\Model\SalesRow;
use MediaWorkbench\LinguaPilot\Domain\Model\TermRef;
use MediaWorkbench\LinguaPilot\Gateway\WooGateway;

/**
 * Catalog display data and attribute taxonomies set by the test.
 */
final class FakeWooGateway implements WooGateway {

	/**
	 * Posts by ID.
	 *
	 * @var array<int, CatalogItem>
	 */
	private array $posts = array();

	/**
	 * Terms by ID.
	 *
	 * @var array<int, CatalogItem>
	 */
	private array $terms = array();

	/**
	 * Product field values by post ID.
	 *
	 * @var array<int, array<string, string|null>>
	 */
	private array $fields = array();

	/**
	 * Variable product IDs.
	 *
	 * @var list<int>
	 */
	private array $variable = array();

	/**
	 * Attributes by product ID.
	 *
	 * @var array<int, list<ProductAttribute>>
	 */
	private array $attributes = array();

	/**
	 * Variation IDs by parent ID.
	 *
	 * @var array<int, list<int>>
	 */
	private array $children = array();

	/**
	 * Variation attribute meta by variation ID.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $variation_values = array();

	/**
	 * Assigned terms by product ID and taxonomy.
	 *
	 * @var array<int, array<string, list<TermRef>>>
	 */
	private array $terms_by_product = array();

	/**
	 * Analytics sales rows, or null when the lookup tables are unavailable.
	 *
	 * @var list<SalesRow>|null
	 */
	private ?array $analytics_sales = array();

	/**
	 * Orders counted by Analytics in the period (null = unavailable).
	 *
	 * @var int|null
	 */
	private ?int $analytics_orders = 0;

	/**
	 * Sales aggregated from order items (fallback).
	 *
	 * @var list<SalesRow>
	 */
	private array $order_sales = array();

	/**
	 * Orders in the period in the order table.
	 *
	 * @var int
	 */
	private int $orders = 0;

	/**
	 * Period start passed to the last sales call (assertion helper).
	 *
	 * @var string|null
	 */
	public ?string $last_from = null;

	/**
	 * Creates the fake.
	 *
	 * @param list<string> $attribute_taxonomies Global attribute taxonomies.
	 */
	public function __construct( private readonly array $attribute_taxonomies = array( 'pa_color', 'pa_size' ) ) {}

	/**
	 * Adds a product.
	 *
	 * @param int         $id    Post ID.
	 * @param string      $title Title.
	 * @param string|null $sku   SKU.
	 */
	public function add_product( int $id, string $title, ?string $sku = null ): self {
		$this->posts[ $id ] = new CatalogItem( $id, 'product', $title, $sku, "https://shop.test/wp-admin/post.php?post={$id}&action=edit" );
		return $this;
	}

	/**
	 * Adds a variation.
	 *
	 * @param int         $id        Variation post ID.
	 * @param int         $parent_id Parent product ID.
	 * @param string      $title     Title.
	 * @param string|null $sku       SKU.
	 */
	public function add_variation( int $id, int $parent_id, string $title, ?string $sku = null ): self {
		$this->posts[ $id ] = new CatalogItem( $id, 'product_variation', $title, $sku, "https://shop.test/wp-admin/post.php?post={$parent_id}&action=edit", $parent_id );
		return $this;
	}

	/**
	 * Adds a term.
	 *
	 * @param int    $id       Term ID.
	 * @param string $taxonomy Taxonomy.
	 * @param string $name     Name.
	 */
	public function add_term( int $id, string $taxonomy, string $name ): self {
		$this->terms[ $id ] = new CatalogItem( $id, $taxonomy, $name, null, "https://shop.test/wp-admin/term.php?taxonomy={$taxonomy}&tag_ID={$id}" );
		return $this;
	}

	/**
	 * Sets a post's field values (keys = ProductField values; unset = null).
	 *
	 * @param int                        $id     Post ID.
	 * @param array<string, string|null> $fields Field values.
	 */
	public function set_fields( int $id, array $fields ): self {
		$this->fields[ $id ] = $fields;
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function product_fields( array $ids ): array {
		$result = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->fields[ $id ] ) ) {
				$result[ $id ] = $this->fields[ $id ] + array( 'parent_id' => (string) ( $this->posts[ $id ]->parent_id ?? 0 ) );
			}
		}

		return $result;
	}

	/**
	 * Declares a variable product with attributes, variations and assigned terms.
	 *
	 * @param int                          $id         Product ID.
	 * @param list<ProductAttribute>       $attributes Attributes.
	 * @param list<int>                    $children   Variation IDs.
	 * @param array<string, list<TermRef>> $terms     Assigned global attribute terms by taxonomy.
	 */
	public function set_variable_product( int $id, array $attributes, array $children = array(), array $terms = array() ): self {
		$this->variable[]              = $id;
		$this->attributes[ $id ]       = $attributes;
		$this->children[ $id ]         = $children;
		$this->terms_by_product[ $id ] = $terms;
		return $this;
	}

	/**
	 * Sets a variation's attribute meta (keys `attribute_*`).
	 *
	 * @param int                   $id     Variation ID.
	 * @param array<string, string> $values Attribute values.
	 */
	public function set_variation_values( int $id, array $values ): self {
		$this->variation_values[ $id ] = $values;
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function variable_product_ids( array $ids ): array {
		return array_values( array_intersect( $ids, $this->variable ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function product_attributes( array $ids ): array {
		return array_intersect_key( $this->attributes, array_flip( $ids ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function children( array $parent_ids ): array {
		return array_intersect_key( $this->children, array_flip( $parent_ids ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function variation_attribute_values( array $variation_ids ): array {
		return array_intersect_key( $this->variation_values, array_flip( $variation_ids ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function object_terms( array $ids, array $taxonomies ): array {
		$result = array();
		foreach ( $ids as $id ) {
			$result[ $id ] = array_intersect_key( $this->terms_by_product[ $id ] ?? array(), array_flip( $taxonomies ) );
		}

		return $result;
	}

	/**
	 * Sets the Analytics data.
	 *
	 * @param list<SalesRow>|null $rows   Rows, or null for "lookup tables unavailable".
	 * @param int|null            $orders Orders counted by Analytics.
	 */
	public function set_analytics( ?array $rows, ?int $orders ): self {
		$this->analytics_sales  = $rows;
		$this->analytics_orders = $orders;
		return $this;
	}

	/**
	 * Sets the order-table data.
	 *
	 * @param int            $orders Orders in the period.
	 * @param list<SalesRow> $rows   Sales from order items.
	 */
	public function set_orders( int $orders, array $rows = array() ): self {
		$this->orders      = $orders;
		$this->order_sales = $rows;
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function excluded_report_statuses(): array {
		return array( 'wc-pending', 'wc-cancelled', 'wc-failed', 'wc-auto-draft', 'wc-trash' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function order_count( string $from_gmt, array $excluded_statuses ): int {
		return $this->orders;
	}

	/**
	 * {@inheritDoc}
	 */
	public function analytics_order_count( string $from_gmt, array $excluded_statuses ): ?int {
		return $this->analytics_orders;
	}

	/**
	 * {@inheritDoc}
	 */
	public function sales_from_analytics( string $from_gmt, array $excluded_statuses ): array {
		$this->last_from = $from_gmt;
		return $this->analytics_sales ?? array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function sales_from_orders( string $from_gmt, array $excluded_statuses ): array {
		$this->last_from = $from_gmt;
		return $this->order_sales;
	}

	/**
	 * {@inheritDoc}
	 */
	public function attribute_taxonomies(): array {
		return $this->attribute_taxonomies;
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe_posts( array $ids ): array {
		return array_intersect_key( $this->posts, array_flip( $ids ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function describe_terms( array $ids ): array {
		return array_intersect_key( $this->terms, array_flip( $ids ) );
	}
}
