<?php
/**
 * Ability: mlstore/currency-price-gaps.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Abilities;

use Closure;
use DateTimeImmutable;
use MediaWorkbench\MultilingualStoreAbilities\Domain\CurrencyPriceAudit;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Support\OutputEnvelope;
use MediaWorkbench\MultilingualStoreAbilities\Support\Pagination;

/**
 * Manually priced products and variations without a price in a secondary currency.
 */
final class CurrencyPriceGaps extends AbstractAbility {

	/**
	 * Creates the ability.
	 *
	 * @param MultilingualGateway                 $gateway Multilingual plugin access.
	 * @param WooGateway                          $woo     Catalog access.
	 * @param AuditLogger                         $audit   Audit log.
	 * @param DependencyStatus                    $status  Detected dependencies.
	 * @param (Closure(): DateTimeImmutable)|null $clock   Current time; injectable for tests.
	 */
	public function __construct(
		MultilingualGateway $gateway,
		private readonly WooGateway $woo,
		AuditLogger $audit,
		DependencyStatus $status,
		?Closure $clock = null,
	) {
		parent::__construct( $gateway, $audit, $status, $clock );
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'mlstore/currency-price-gaps';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Currency price gaps', 'multilingual-store-abilities-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Lists products and variations that have WooCommerce Multilingual "set prices manually" enabled but no regular price in one or more secondary currencies. Customers paying in such a currency silently get an exchange-rate conversion of the base price instead. Requires WCML multi-currency; otherwise returns mlstore_currency_disabled.', 'multilingual-store-abilities-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function requires_wcml(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_properties(): array {
		return array_merge(
			array(
				'currencies'         => array(
					'type'        => 'array',
					'items'       => array(
						'type'    => 'string',
						'pattern' => '^[A-Z]{3}$',
					),
					'uniqueItems' => true,
					'default'     => array(),
					'description' => __( 'Secondary currency codes to check, e.g. CHF. Default: all secondary currencies (see mlstore/languages-overview).', 'multilingual-store-abilities-for-woocommerce' ),
				),
				'include_variations' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Also check variations (manual prices are set per variation).', 'multilingual-store-abilities-for-woocommerce' ),
				),
			),
			Pagination::input_properties()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		$codes = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'         => array(
					'type'        => 'integer',
					'description' => __( 'Original-language product ID (the parent product for variations).', 'multilingual-store-abilities-for-woocommerce' ),
				),
				'variation_id'       => array( 'type' => array( 'integer', 'null' ) ),
				'title'              => array( 'type' => 'string' ),
				'sku'                => array( 'type' => array( 'string', 'null' ) ),
				'manual_pricing'     => array(
					'type' => 'boolean',
					'enum' => array( true ),
				),
				'missing_currencies' => $codes,
				'base_regular_price' => array(
					'type'        => array( 'string', 'null' ),
					'description' => __( 'Regular price in the store base currency, as stored.', 'multilingual-store-abilities-for-woocommerce' ),
				),
				'edit_url'           => array( 'type' => 'string' ),
			),
			'required'             => array( 'product_id', 'variation_id', 'title', 'sku', 'manual_pricing', 'missing_currencies', 'base_regular_price', 'edit_url' ),
			'additionalProperties' => false,
		);

		$summary = array(
			'type'                 => 'object',
			'properties'           => array(
				'currencies_checked'  => $codes,
				'objects_checked'     => array(
					'type'        => 'integer',
					'description' => __( 'Manually priced products and variations checked.', 'multilingual-store-abilities-for-woocommerce' ),
				),
				'objects_with_gaps'   => array( 'type' => 'integer' ),
				'missing_by_currency' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'currency' => array( 'type' => 'string' ),
							'missing'  => array( 'type' => 'integer' ),
						),
						'required'             => array( 'currency', 'missing' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'currencies_checked', 'objects_checked', 'objects_with_gaps', 'missing_by_currency' ),
			'additionalProperties' => false,
		);

		return OutputEnvelope::collection_schema( 'items', $item, $this->meta_schema(), $summary );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws AbilityError When multi-currency is off or unknown currencies are requested.
	 */
	protected function run( array $input ): array {
		$settings = $this->gateway->currencies();
		if ( ! $settings->enabled ) {
			throw AbilityError::currency_disabled();
		}

		$secondary = $settings->secondary_codes();
		$requested = is_array( $input['currencies'] ?? null ) ? array_values( array_filter( $input['currencies'], 'is_string' ) ) : array();
		$invalid   = array_values( array_diff( $requested, $secondary ) );
		if ( array() !== $invalid ) {
			throw AbilityError::invalid_input(
				esc_html(
					sprintf(
					/* translators: 1: Requested currency codes, 2: Available secondary currency codes. */
						__( 'Not a secondary currency: %1$s. Secondary currencies: %2$s.', 'multilingual-store-abilities-for-woocommerce' ),
						implode( ', ', $invalid ),
						array() === $secondary ? '-' : implode( ', ', $secondary )
					)
				)
			);
		}

		$currencies = array() === $requested ? $secondary : array_values( array_intersect( $secondary, $requested ) );
		$variations = is_bool( $input['include_variations'] ?? null ) ? $input['include_variations'] : true;
		$pagination = Pagination::from_input( $input );

		$result = ( new CurrencyPriceAudit( $this->gateway, $this->woo ) )->audit( $currencies, $variations, $pagination );

		return OutputEnvelope::collection( 'items', $result['items'], $pagination, $result['total_items'], $this->meta(), $result['summary'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function result_count( array $output ): ?int {
		return is_array( $output['items'] ?? null ) ? count( $output['items'] ) : null;
	}
}
