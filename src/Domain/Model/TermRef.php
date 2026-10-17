<?php
/**
 * A term assigned to a product.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * Term ID, slug and plain-text name.
 */
final class TermRef {

	/**
	 * Creates a reference.
	 *
	 * @param int    $id   Term ID.
	 * @param string $slug Slug (what variation meta stores for global attributes).
	 * @param string $name Plain-text name.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $slug,
		public readonly string $name,
	) {}
}
