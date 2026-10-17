<?php
/**
 * Variation and attribute translation audit.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain;

use MediaWorkbench\LinguaPilot\Domain\Model\CatalogScope;
use MediaWorkbench\LinguaPilot\Domain\Model\ElementType;
use MediaWorkbench\LinguaPilot\Domain\Model\ProductAttribute;
use MediaWorkbench\LinguaPilot\Domain\Model\TermRef;
use MediaWorkbench\LinguaPilot\Domain\Model\TranslationPair;
use MediaWorkbench\LinguaPilot\Gateway\MultilingualGateway;
use MediaWorkbench\LinguaPilot\Gateway\WooGateway;
use MediaWorkbench\LinguaPilot\Support\Pagination;

/**
 * Finds translated variable products whose variations or variation attributes are not (properly) translated:
 * the classic cause of broken variation selectors in translated shops.
 *
 * Only attributes used for variations are checked. Issue types and severities: docs/api-notes.md §5.10.
 */
final class VariationAudit {

	public const ERROR  = 'error';
	public const REVIEW = 'review';

	/**
	 * Originals processed per batch.
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Memo of translatable attribute taxonomies.
	 *
	 * @var array<string, bool>
	 */
	private array $translatable = array();

	/**
	 * Creates the service.
	 *
	 * @param MultilingualGateway $gateway Multilingual plugin access.
	 * @param WooGateway          $woo     Catalog access.
	 */
	public function __construct(
		private readonly MultilingualGateway $gateway,
		private readonly WooGateway $woo,
	) {}

	/**
	 * Audits every translation of every variable product (or of one product).
	 *
	 * @param list<string> $languages  Translation languages (validated by the caller).
	 * @param int|null     $product_id Original product to limit to.
	 * @param Pagination   $pagination Page of (product, language) rows with issues.
	 * @return array{
	 *     items: list<array{product_id: int, title: string, language: string, translation_id: int, edit_url: string, issues: list<array<string, int|string>>}>,
	 *     total_items: int,
	 *     summary: array{products_checked: int, translations_checked: int, errors: int, reviews: int}
	 * }
	 */
	public function audit( array $languages, ?int $product_id, Pagination $pagination ): array {
		$pairs = array() === $languages ? array() : $this->gateway->translation_pairs( new CatalogScope( array( ElementType::product() ), CatalogScope::ANY_POST_STATUS ), $languages );
		if ( null !== $product_id ) {
			$pairs = array_values( array_filter( $pairs, static fn ( TranslationPair $pair ): bool => $pair->original_id === $product_id ) );
		}

		$variable = array_flip( $this->woo->variable_product_ids( array_values( array_unique( array_map( static fn ( TranslationPair $pair ): int => $pair->original_id, $pairs ) ) ) ) );
		$pairs    = array_values( array_filter( $pairs, static fn ( TranslationPair $pair ): bool => isset( $variable[ $pair->original_id ] ) ) );

		$found = array();
		foreach ( array_chunk( $pairs, self::BATCH_SIZE ) as $batch ) {
			$found = array_merge( $found, $this->audit_batch( $batch ) );
		}

		$order = array_flip( $languages );
		usort(
			$found,
			static fn ( array $a, array $b ): int => array( $a['pair']->original_id, $order[ $a['pair']->language ] ?? PHP_INT_MAX )
				<=> array( $b['pair']->original_id, $order[ $b['pair']->language ] ?? PHP_INT_MAX )
		);

		$errors  = 0;
		$reviews = 0;
		foreach ( $found as $entry ) {
			foreach ( $entry['issues'] as $issue ) {
				self::ERROR === $issue['severity'] ? ++$errors : ++$reviews;
			}
		}

		$page = array_slice( $found, $pagination->offset(), $pagination->per_page );
		$ids  = array();
		foreach ( $page as $entry ) {
			$ids[] = $entry['pair']->original_id;
			$ids[] = $entry['pair']->translation_id;
		}
		$items = $this->woo->describe_posts( array_values( array_unique( $ids ) ) );

		$result = array();
		foreach ( $page as $entry ) {
			$pair        = $entry['pair'];
			$original    = $items[ $pair->original_id ] ?? null;
			$translation = $items[ $pair->translation_id ] ?? null;
			$result[]    = array(
				'product_id'     => $pair->original_id,
				'title'          => null === $original ? '' : $original->title,
				'language'       => $pair->language,
				'translation_id' => $pair->translation_id,
				'edit_url'       => null === $translation ? '' : $translation->edit_url,
				'issues'         => $entry['issues'],
			);
		}

		return array(
			'items'       => $result,
			'total_items' => count( $found ),
			'summary'     => array(
				'products_checked'     => count( array_unique( array_map( static fn ( TranslationPair $pair ): int => $pair->original_id, $pairs ) ) ),
				'translations_checked' => count( $pairs ),
				'errors'               => $errors,
				'reviews'              => $reviews,
			),
		);
	}

