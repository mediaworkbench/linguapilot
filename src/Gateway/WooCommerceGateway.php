<?php
/**
 * WooCommerce implementation of the catalog gateway.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Gateway;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogItem;
use Automattic\WooCommerce\Utilities\OrderUtil;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductAttribute;
use MediaWorkbench\LinguaPilot\Domain\Model\SalesRow;
use MediaWorkbench\LinguaPilot\Domain\Model\TermRef;
use wpdb;

/**
 * Reads catalog display data. Read-only; never calls the multilingual plugin.
 */
final class WooCommerceGateway implements WooGateway {

	use AssertsQueries;

	/**
	 * Meta keys of the comparable fields, by ProductField value.
	 */
	private const FIELD_META_KEYS = array(
		'sku'            => '_sku',
		'stock_status'   => '_stock_status',
		'stock_quantity' => '_stock',
		'manage_stock'   => '_manage_stock',
		'tax_class'      => '_tax_class',
		'regular_price'  => '_regular_price',
		'sale_price'     => '_sale_price',
	);

	/**
	 * Creates the gateway.
	 *
	 * @param wpdb $wpdb WordPress database access.
	 */
	public function __construct( private readonly wpdb $wpdb ) {}

	/**
	 * {@inheritDoc}
	 */
	public function attribute_taxonomies(): array {
		return function_exists( 'wc_get_attribute_taxonomy_names' ) ? array_values( array_filter( wc_get_attribute_taxonomy_names(), 'is_string' ) ) : array();
	}

