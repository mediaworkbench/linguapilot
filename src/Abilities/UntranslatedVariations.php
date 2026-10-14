<?php
/**
 * Ability: mlstore/untranslated-variations.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Abilities;

use Closure;
use DateTimeImmutable;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\VariationAudit;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Support\OutputEnvelope;
use MediaWorkbench\MultilingualStoreAbilities\Support\Pagination;

/**
 * Translated variable products whose variations or variation attributes are not (properly) translated.
 */
final class UntranslatedVariations extends AbstractAbility {

	private const ISSUE_TYPES = array(
		'variation_missing',
		'attribute_term_untranslated',
		'local_attribute_missing',
		'local_attribute_options_mismatch',
		'local_attribute_identical',
		'local_attribute_label_untranslated',
		'variation_attribute_value_invalid',
	);

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
		return 'mlstore/untranslated-variations';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Untranslated variations', 'multilingual-store-abilities' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Finds translated variable products whose variations or variation attributes are not properly translated, the classic cause of broken variation selectors in translated shops. Issues per product and language: variation_missing, attribute_term_untranslated, local_attribute_missing, local_attribute_options_mismatch and variation_attribute_value_invalid (severity error: shoppers are affected); local_attribute_identical and local_attribute_label_untranslated (severity review: may be intentional, e.g. sizes like S | M | L).', 'multilingual-store-abilities' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_properties(): array {
		return array_merge(
			array(
				'languages'  => self::languages_input_property(),
				'product_id' => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'description' => __( 'Limit to one variable product (any language version; reported under its original).', 'multilingual-store-abilities' ),
				),
			),
			Pagination::input_properties()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		$issue = array(
			'type'                 => 'object',
			'properties'           => array(
				'type'              => array(
					'type' => 'string',
					'enum' => self::ISSUE_TYPES,
				),
				'severity'          => array(
					'type' => 'string',
					'enum' => array( VariationAudit::ERROR, VariationAudit::REVIEW ),
				),
				'variation_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Original-language variation ID.', 'multilingual-store-abilities' ),
				),
				'taxonomy'          => array( 'type' => 'string' ),
				'term_id'           => array(
					'type'        => 'integer',
					'description' => __( 'Original-language term ID.', 'multilingual-store-abilities' ),
				),
				'term_name'         => array( 'type' => 'string' ),
				'attribute'         => array(
					'type'        => 'string',
					'description' => __( 'Attribute name (taxonomy slug for global attributes).', 'multilingual-store-abilities' ),
				),
				'original_value'    => array( 'type' => 'string' ),
				'translation_value' => array( 'type' => 'string' ),
			),
			'required'             => array( 'type', 'severity' ),
			'additionalProperties' => false,
		);

		$item = array(
			'type'                 => 'object',
			'properties'           => array(
				'product_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Original-language product ID.', 'multilingual-store-abilities' ),
				),
				'title'          => array( 'type' => 'string' ),
				'language'       => array( 'type' => 'string' ),
				'translation_id' => array( 'type' => 'integer' ),
				'edit_url'       => array(
					'type'        => 'string',
					'description' => __( 'Edit link of the translated product.', 'multilingual-store-abilities' ),
				),
				'issues'         => array(
					'type'  => 'array',
					'items' => $issue,
				),
			),
			'required'             => array( 'product_id', 'title', 'language', 'translation_id', 'edit_url', 'issues' ),
			'additionalProperties' => false,
		);

		$summary = array(
			'type'                 => 'object',
			'properties'           => array(
				'products_checked'     => array( 'type' => 'integer' ),
				'translations_checked' => array( 'type' => 'integer' ),
				'errors'               => array( 'type' => 'integer' ),
				'reviews'              => array( 'type' => 'integer' ),
			),
			'required'             => array( 'products_checked', 'translations_checked', 'errors', 'reviews' ),
			'additionalProperties' => false,
		);

		return OutputEnvelope::collection_schema( 'products', $item, $this->meta_schema(), $summary );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $input ): array {
		$languages  = $this->resolve_languages( $input['languages'] ?? array() );
		$pagination = Pagination::from_input( $input );
		$product_id = isset( $input['product_id'] ) && is_numeric( $input['product_id'] ) ? $this->original_variable_product( (int) $input['product_id'] ) : null;

		$result = ( new VariationAudit( $this->gateway, $this->woo ) )->audit( $languages, $product_id, $pagination );

		return OutputEnvelope::collection( 'products', $result['items'], $pagination, $result['total_items'], $this->meta(), $result['summary'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function result_count( array $output ): ?int {
		return is_array( $output['products'] ?? null ) ? count( $output['products'] ) : null;
	}

	/**
	 * Maps any language version of a product to its original and checks it is variable.
	 *
	 * @param int $product_id Product ID.
	 * @throws AbilityError When the product does not exist or is not a variable product.
	 */
	private function original_variable_product( int $product_id ): int {
		$original = $this->gateway->original_id( $product_id, ElementType::product() );
		if ( null === $original ) {
			/* translators: %d: Product ID. */
			throw new AbilityError( AbilityError::NOT_FOUND, sprintf( __( 'Product %d not found.', 'multilingual-store-abilities' ), $product_id ), 404 );
		}

		if ( array() === $this->woo->variable_product_ids( array( $original ) ) ) {
			/* translators: %d: Product ID. */
			throw new AbilityError( AbilityError::INVALID_INPUT, sprintf( __( 'Product %d is not a variable product.', 'multilingual-store-abilities' ), $product_id ) );
		}

		return $original;
	}
}
