<?php
/**
 * Ability: mlstore/languages-overview.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Abilities;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;
use MediaWorkbench\MultilingualStoreAbilities\Domain\StoreOverview;
use MediaWorkbench\MultilingualStoreAbilities\Support\OutputEnvelope;

/**
 * Orientation for agents: languages, currencies, synced fields and catalog size. Meant as the first call.
 */
final class LanguagesOverview extends AbstractAbility {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'mlstore/languages-overview';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return __( 'Multilingual store overview', 'multilingual-store-abilities' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return __( 'Call this first. Returns the store\'s languages (the default language is the source of translations), whether WooCommerce Multilingual and multi-currency are active, the currencies per language, which product fields are kept in sync across translations, and how many original-language products, variations, categories and tags exist. Other mlstore abilities take the language and currency codes listed here.', 'multilingual-store-abilities' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_properties(): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		$codes = array(
			'type'  => 'array',
			'items' => array( 'type' => 'string' ),
		);

		$fields = array(
			'type'  => 'array',
			'items' => array(
				'type' => 'string',
				'enum' => array_map( static fn ( ProductField $field ): string => $field->value, ProductField::cases() ),
			),
		);

		return OutputEnvelope::object_schema(
			array(
				'default_language'       => array(
					'type'        => 'string',
					'description' => __( 'Code of the default (source) language.', 'multilingual-store-abilities' ),
				),
				'languages'              => array(
					'type'  => 'array',
					'items' => self::strict_object(
						array(
							'code'         => array( 'type' => 'string' ),
							'native_name'  => array( 'type' => 'string' ),
							'english_name' => array( 'type' => 'string' ),
							'is_default'   => array( 'type' => 'boolean' ),
						)
					),
				),
				'wcml_active'            => array( 'type' => 'boolean' ),
				'multi_currency_enabled' => array( 'type' => 'boolean' ),
				'default_currency'       => array(
					'type'        => 'string',
					'description' => __( 'Store base currency; analytics revenue is reported in it.', 'multilingual-store-abilities' ),
				),
				'currencies'             => array(
					'type'  => 'array',
					'items' => self::strict_object(
						array(
							'code'       => array( 'type' => 'string' ),
							'is_default' => array( 'type' => 'boolean' ),
							'languages'  => $codes,
						)
					),
				),
				'sync_settings'          => self::strict_object(
					array(
						'synced_fields'   => $fields + array( 'description' => __( 'A difference between translations in these fields is a sync error.', 'multilingual-store-abilities' ) ),
						'unsynced_fields' => $fields + array( 'description' => __( 'These fields may legitimately differ per language.', 'multilingual-store-abilities' ) ),
					)
				),
				'catalog_size'           => self::strict_object(
					array(
						'products'           => array( 'type' => 'integer' ),
						'variations'         => array( 'type' => 'integer' ),
						'product_categories' => array( 'type' => 'integer' ),
						'product_tags'       => array( 'type' => 'integer' ),
					),
					__( 'Original-language objects only (translations not counted), any status except trash.', 'multilingual-store-abilities' )
				),
			),
			$this->meta_schema()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( array $input ): array {
		return OutputEnvelope::object(
			( new StoreOverview( $this->gateway ) )->build( $this->status()->has_wcml() ),
			$this->meta()
		);
	}

	/**
	 * Object schema with all properties required and nothing else allowed.
	 *
	 * @param array<string, array<string, mixed>> $properties  Property schemas.
	 * @param string|null                         $description Optional description.
	 * @return array<string, mixed>
	 */
	private static function strict_object( array $properties, ?string $description = null ): array {
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);

		return null === $description ? $schema : $schema + array( 'description' => $description );
	}
}