	/**
	 * Audits one batch of pairs; loads all data the batch needs up front.
	 *
	 * @param list<TranslationPair> $batch Pairs.
	 * @return list<array{pair: TranslationPair, issues: list<array<string, int|string>>}>
	 */
	private function audit_batch( array $batch ): array {
		$products = array();
		foreach ( $batch as $pair ) {
			$products[] = $pair->original_id;
			$products[] = $pair->translation_id;
		}
		$products = array_values( array_unique( $products ) );

		$attributes = $this->woo->product_attributes( $products );
		$children   = $this->woo->children( $products );

		$original_variations = array();
		$all_variations      = array();
		foreach ( $batch as $pair ) {
			$original_variations = array_merge( $original_variations, $children[ $pair->original_id ] ?? array() );
			$all_variations      = array_merge( $all_variations, $children[ $pair->translation_id ] ?? array() );
		}
		$variation_groups = $this->gateway->translations_of( array_values( array_unique( $original_variations ) ), ElementType::variation() );
		$variation_values = $this->woo->variation_attribute_values( array_values( array_unique( $all_variations ) ) );

		$taxonomies = array();
		foreach ( $batch as $pair ) {
			foreach ( self::variation_attributes( $attributes[ $pair->original_id ] ?? array() ) as $attribute ) {
				if ( $attribute->is_taxonomy && $this->is_translatable( $attribute->key ) ) {
					$taxonomies[ $attribute->key ] = true;
				}
			}
		}
		$taxonomies = array_keys( $taxonomies );
		$terms      = array() === $taxonomies ? array() : $this->woo->object_terms( $products, $taxonomies );

		$term_groups = array();
		foreach ( $taxonomies as $taxonomy ) {
			$term_ids = array();
			foreach ( $batch as $pair ) {
				foreach ( $terms[ $pair->original_id ][ $taxonomy ] ?? array() as $term ) {
					$term_ids[] = $term->id;
				}
			}
			$term_groups[ $taxonomy ] = $this->gateway->translations_of( array_values( array_unique( $term_ids ) ), ElementType::taxonomy( $taxonomy ) );
		}

		$found = array();
		foreach ( $batch as $pair ) {
			$issues = $this->issues( $pair, $attributes, $children, $variation_groups, $variation_values, $terms, $term_groups, $taxonomies );
			if ( array() !== $issues ) {
				$found[] = array(
					'pair'   => $pair,
					'issues' => $issues,
				);
			}
		}

		return $found;
	}

