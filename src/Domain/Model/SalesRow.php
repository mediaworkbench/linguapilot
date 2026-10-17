<?php
/**
 * Sales of one product in a period.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * Units and net revenue (store base currency) for a product ID as recorded on orders.
 */
final class SalesRow {

	/**
	 * Creates a row.
	 *
	 * @param int   $product_id Product ID as recorded (may be a translation).
	 * @param float $units      Units sold (refunds subtract).
	 * @param float $revenue    Net revenue in the store base currency.
	 */
	public function __construct(
		public readonly int $product_id,
		public readonly float $units,
		public readonly float $revenue,
	) {}
}
