<?php
/**
 * One page of coverage rows.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * Coverage rows on this page plus the total number of matching originals.
 */
final class CoveragePage {

	/**
	 * Creates a page.
	 *
	 * @param list<CoverageRow> $rows  Rows on this page, ordered by original ID.
	 * @param int               $total Matching originals across all pages.
	 */
	public function __construct(
		public readonly array $rows,
		public readonly int $total,
	) {}
}
