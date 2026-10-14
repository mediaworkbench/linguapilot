<?php
/**
 * Ability: mlstore/translation-completeness.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Abilities;

use Closure;
use DateTimeImmutable;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\TranslationAudit;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use MediaWorkbench\MultilingualStoreAbilities\Support\OutputEnvelope;
use MediaWorkbench\MultilingualStoreAbilities\Support\Pagination;

/**
 * Which catalog objects lack translations, plus coverage per language.
 */
final class TranslationCompleteness extends AbstractAbility {

	private const OBJECT_TYPES = array( 'product', 'product_cat', 'product_tag', 'attribute_term' );

	/**
	 * Creates the ability.
	 *
	 * @param MultilingualGateway                 $gateway Multilingual plugin access.
	 * @param WooGateway                          $woo     Catalog display data.
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
		return 'mlstore/translation-completeness';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Translation completeness', 'multilingual-store-abilities' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Lists original-language products, product categories, product tags or global attribute terms that are not translated into one or more languages, with a coverage summary per language across the whole catalog. A translation that exists but is not published (draft, pending, private, scheduled) is reported in unpublished_languages; one that was sent to translation but is not done yet in in_progress_languages; a missing or trashed one in missing_languages; an existing translation whose original changed since in outdated_languages. Only objects with at least one gap are listed.', 'multilingual-store-abilities' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_properties(): array {
		return array_merge(
			array(
				'object_type' => array(
					'type'        => 'string',
					'enum'        => self::OBJECT_TYPES,
					'default'     => 'product',
					'description' => __( 'What to audit. attribute_term = terms of global product attributes (pa_*).', 'multilingual-store-abilities' ),
				),
				'languages'   => self::languages_input_property(),
				'status'      => array(
					'type'        => 'string',
					'enum'        => array( 'publish', 'any' ),
					'default'     => 'publish',
					'description' => __( 'Products only: audit published originals, or originals in any status except trash.', 'multilingual-store-abilities' ),
				),
				'taxonomy'    => array(
					'type'        => 'string',
					'pattern'     => '^pa_[a-z0-9_-]+$',
					'description' => __( 'attribute_term only: limit to one attribute taxonomy, e.g. pa_color. Default: all translatable attributes.', 'multilingual-store-abilities' ),
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
				'id'                    => array(
					'type'        => 'integer',
					'description' => __( 'Original-language post or term ID.', 'multilingual-store-abilities' ),
				),
				'type'                  => array(
					'type'        => 'string',
					'description' => __( 'Post type or taxonomy, e.g. product, product_cat, pa_color.', 'multilingual-store-abilities' ),
				),
				'title'                 => array( 'type' => 'string' ),
				'sku'                   => array( 'type' => array( 'string', 'null' ) ),
				'missing_languages'     => $codes,
				'unpublished_languages' => $codes,
				'in_progress_languages' => $codes + array( 'description' => __( 'Sent to translation (open WPML translation job), not translated yet.', 'multilingual-store-abilities' ) ),
				'outdated_languages'    => $codes + array( 'description' => __( 'Translation exists, but the original changed since and it needs an update.', 'multilingual-store-abilities' ) ),
				'edit_url'              => array(
					'type'   => 'string',
					'format' => 'uri',
				),
			),
			'required'             => array( 'id', 'type', 'title', 'sku', 'missing_languages', 'unpublished_languages', 'in_progress_languages', 'outdated_languages', 'edit_url' ),
			'additionalProperties' => false,
		);

		$language_coverage = array(
			'type'                 => 'object',
			'properties'           => array(
				'language'     => array( 'type' => 'string' ),
				'translated'   => array( 'type' => 'integer' ),
				'unpublished'  => array( 'type' => 'integer' ),
				'in_progress'  => array( 'type' => 'integer' ),
				'missing'      => array( 'type' => 'integer' ),
				'outdated'     => array(
					'type'        => 'integer',
					'description' => __( 'Existing translations that need an update (already counted in translated or unpublished).', 'multilingual-store-abilities' ),
				),
				'coverage_pct' => array(
					'type'        => 'number',
					'description' => __( 'Share of originals with a published translation, in percent.', 'multilingual-store-abilities' ),
				),
			),
			'required'             => array( 'language', 'translated', 'unpublished', 'in_progress', 'missing', 'outdated', 'coverage_pct' ),
			'additionalProperties' => false,
		);

		$summary = array(
			'type'                 => 'object',
			'properties'           => array(
				'total_objects' => array(
					'type'        => 'integer',
					'description' => __( 'Original-language objects audited (all pages).', 'multilingual-store-abilities' ),
				),
				'languages'     => array(
					'type'  => 'array',
					'items' => $language_coverage,
				),
			),
			'required'             => array( 'total_objects', 'languages' ),
			'additionalProperties' => false,
		);

		return OutputEnvelope::collection_schema( 'objects', $item, $this->meta_schema(), $summary );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $input ): array {
		$languages  = $this->resolve_languages( $input['languages'] ?? array() );
		$pagination = Pagination::from_input( $input );
		$scope      = $this->scope( $input );

		$result = null === $scope
			? ( new TranslationAudit( $this->gateway, $this->woo ) )->completeness( new CatalogScope( array( ElementType::product() ) ), array(), $pagination )
			: ( new TranslationAudit( $this->gateway, $this->woo ) )->completeness( $scope, $languages, $pagination );

		return OutputEnvelope::collection( 'objects', $result['objects'], $pagination, $result['total_items'], $this->meta(), $result['summary'] );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function result_count( array $output ): ?int {
		return is_array( $output['objects'] ?? null ) ? count( $output['objects'] ) : null;
	}

	/**
	 * Builds the audit scope from the input. Null = nothing translatable to audit.
	 *
	 * @param array<string, mixed> $input Normalized input.
	 * @throws AbilityError On an unusable combination of inputs.
	 */
	private function scope( array $input ): ?CatalogScope {
		$object_type = is_string( $input['object_type'] ?? null ) ? $input['object_type'] : 'product';
		$taxonomy    = isset( $input['taxonomy'] ) && is_string( $input['taxonomy'] ) ? $input['taxonomy'] : null;

		if ( null !== $taxonomy && 'attribute_term' !== $object_type ) {
			throw AbilityError::invalid_input( esc_html__( 'taxonomy can only be used with object_type attribute_term.', 'multilingual-store-abilities' ) );
		}

		if ( 'attribute_term' === $object_type ) {
			return $this->attribute_scope( $taxonomy );
		}

		$type = match ( $object_type ) {
			'product_cat' => ElementType::product_category(),
			'product_tag' => ElementType::product_tag(),
			default       => ElementType::product(),
		};
		$this->assert_translatable( $type );

		$statuses = 'any' === ( $input['status'] ?? 'publish' ) ? CatalogScope::ANY_POST_STATUS : array( 'publish' );

		return new CatalogScope( array( $type ), $statuses );
	}

