<?php
/**
 * One page of object IDs.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain\Model;

/**
 * A page of IDs plus the total number of matching objects.
 */
final class IdPage {

	/**
	 * Creates a page.
	 *
	 * @param list<int> $ids   IDs on this page.
	 * @param int       $total Total matching objects across all pages.
	 */
	public function __construct(
		public readonly array $ids,
		public readonly int $total,
	) {}
}
