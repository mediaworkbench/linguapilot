<?php
/**
 * Parser for WCML's multi-currency settings.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway\Wpml;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Currency;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;

/**
 * Turns the raw `_wcml_settings` option into {@see CurrencySettings}. Malformed data degrades to "disabled".
 *
 * Structure verified against WCML 5.6.3 (docs/api-notes.md §5.5).
 */
final class CurrencySettingsParser {

	/**
	 * WCML_MULTI_CURRENCIES_INDEPENDENT.
	 */
	private const MODE_ENABLED = 2;

	/**
	 * Parses the option.
	 *
	 * @param mixed  $wcml_settings    Raw value of the `_wcml_settings` option.
	 * @param string $default_currency Store base currency (`woocommerce_currency`).
	 * @param bool   $wcml_active      Whether WCML is running; its stored settings are ignored when it is not.
	 */
	public static function parse( mixed $wcml_settings, string $default_currency, bool $wcml_active = true ): CurrencySettings {
		$settings = is_array( $wcml_settings ) ? $wcml_settings : array();
		$mode     = $settings['enable_multi_currency'] ?? null;
		$enabled  = $wcml_active && is_numeric( $mode ) && self::MODE_ENABLED === (int) $mode;
		$options  = $enabled && isset( $settings['currency_options'] ) && is_array( $settings['currency_options'] )
			? $settings['currency_options']
			: array();

		$currencies = array( new Currency( $default_currency, self::languages( $options[ $default_currency ] ?? null ) ) );

		foreach ( $options as $code => $currency_options ) {
			if ( ! is_string( $code ) || 1 !== preg_match( '/^[A-Z]{3}$/', $code ) || $code === $default_currency ) {
				continue;
			}

			$currencies[] = new Currency( $code, self::languages( $currency_options ) );
		}

		return new CurrencySettings( $enabled, $default_currency, $currencies );
	}

	/**
	 * Language codes a currency is enabled for.
	 *
	 * @param mixed $currency_options One entry of `currency_options`.
	 * @return list<string>
	 */
	private static function languages( mixed $currency_options ): array {
		$map = is_array( $currency_options ) && isset( $currency_options['languages'] ) && is_array( $currency_options['languages'] )
			? $currency_options['languages']
			: array();

		$languages = array();
		foreach ( $map as $language => $enabled ) {
			if ( is_string( $language ) && (bool) $enabled ) {
				$languages[] = $language;
			}
		}

		return $languages;
	}
}
