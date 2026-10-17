<?php
/**
 * Store overview service.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain;

use MediaWorkbench\LinguaPilot\Domain\Model\Currency;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\Language;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductField;
use MediaWorkbench\LinguaPilot\Gateway\MultilingualGateway;

/**
 * Languages, currencies, sync settings and catalog size: what an agent needs before asking anything else.
 */
final class StoreOverview {

	/**
	 * Creates the service.
	 *
	 * @param MultilingualGateway $gateway Multilingual plugin access.
	 */
	public function __construct( private readonly MultilingualGateway $gateway ) {}

	/**
	 * Builds the overview.
	 *
	 * @param bool $wcml_active Whether WooCommerce Multilingual is active.
	 * @return array{
	 *     default_language: string,
	 *     languages: list<array{code: string, native_name: string, english_name: string, is_default: bool}>,
	 *     wcml_active: bool,
	 *     multi_currency_enabled: bool,
	 *     default_currency: string,
	 *     currencies: list<array{code: string, is_default: bool, languages: list<string>}>,
	 *     sync_settings: array{synced_fields: list<string>, unsynced_fields: list<string>},
	 *     catalog_size: array{products: int, variations: int, product_categories: int, product_tags: int}
	 * }
	 */
	public function build( bool $wcml_active ): array {
		$languages  = $this->gateway->active_languages();
		$currencies = $this->gateway->currencies();
		$sync       = $this->gateway->sync_settings();
		$all_codes  = array_map( static fn ( Language $language ): string => $language->code, $languages );

		// Without multi-currency the store's base currency is used in every language.
		$currency_list = $currencies->enabled
			? array_map(
				static fn ( Currency $currency ): array => array(
					'code'       => $currency->code,
					'is_default' => $currency->code === $currencies->default_currency,
					// Site language order; languages no longer active are dropped.
					'languages'  => array_values( array_intersect( $all_codes, $currency->languages ) ),
				),
				$currencies->currencies
			)
			: array(
				array(
					'code'       => $currencies->default_currency,
					'is_default' => true,
					'languages'  => $all_codes,
				),
			);

		return array(
			'default_language'       => $this->gateway->default_language(),
			'languages'              => array_map(
				static fn ( Language $language ): array => array(
					'code'         => $language->code,
					'native_name'  => $language->native_name,
					'english_name' => $language->english_name,
					'is_default'   => $language->is_default,
				),
				$languages
			),
			'wcml_active'            => $wcml_active,
			'multi_currency_enabled' => $currencies->enabled,
			'default_currency'       => $currencies->default_currency,
			'currencies'             => $currency_list,
			'sync_settings'          => array(
				'synced_fields'   => self::field_names( $sync->synced_fields() ),
				'unsynced_fields' => self::field_names( $sync->unsynced_fields() ),
			),
			'catalog_size'           => array(
				'products'           => $this->count_originals( ElementType::product() ),
				'variations'         => $this->count_originals( ElementType::variation() ),
				'product_categories' => $this->count_originals( ElementType::product_category() ),
				'product_tags'       => $this->count_originals( ElementType::product_tag() ),
			),
		);
	}

	/**
	 * Number of originals of a type (any status except trash and auto-draft).
	 *
	 * @param ElementType $type Element type.
	 */
	private function count_originals( ElementType $type ): int {
		return $this->gateway->original_ids( $type, 1, 0 )->total;
	}

	/**
	 * Public names of product fields.
	 *
	 * @param list<ProductField> $fields Fields.
	 * @return list<string>
	 */
	private static function field_names( array $fields ): array {
		return array_map( static fn ( ProductField $field ): string => $field->value, $fields );
	}
}
