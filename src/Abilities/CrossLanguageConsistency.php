<?php
/**
 * Ability: linguapilot/cross-language-consistency.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Abilities;

use Closure;
use DateTimeImmutable;
use MediaWorkbench\LinguaPilot\Domain\ConsistencyChecker;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductField;
use MediaWorkbench\LinguaPilot\Environment\DependencyStatus;
use MediaWorkbench\LinguaPilot\Gateway\MultilingualGateway;
use MediaWorkbench\LinguaPilot\Gateway\WooGateway;
use MediaWorkbench\LinguaPilot\Support\AuditLogger;
use MediaWorkbench\LinguaPilot\Support\OutputEnvelope;
use MediaWorkbench\LinguaPilot\Support\Pagination;

/**
 * Translations whose SKU, stock, tax class, visibility, status or prices differ from the original.
 */
final class CrossLanguageConsistency extends AbstractAbility {

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
		return 'linguapilot/cross-language-consistency';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Cross-language consistency', 'linguapilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Finds product and variation translations whose SKU, stock, tax class, catalog visibility, post status or prices differ from the original. Each difference has a severity: sync_error when the field is supposed to be kept in sync across translations (see sync_settings in linguapilot/languages-overview), so the data is out of sync; deviation when the field may legitimately differ per language. Prices are not compared for a language whose currency has manual prices on the product (use linguapilot/currency-price-gaps for those). Only translations with at least one difference are listed.', 'linguapilot' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_properties(): array {
		return array_merge(
			array(
				'fields'             => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => self::field_names(),
					),
					'uniqueItems' => true,
					'default'     => array(),
					'description' => __( 'Fields to compare. Default: all.', 'linguapilot' ),
				),
				'languages'          => self::languages_input_property(),
				'include_variations' => array(
					'type'        => 'boolean',
					'default'     => true,
					'description' => __( 'Also compare translated variations (SKU, stock, prices, enabled/disabled per variation).', 'linguapilot' ),
				),
			),
			Pagination::input_properties()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		$value = array(
			'type'        => array( 'string', 'null' ),
			'description' => __( 'Raw stored value; null when empty.', 'linguapilot' ),
		);

		$difference = array(
			'type'                 => 'object',
			'properties'           => array(
				'field'       => array(
					'type' => 'string',
					'enum' => self::field_names(),
				),
				'original'    => $value,
				'translation' => $value,
				'severity'    => array(
					'type' => 'string',
					'enum' => array( ConsistencyChecker::SYNC_ERROR, ConsistencyChecker::DEVIATION ),
				),
			),
			'required'             => array( 'field', 'original', 'translation', 'severity' ),
			'additionalProperties' => false,
		);

		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Original-language product ID (the parent product for variation rows).', 'linguapilot' ),
				),
				'variation_id'   => array(
					'type'        => array( 'integer', 'null' ),
					'description' => __( 'Original-language variation ID; null for product-level rows.', 'linguapilot' ),
				),
				'title'          => array( 'type' => 'string' ),
				'sku'            => array( 'type' => array( 'string', 'null' ) ),
				'language'       => array( 'type' => 'string' ),
				'translation_id' => array( 'type' => 'integer' ),
				'edit_url'       => array(
					'type'        => 'string',
					'description' => __( 'Edit link of the translation (for variations: of the translated parent product).', 'linguapilot' ),
				),
				'differences'    => array(
					'type'  => 'array',
					'items' => $difference,
				),
			),
			'required'             => array( 'product_id', 'variation_id', 'title', 'sku', 'language', 'translation_id', 'edit_url', 'differences' ),
			'additionalProperties' => false,
		);

		$summary = array(
			'type'                 => 'object',
			'properties'           => array(
				'products_checked'     => array( 'type' => 'integer' ),
				'variations_checked'   => array( 'type' => 'integer' ),
				'translations_checked' => array( 'type' => 'integer' ),
				'sync_errors'          => array( 'type' => 'integer' ),
				'deviations'           => array( 'type' => 'integer' ),
				'price_checks_skipped' => array(
					'type'        => 'integer',
					'description' => __( 'Translations whose prices were not compared because their currency has manual prices.', 'linguapilot' ),
				),
			),
			'required'             => array( 'products_checked', 'variations_checked', 'translations_checked', 'sync_errors', 'deviations', 'price_checks_skipped' ),
			'additionalProperties' => false,
		);

		return OutputEnvelope::collection_schema( 'translations', $item, $this->meta_schema(), $summary );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $input ): array {
		$languages  = $this->resolve_languages( $input['languages'] ?? array() );
		$pagination = Pagination::from_input( $input );
		$requested  = is_array( $input['fields'] ?? null ) ? $input['fields'] : array();
		$fields     = array() === $requested
			? ProductField::cases()
			: array_values( array_filter( ProductField::cases(), static fn ( ProductField $field ): bool => in_array( $field->value, $requested, true ) ) );

		$variations = is_bool( $input['include_variations'] ?? null ) ? $input['include_variations'] : true;

		$result = ( new ConsistencyChecker( $this->gateway, $this->woo ) )->check( $fields, $languages, $pagination, $variations );

		return OutputEnvelope::collection( 'translations', $result['items'], $pagination, $result['total_items'], $this->meta(), $result['summary'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function result_count( array $output ): ?int {
		return is_array( $output['translations'] ?? null ) ? count( $output['translations'] ) : null;
	}

	/**
	 * Public field names.
	 *
	 * @return list<string>
	 */
	private static function field_names(): array {
		return array_map( static fn ( ProductField $field ): string => $field->value, ProductField::cases() );
	}
}
