<?php
/**
 * A store currency.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain\Model;

/**
 * A currency and the languages it is offered in.
 */
final class Currency {

	/**
	 * Creates a currency.
	 *
	 * @param string       $code      ISO 4217 code, e.g. `CHF`.
	 * @param list<string> $languages Language codes the currency is shown for.
	 */
	public function __construct(
		public readonly string $code,
		public readonly array $languages,
	) {}
}