	/**
	 * Scope for global attribute terms.
	 *
	 * @param string|null $taxonomy Requested attribute taxonomy, or null for all translatable ones.
	 * @throws AbilityError When the taxonomy is not a global attribute or not translatable.
	 */
	private function attribute_scope( ?string $taxonomy ): ?CatalogScope {
		$taxonomies = $this->woo->attribute_taxonomies();

		if ( null !== $taxonomy ) {
			if ( ! in_array( $taxonomy, $taxonomies, true ) ) {
				/* translators: %s: Taxonomy slug. */
				throw AbilityError::invalid_input( esc_html( sprintf( __( '%s is not a global product attribute.', 'multilingual-store-abilities' ), $taxonomy ) ) );
			}

			$type = ElementType::taxonomy( $taxonomy );
			$this->assert_translatable( $type );

			return new CatalogScope( array( $type ) );
		}

		$types = array_values(
			array_filter(
				array_map( static fn ( string $slug ): ElementType => ElementType::taxonomy( $slug ), $taxonomies ),
				fn ( ElementType $type ): bool => $this->gateway->is_translatable( $type )
			)
		);

		return array() === $types ? null : new CatalogScope( $types );
	}

	/**
	 * Fails when the multilingual plugin does not translate a type at all.
	 *
	 * @param ElementType $type Element type.
	 * @throws AbilityError When the type is not translatable.
	 */
	private function assert_translatable( ElementType $type ): void {
		if ( ! $this->gateway->is_translatable( $type ) ) {
			/* translators: %s: Post type or taxonomy slug. */
			throw AbilityError::invalid_input( esc_html( sprintf( __( '%s is not set to be translated in WPML.', 'multilingual-store-abilities' ), $type->name() ) ) );
		}
	}
}