	/**
	 * {@inheritDoc}
	 */
	public function product_fields( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = implode( ',', wp_parse_id_list( $ids ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Raw reads by design (see interface); $list holds only integers (wp_parse_id_list()).
		$posts = $wpdb->get_results( "SELECT ID, post_status, post_parent FROM {$wpdb->posts} WHERE ID IN ( {$list} )", ARRAY_A );
		$this->assert_query_succeeded();
		$meta = $wpdb->get_results(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
			WHERE post_id IN ( {$list} ) AND meta_key IN ( '_sku', '_stock_status', '_stock', '_manage_stock', '_tax_class', '_regular_price', '_sale_price' )",
			ARRAY_A
		);
		$this->assert_query_succeeded();
		$visibility = $wpdb->get_results(
			"SELECT tr.object_id, t.slug FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_visibility'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tr.object_id IN ( {$list} ) AND t.slug IN ( 'exclude-from-catalog', 'exclude-from-search' )",
			ARRAY_A
		);
		$this->assert_query_succeeded();
		// phpcs:enable

		$fields = array();
		foreach ( $posts as $post ) {
			$fields[ (int) $post['ID'] ] = array_fill_keys( array_keys( self::FIELD_META_KEYS ), null ) + array(
				'post_status'        => (string) $post['post_status'],
				'catalog_visibility' => 'visible',
				'parent_id'          => (string) $post['post_parent'],
			);
		}

		$by_key = array_flip( self::FIELD_META_KEYS );
		foreach ( $meta as $row ) {
			$id = (int) $row['post_id'];
			if ( isset( $fields[ $id ], $by_key[ $row['meta_key'] ] ) ) {
				$fields[ $id ][ $by_key[ $row['meta_key'] ] ] = null === $row['meta_value'] ? null : (string) $row['meta_value'];
			}
		}

		$excluded = array();
		foreach ( $visibility as $row ) {
			$excluded[ (int) $row['object_id'] ][] = (string) $row['slug'];
		}
		foreach ( $excluded as $id => $slugs ) {
			if ( isset( $fields[ $id ] ) ) {
				$fields[ $id ]['catalog_visibility'] = self::catalog_visibility( $slugs );
			}
		}

		return $fields;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Mirrors WooCommerce 11.2: option `woocommerce_excluded_report_order_statuses` (default from the
	 * `woocommerce_analytics_settings_default_excluded_order_statuses` filter: pending, cancelled, failed),
	 * plus auto-draft and trash, then the public `woocommerce_analytics_excluded_order_statuses` filter.
	 */
	public function excluded_report_statuses(): array {
		/** This filter is documented in woocommerce/src/Internal/Admin/Settings.php */
		$defaults = apply_filters( 'woocommerce_analytics_settings_default_excluded_order_statuses', array( 'pending', 'cancelled', 'failed' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook, mirrored.
		$option   = get_option( 'woocommerce_excluded_report_order_statuses', $defaults );
		$statuses = array_merge( array( 'auto-draft', 'trash' ), is_array( $option ) ? $option : ( is_array( $defaults ) ? $defaults : array() ) );

		/** This filter is documented in woocommerce/src/Admin/API/Reports/DataStore.php */
		$filtered = apply_filters( 'woocommerce_analytics_excluded_order_statuses', $statuses ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce hook, mirrored.
		$filtered = is_array( $filtered ) ? $filtered : $statuses;

		$result = array();
		foreach ( $filtered as $status ) {
			if ( is_string( $status ) ) {
				$normalized = mb_substr( 'wc-' . trim( $status ), 0, 20 );
				if ( 1 === preg_match( '/^[a-z0-9_-]+$/', $normalized ) ) {
					$result[] = $normalized;
				}
			}
		}

		return array_values( array_unique( $result ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Uses the table WooCommerce reports as authoritative (`OrderUtil::get_table_for_orders()`), so it
	 * works with HPOS and with legacy post storage.
	 */
	public function order_count( string $from_gmt, array $excluded_statuses ): int {
		$wpdb     = $this->wpdb;
		$excluded = implode( ',', $excluded_statuses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Read-only report query.
		$count = OrderUtil::custom_orders_table_usage_is_enabled()
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE type = 'shop_order' AND date_created_gmt >= %s AND NOT FIND_IN_SET( status, %s )", OrderUtil::get_table_for_orders(), $from_gmt, $excluded ) )
			: $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'shop_order' AND post_date_gmt >= %s AND NOT FIND_IN_SET( post_status, %s )", $wpdb->posts, $from_gmt, $excluded ) );
		// phpcs:enable
		$this->assert_query_succeeded();

		return (int) $count;
	}

	/**
	 * {@inheritDoc}
	 */
	public function analytics_order_count( string $from_gmt, array $excluded_statuses ): ?int {
		if ( ! $this->analytics_available() ) {
			return null;
		}

		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only report query.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE parent_id = 0 AND date_created_gmt >= %s AND NOT FIND_IN_SET( status, %s )', $wpdb->prefix . 'wc_order_stats', $from_gmt, implode( ',', $excluded_statuses ) ) );
		$this->assert_query_succeeded();

		return (int) $count;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Net revenue = `product_net_revenue` (WooCommerce Analytics' products report). Refund orders have
	 * their own rows with negative values and are included, as in Analytics.
	 */
	public function sales_from_analytics( string $from_gmt, array $excluded_statuses ): array {
		if ( ! $this->analytics_available() ) {
			return array();
		}

		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only report query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.product_id, SUM( l.product_qty ) AS units, SUM( l.product_net_revenue ) AS revenue
				FROM %i l INNER JOIN %i s ON s.order_id = l.order_id
				WHERE s.date_created_gmt >= %s AND NOT FIND_IN_SET( s.status, %s )
				GROUP BY l.product_id',
				$wpdb->prefix . 'wc_order_product_lookup',
				$wpdb->prefix . 'wc_order_stats',
				$from_gmt,
				implode( ',', $excluded_statuses )
			),
			ARRAY_A
		);
		$this->assert_query_succeeded();

		return self::sales_rows( $rows ?? array() );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Raw SQL over the order item tables (`_product_id`, `_qty`, `_line_total`), not wc_get_orders():
	 * loading orders lets WCML swap item product IDs to translations in memory (WCML\OrderItems\Hooks),
	 * and it is far heavier. Uses the order table WooCommerce reports as authoritative (HPOS or posts).
	 */
	public function sales_from_orders( string $from_gmt, array $excluded_statuses ): array {
		$wpdb     = $this->wpdb;
		$items    = $wpdb->prefix . 'woocommerce_order_items';
		$meta     = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$excluded = implode( ',', $excluded_statuses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Read-only report query.
		$rows = OrderUtil::custom_orders_table_usage_is_enabled()
			? $wpdb->get_results(
				$wpdb->prepare(
					"SELECT CAST( pid.meta_value AS UNSIGNED ) AS product_id, SUM( qty.meta_value ) AS units, SUM( total.meta_value ) AS revenue
					FROM %i oi
					INNER JOIN %i o ON o.id = oi.order_id AND o.type = 'shop_order' AND o.date_created_gmt >= %s AND NOT FIND_IN_SET( o.status, %s )
					INNER JOIN %i pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
					INNER JOIN %i qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
					INNER JOIN %i total ON total.order_item_id = oi.order_item_id AND total.meta_key = '_line_total'
					WHERE oi.order_item_type = 'line_item'
					GROUP BY product_id",
					$items,
					OrderUtil::get_table_for_orders(),
					$from_gmt,
					$excluded,
					$meta,
					$meta,
					$meta
				),
				ARRAY_A
			)
			: $wpdb->get_results(
				$wpdb->prepare(
					"SELECT CAST( pid.meta_value AS UNSIGNED ) AS product_id, SUM( qty.meta_value ) AS units, SUM( total.meta_value ) AS revenue
					FROM %i oi
					INNER JOIN %i o ON o.ID = oi.order_id AND o.post_type = 'shop_order' AND o.post_date_gmt >= %s AND NOT FIND_IN_SET( o.post_status, %s )
					INNER JOIN %i pid ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id'
					INNER JOIN %i qty ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
					INNER JOIN %i total ON total.order_item_id = oi.order_item_id AND total.meta_key = '_line_total'
					WHERE oi.order_item_type = 'line_item'
					GROUP BY product_id",
					$items,
					$wpdb->posts,
					$from_gmt,
					$excluded,
					$meta,
					$meta,
					$meta
				),
				ARRAY_A
			);
		// phpcs:enable
		$this->assert_query_succeeded();

		return self::sales_rows( $rows ?? array() );
	}

	/**
	 * {@inheritDoc}
	 */
	public function variable_product_ids( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = implode( ',', wp_parse_id_list( $ids ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (wp_parse_id_list()).
		$found = $wpdb->get_col(
			"SELECT tr.object_id FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_type'
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id AND t.slug = 'variable'
			WHERE tr.object_id IN ( {$list} )"
		);
		// phpcs:enable
		$this->assert_query_succeeded();

		return array_values( array_intersect( $ids, array_map( 'intval', $found ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Raw read: WCML filters product meta reads.
	 */
	public function product_attributes( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = implode( ',', wp_parse_id_list( $ids ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (wp_parse_id_list()).
		$rows = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$list} ) AND meta_key = '_product_attributes'", ARRAY_A );
		// phpcs:enable
		$this->assert_query_succeeded();

		$result = array();
		foreach ( $rows as $row ) {
			$stored     = maybe_unserialize( (string) $row['meta_value'] );
			$attributes = array();
			foreach ( is_array( $stored ) ? $stored : array() as $key => $data ) {
				if ( ! is_string( $key ) || ! is_array( $data ) ) {
					continue;
				}
				$is_taxonomy  = ! empty( $data['is_taxonomy'] );
				$attributes[] = new ProductAttribute(
					$key,
					is_string( $data['name'] ?? null ) ? $data['name'] : $key,
					$is_taxonomy,
					! empty( $data['is_variation'] ),
					$is_taxonomy || ! is_string( $data['value'] ?? null ) ? array() : ProductAttribute::split_options( $data['value'] )
				);
			}
			$result[ (int) $row['post_id'] ] = $attributes;
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function children( array $parent_ids ): array {
		if ( array() === $parent_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = implode( ',', wp_parse_id_list( $parent_ids ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (wp_parse_id_list()).
		$rows = $wpdb->get_results(
			"SELECT ID, post_parent FROM {$wpdb->posts}
			WHERE post_parent IN ( {$list} ) AND post_type = 'product_variation' AND post_status NOT IN ( 'trash', 'auto-draft' )
			ORDER BY menu_order ASC, ID ASC",
			ARRAY_A
		);
		// phpcs:enable
		$this->assert_query_succeeded();

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row['post_parent'] ][] = (int) $row['ID'];
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function variation_attribute_values( array $variation_ids ): array {
		if ( array() === $variation_ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = implode( ',', wp_parse_id_list( $variation_ids ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (wp_parse_id_list()).
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ( {$list} ) AND meta_key LIKE 'attribute\\_%'", ARRAY_A );
		// phpcs:enable
		$this->assert_query_succeeded();

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row['post_id'] ][ (string) $row['meta_key'] ] = (string) $row['meta_value'];
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Direct SQL: WPML filters term queries to the current language.
	 */
	public function object_terms( array $ids, array $taxonomies ): array {
		if ( array() === $ids || array() === $taxonomies ) {
			return array();
		}

		// Only strict taxonomy slugs reach the SQL (same rule as ElementType).
		$taxonomies = array_values( array_filter( $taxonomies, static fn ( string $taxonomy ): bool => 1 === preg_match( '/^[a-z0-9_-]{1,32}$/', $taxonomy ) ) );
		if ( array() === $taxonomies ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = implode( ',', wp_parse_id_list( $ids ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (wp_parse_id_list()); taxonomies: esc_sql() of validated slugs.
		$rows = $wpdb->get_results(
			"SELECT tr.object_id, tt.taxonomy, t.term_id, t.slug, t.name FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tr.object_id IN ( {$list} ) AND FIND_IN_SET( tt.taxonomy, '" . esc_sql( implode( ',', $taxonomies ) ) . "' )
			ORDER BY t.term_id ASC",
			ARRAY_A
		);
		// phpcs:enable
		$this->assert_query_succeeded();

		$result = array();
		foreach ( $rows as $row ) {
			$result[ (int) $row['object_id'] ][ (string) $row['taxonomy'] ][] = new TermRef( (int) $row['term_id'], (string) $row['slug'], self::plain_text( (string) $row['name'] ) );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Primes the post and meta caches once for the whole page instead of one query per post.
	 */
	public function describe_posts( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		_prime_post_caches( $ids, false, true );

		$items = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( null === $post ) {
				continue;
			}

			$sku          = get_post_meta( $id, '_sku', true );
			$is_variation = 'product_variation' === $post->post_type;
			$items[ $id ] = new CatalogItem(
				$id,
				$post->post_type,
				self::plain_text( $post->post_title ),
				is_string( $sku ) && '' !== $sku ? $sku : null,
				// Variations are edited on their parent product's screen.
				admin_url( 'post.php?post=' . ( $is_variation ? $post->post_parent : $id ) . '&action=edit' ),
				$is_variation ? $post->post_parent : null
			);
		}

		return $items;
	}

	/**
	 * {@inheritDoc}
	 *
	 * Direct SQL instead of get_term(): WPML can filter get_term() to swap IDs into the current
	 * language ("adjust IDs"), which would return the wrong term's name.
	 */
	public function describe_terms( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- See method doc; read-only, one query per page.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.term_id, t.name, tt.taxonomy FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id WHERE FIND_IN_SET( t.term_id, %s )',
				$wpdb->terms,
				$wpdb->term_taxonomy,
				implode( ',', array_map( 'intval', $ids ) )
			),
			ARRAY_A
		);

		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id           = (int) $row['term_id'];
			$taxonomy     = (string) $row['taxonomy'];
			$items[ $id ] = new CatalogItem(
				$id,
				$taxonomy,
				self::plain_text( (string) $row['name'] ),
				null,
				admin_url( 'term.php?taxonomy=' . rawurlencode( $taxonomy ) . '&tag_ID=' . $id . '&post_type=product' )
			);
		}

		return $items;
	}

	/**
	 * Whether the Analytics lookup tables exist.
	 */
	private function analytics_available(): bool {
		$wpdb  = $this->wpdb;
		$table = $wpdb->prefix . 'wc_order_product_lookup';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Schema check.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );

		return $found === $table;
	}

	/**
	 * Converts aggregated rows to SalesRow objects.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows with product_id, units, revenue.
	 * @return list<SalesRow>
	 */
	private static function sales_rows( array $rows ): array {
		$sales = array();
		foreach ( $rows as $row ) {
			if ( (int) $row['product_id'] > 0 ) {
				$sales[] = new SalesRow( (int) $row['product_id'], (float) $row['units'], (float) $row['revenue'] );
			}
		}

		return $sales;
	}

	/**
	 * WooCommerce's catalog visibility from `product_visibility` terms (as WC_Product::get_catalog_visibility()).
	 *
	 * @param list<string> $slugs Exclusion term slugs.
	 */
	private static function catalog_visibility( array $slugs ): string {
		$catalog = in_array( 'exclude-from-catalog', $slugs, true );
		$search  = in_array( 'exclude-from-search', $slugs, true );

		return match ( true ) {
			$catalog && $search => 'hidden',
			$catalog            => 'search',
			$search             => 'catalog',
			default             => 'visible',
		};
	}

	/**
	 * Store content as plain text: tags stripped, entities decoded (prompt-injection hygiene, see docs/SECURITY.md).
	 *
	 * @param string $text Raw title or name.
	 */
	private static function plain_text( string $text ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
