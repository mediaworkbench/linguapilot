<?php
/**
 * Which originals an audit looks at.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

use InvalidArgumentException;

/**
 * One post type, or one or more taxonomies, plus the post statuses originals must have.
 */
final class CatalogScope {

	/**
	 * Original post statuses for "any": every status a merchant works with, never trash or auto-draft.
	 */
	public const ANY_POST_STATUS = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Creates a scope.
	 *
	 * @param list<ElementType> $types         One post type, or one or more taxonomies.
	 * @param list<string>      $post_statuses Statuses of originals to include (posts only).
	 * @throws InvalidArgumentException When types are empty, mixed, or several post types.
	 */
	public function __construct(
		public readonly array $types,
		public readonly array $post_statuses = array( 'publish' ),
	) {
		if ( array() === $types ) {
			throw new InvalidArgumentException( 'A scope needs at least one element type.' );
		}

		$all_terms = count( array_filter( $types, static fn ( ElementType $type ): bool => $type->is_term() ) ) === count( $types );
		if ( ! $all_terms && 1 !== count( $types ) ) {
			throw new InvalidArgumentException( 'A scope is either one post type or a set of taxonomies.' );
		}
	}

	/**
	 * Whether the scope covers taxonomies.
	 */
	public function is_terms(): bool {
		return $this->types[0]->is_term();
	}
}
