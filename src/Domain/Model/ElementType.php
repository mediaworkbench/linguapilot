<?php
/**
 * WPML element type.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

use InvalidArgumentException;

/**
 * A translatable element type in WPML's naming: `post_{post_type}` or `tax_{taxonomy}`.
 */
final class ElementType {

	private const POST_PREFIX = 'post_';
	private const TERM_PREFIX = 'tax_';

	/**
	 * Creates an element type from its WPML name.
	 *
	 * @param string $value WPML element type, e.g. `post_product`.
	 */
	private function __construct( public readonly string $value ) {}

	/**
	 * Products.
	 */
	public static function product(): self {
		return self::post_type( 'product' );
	}

	/**
	 * Product variations.
	 */
	public static function variation(): self {
		return self::post_type( 'product_variation' );
	}

	/**
	 * Product categories.
	 */
	public static function product_category(): self {
		return self::taxonomy( 'product_cat' );
	}

	/**
	 * Product tags.
	 */
	public static function product_tag(): self {
		return self::taxonomy( 'product_tag' );
	}

	/**
	 * Any post type.
	 *
	 * @param string $post_type Post type slug.
	 */
	public static function post_type( string $post_type ): self {
		return new self( self::POST_PREFIX . self::assert_slug( $post_type ) );
	}

	/**
	 * Any taxonomy, e.g. a global attribute `pa_color`.
	 *
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function taxonomy( string $taxonomy ): self {
		return new self( self::TERM_PREFIX . self::assert_slug( $taxonomy ) );
	}

	/**
	 * Whether elements of this type are terms (as opposed to posts).
	 */
	public function is_term(): bool {
		return str_starts_with( $this->value, self::TERM_PREFIX );
	}

	/**
	 * The post type or taxonomy slug.
	 */
	public function name(): string {
		return substr( $this->value, strlen( $this->is_term() ? self::TERM_PREFIX : self::POST_PREFIX ) );
	}

	/**
	 * Value equality.
	 *
	 * @param self $other Type to compare with.
	 */
	public function equals( self $other ): bool {
		return $this->value === $other->value;
	}

	/**
	 * Validates a post type or taxonomy slug. The value ends up in SQL, so be strict.
	 *
	 * @param string $slug Slug to validate.
	 * @throws InvalidArgumentException When the slug contains anything but [a-z0-9_-].
	 */
	private static function assert_slug( string $slug ): string {
		if ( 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $slug ) ) {
			throw new InvalidArgumentException( 'Invalid post type or taxonomy slug.' );
		}

		return $slug;
	}
}
