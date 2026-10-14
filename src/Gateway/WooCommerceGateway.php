<?php
/**
 * WooCommerce implementation of the catalog gateway.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogItem;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductAttribute;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TermRef;
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
		$list = self::id_list( $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Raw reads by design (see interface); $list holds only integers (id_list()).
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
	 */
	public function variable_product_ids( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = self::id_list( $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (id_list()).
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
	 * Raw read: WCML filters product meta reads (docs/api-notes.md §11).
	 */
	public function product_attributes( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$wpdb = $this->wpdb;
		$list = self::id_list( $ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (id_list()).
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
		$list = self::id_list( $parent_ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (id_list()).
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
		$list = self::id_list( $variation_ids );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (id_list()).
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

		$wpdb  = $this->wpdb;
		$list  = self::id_list( $ids );
		$names = "'" . implode( "','", $taxonomies ) . "'";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $list holds only integers (id_list()); $names only validated slugs.
		$rows = $wpdb->get_results(
			"SELECT tr.object_id, tt.taxonomy, t.term_id, t.slug, t.name FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			WHERE tr.object_id IN ( {$list} ) AND tt.taxonomy IN ( {$names} )
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
	 * Store content as plain text: tags stripped, entities decoded (prompt-injection hygiene, concept §9).
	 *
	 * @param string $text Raw title or name.
	 */
	private static function plain_text( string $text ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
