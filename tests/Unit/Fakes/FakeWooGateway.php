<?php
/**
 * In-memory WooCommerce gateway for unit tests.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogItem;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooGateway;

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
				$result[ $id ] = $this->fields[ $id ];
			}
		}

		return $result;
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
