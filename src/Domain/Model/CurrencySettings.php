<?php
/**
 * Multi-currency configuration.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain\Model;

/**
 * Store currencies. The default (store base) currency is always listed first.
 */
final class CurrencySettings {

	/**
	 * Creates the settings.
	 *
	 * @param bool           $enabled          Whether multi-currency is enabled.
	 * @param string         $default_currency Store base currency code.
	 * @param list<Currency> $currencies       All currencies, default first.
	 */
	public function __construct(
		public readonly bool $enabled,
		public readonly string $default_currency,
		public readonly array $currencies,
	) {}

	/**
	 * All currency codes, default first.
	 *
	 * @return list<string>
	 */
	public function codes(): array {
		return array_map( static fn ( Currency $currency ): string => $currency->code, $this->currencies );
	}

	/**
	 * Codes of the non-default currencies (empty when multi-currency is disabled).
	 *
	 * @return list<string>
	 */
	public function secondary_codes(): array {
		if ( ! $this->enabled ) {
			return array();
		}

		return array_values( array_diff( $this->codes(), array( $this->default_currency ) ) );
	}
}
