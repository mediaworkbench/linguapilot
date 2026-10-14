<?php
/**
 * Ability: mlstore/translation-work-queue.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Abilities;

use Closure;
use DateTimeImmutable;
use MediaWorkbench\MultilingualStoreAbilities\Domain\SalesRanking;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Support\OutputEnvelope;

/**
 * What to translate first: untranslated products ranked by sales.
 */
final class TranslationWorkQueue extends AbstractAbility {

	/**
	 * Creates the ability.
	 *
	 * @param MultilingualGateway                 $gateway Multilingual plugin access.
	 * @param WooGateway                          $woo     Catalog and sales access.
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
		return 'mlstore/translation-work-queue';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Translation work queue', 'multilingual-store-abilities-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Ranks products that are not yet translated (published) into every requested language by their sales in a recent period, so the best sellers get translated first. Sales from all language versions of a product are summed under its original. Revenue is net revenue in the store base currency (meta.currency) from WooCommerce Analytics, with the order statuses Analytics excludes; meta.data_source says whether Analytics or raw order items were used, and meta.analytics_missing_orders how many recent orders Analytics has not imported yet.', 'multilingual-store-abilities-for-woocommerce' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_properties(): array {
		return array(
			'languages'   => self::languages_input_property(),
			'period_days' => array(
				'type'        => 'integer',
				'minimum'     => 7,
				'maximum'     => 365,
				'default'     => 90,
				'description' => __( 'Sales period in days, counted back from now.', 'multilingual-store-abilities-for-woocommerce' ),
			),
			'rank_by'     => array(
				'type'    => 'string',
				'enum'    => array( 'revenue', 'units' ),
				'default' => 'revenue',
			),
			'limit'       => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 50,
				'default' => 20,
			),
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

		$product = array(
			'type'                 => 'object',
			'properties'           => array(
				'rank'                  => array( 'type' => 'integer' ),
				'product_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Original-language product ID.', 'multilingual-store-abilities-for-woocommerce' ),
				),
				'title'                 => array( 'type' => 'string' ),
				'sku'                   => array( 'type' => array( 'string', 'null' ) ),
				'revenue'               => array(
					'type'        => 'number',
					'description' => __( 'Net revenue in the period (store base currency), all language versions.', 'multilingual-store-abilities-for-woocommerce' ),
				),
				'units_sold'            => array( 'type' => 'integer' ),
				'missing_languages'     => $codes,
				'unpublished_languages' => $codes,
				'in_progress_languages' => $codes,
				'edit_url'              => array( 'type' => 'string' ),
			),
			'required'             => array( 'rank', 'product_id', 'title', 'sku', 'revenue', 'units_sold', 'missing_languages', 'unpublished_languages', 'in_progress_languages', 'edit_url' ),
			'additionalProperties' => false,
		);

		$summary = array(
			'type'                 => 'object',
			'properties'           => array(
				'products_with_sales'     => array( 'type' => 'integer' ),
				'untranslated_with_sales' => array( 'type' => 'integer' ),
				'revenue_untranslated'    => array(
					'type'        => 'number',
					'description' => __( 'Revenue of all products with sales that are not fully translated (not only the listed ones).', 'multilingual-store-abilities-for-woocommerce' ),
				),
				'revenue_total'           => array( 'type' => 'number' ),
			),
			'required'             => array( 'products_with_sales', 'untranslated_with_sales', 'revenue_untranslated', 'revenue_total' ),
			'additionalProperties' => false,
		);

		return OutputEnvelope::object_schema(
			array(
				'products' => array(
					'type'  => 'array',
					'items' => $product,
				),
				'summary'  => $summary,
			),
			$this->meta_schema(
				array(
					'data_source'              => array(
						'type' => 'string',
						'enum' => array( SalesRanking::SOURCE_ANALYTICS, SalesRanking::SOURCE_ORDERS ),
					),
					'currency'                 => array( 'type' => 'string' ),
					'period_days'              => array( 'type' => 'integer' ),
					'period_start'             => array(
						'type'   => 'string',
						'format' => 'date-time',
					),
					'analytics_missing_orders' => array(
						'type'        => 'integer',
						'description' => __( 'Orders in the period that WooCommerce Analytics has not imported yet (they are not in the ranking).', 'multilingual-store-abilities-for-woocommerce' ),
					),
				)
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $input ): array {
		$languages = $this->resolve_languages( $input['languages'] ?? array() );
		$days      = max( 7, min( 365, is_numeric( $input['period_days'] ?? null ) ? (int) $input['period_days'] : 90 ) );
		$limit     = max( 1, min( 50, is_numeric( $input['limit'] ?? null ) ? (int) $input['limit'] : 20 ) );
		$rank_by   = 'units' === ( $input['rank_by'] ?? 'revenue' ) ? 'units' : 'revenue';

		$queue = ( new SalesRanking( $this->gateway, $this->woo ) )->queue( $languages, $days, $rank_by, $limit, $this->now() );

		return OutputEnvelope::object(
			array(
				'products' => $queue['products'],
				'summary'  => $queue['summary'],
			),
			$this->meta(
				array(
					'data_source'              => $queue['data_source'],
					'currency'                 => $this->gateway->currencies()->default_currency,
					'period_days'              => $days,
					'period_start'             => $queue['period_start'],
					'analytics_missing_orders' => $queue['analytics_missing_orders'],
				)
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function result_count( array $output ): ?int {
		return is_array( $output['products'] ?? null ) ? count( $output['products'] ) : null;
	}
}