	/**
	 * Issues of one translation, in a stable order: missing variations, untranslated terms, local attributes, invalid values.
	 *
	 * @param TranslationPair                               $pair             Pair.
	 * @param array<int, list<ProductAttribute>>            $attributes       Attributes by product.
	 * @param array<int, list<int>>                         $children         Variations by parent.
	 * @param array<int, array<string, int>>                $variation_groups Original variation => language => variation.
	 * @param array<int, array<string, string>>             $variation_values Variation => attribute meta.
	 * @param array<int, array<string, list<TermRef>>>      $terms            Product => taxonomy => assigned terms.
	 * @param array<string, array<int, array<string, int>>> $term_groups      Taxonomy => original term => language => term.
	 * @param list<string>                                  $taxonomies       Translatable taxonomies in use.
	 * @return list<array<string, int|string>>
	 */
	private function issues( TranslationPair $pair, array $attributes, array $children, array $variation_groups, array $variation_values, array $terms, array $term_groups, array $taxonomies ): array {
		$language            = $pair->language;
		$original_attributes = self::variation_attributes( $attributes[ $pair->original_id ] ?? array() );
		$translated_by_key   = array();
		foreach ( $attributes[ $pair->translation_id ] ?? array() as $attribute ) {
			$translated_by_key[ $attribute->key ] = $attribute;
		}
		$translated_children = $children[ $pair->translation_id ] ?? array();

		$missing = array();
		$mapped  = array();
		foreach ( $children[ $pair->original_id ] ?? array() as $variation ) {
			$translated = $variation_groups[ $variation ][ $language ] ?? null;
			if ( null === $translated || ! in_array( $translated, $translated_children, true ) ) {
				$missing[] = array(
					'type'         => 'variation_missing',
					'severity'     => self::ERROR,
					'variation_id' => $variation,
				);
			} else {
				$mapped[ $variation ] = $translated;
			}
		}

		$untranslated_terms = array();
		$local              = array();
		$labels             = $this->gateway->attribute_label_translations( $pair->translation_id )[ $language ] ?? array();
		foreach ( $original_attributes as $attribute ) {
			if ( $attribute->is_taxonomy ) {
				if ( ! in_array( $attribute->key, $taxonomies, true ) ) {
					continue;
				}
				foreach ( $terms[ $pair->original_id ][ $attribute->key ] ?? array() as $term ) {
					if ( ! isset( $term_groups[ $attribute->key ][ $term->id ][ $language ] ) ) {
						$untranslated_terms[] = array(
							'type'      => 'attribute_term_untranslated',
							'severity'  => self::ERROR,
							'taxonomy'  => $attribute->key,
							'term_id'   => $term->id,
							'term_name' => $term->name,
						);
					}
				}
				continue;
			}

			$translated = $translated_by_key[ $attribute->key ] ?? null;
			if ( null === $translated ) {
				$local[] = array(
					'type'      => 'local_attribute_missing',
					'severity'  => self::ERROR,
					'attribute' => $attribute->name,
				);
				continue;
			}

			if ( count( $attribute->options ) !== count( $translated->options ) ) {
				$local[] = array(
					'type'              => 'local_attribute_options_mismatch',
					'severity'          => self::ERROR,
					'attribute'         => $attribute->name,
					'original_value'    => implode( ' | ', $attribute->options ),
					'translation_value' => implode( ' | ', $translated->options ),
				);
			} elseif ( $attribute->options === $translated->options ) {
				$local[] = array(
					'type'           => 'local_attribute_identical',
					'severity'       => self::REVIEW,
					'attribute'      => $attribute->name,
					'original_value' => implode( ' | ', $attribute->options ),
				);
			}

			$label = $labels[ $attribute->key ] ?? '';
			if ( '' === $label || $label === $attribute->name ) {
				$local[] = array(
					'type'      => 'local_attribute_label_untranslated',
					'severity'  => self::REVIEW,
					'attribute' => $attribute->name,
				);
			}
		}

		$invalid = array();
		foreach ( $mapped as $original_variation => $translated_variation ) {
			foreach ( $original_attributes as $attribute ) {
				$value = $variation_values[ $translated_variation ][ 'attribute_' . $attribute->key ] ?? '';
				if ( '' === $value ) {
					continue; // "Any" value.
				}

				if ( $attribute->is_taxonomy ) {
					if ( ! in_array( $attribute->key, $taxonomies, true ) ) {
						continue; // Not translated by WPML: terms are shared across languages.
					}
					$valid = array_map( static fn ( TermRef $term ): string => $term->slug, $terms[ $pair->translation_id ][ $attribute->key ] ?? array() );
				} else {
					$translated = $translated_by_key[ $attribute->key ] ?? null;
					if ( null === $translated ) {
						continue; // Already reported as local_attribute_missing.
					}
					$valid = $translated->options;
				}

				if ( ! in_array( $value, $valid, true ) ) {
					$invalid[] = array(
						'type'              => 'variation_attribute_value_invalid',
						'severity'          => self::ERROR,
						'variation_id'      => $original_variation,
						'attribute'         => $attribute->name,
						'translation_value' => $value,
					);
				}
			}
		}

		return array_merge( $missing, $untranslated_terms, $local, $invalid );
	}

	/**
	 * Attributes used for variations.
	 *
	 * @param list<ProductAttribute> $attributes Attributes.
	 * @return list<ProductAttribute>
	 */
	private static function variation_attributes( array $attributes ): array {
		return array_values( array_filter( $attributes, static fn ( ProductAttribute $attribute ): bool => $attribute->is_variation ) );
	}

	/**
	 * Whether WPML translates an attribute taxonomy (memoized).
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	private function is_translatable( string $taxonomy ): bool {
		return $this->translatable[ $taxonomy ] ??= $this->gateway->is_translatable( ElementType::taxonomy( $taxonomy ) );
	}
}
