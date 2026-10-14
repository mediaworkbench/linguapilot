<?php
/**
 * Multilingual plugin access contract.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoveragePage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\IdPage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\LanguageCoverage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationPair;

/**
 * Everything the plugin needs to know from the multilingual plugin, behind one interface.
 *
 * IDs are always WordPress object IDs: post IDs for post types, term IDs for taxonomies.
 * All methods are read-only.
 */
interface MultilingualGateway {

	/**
	 * Whether the multilingual plugin's setup is finished.
	 */
	public function is_setup_complete(): bool;

	/**
	 * Enabled languages, default language first.
	 *
	 * @return list<Language>
	 */
	public function active_languages(): array;

	/**
	 * Code of the site's default language.
	 */
	public function default_language(): string;

	/**
	 * Language code of an element, or null when the element has no language.
	 *
	 * @param int         $id   Object ID.
	 * @param ElementType $type Element type.
	 */
	public function element_language( int $id, ElementType $type ): ?string;

	/**
	 * The element's translation group, including the element itself.
	 *
	 * @param int         $id   Object ID of any member of the group.
	 * @param ElementType $type Element type.
	 * @return array<string, int> Language code => object ID. Empty when the element has no language.
	 */
	public function translations( int $id, ElementType $type ): array;

	/**
	 * Translation groups for many elements at once.
	 *
	 * @param list<int>   $ids  Object IDs.
	 * @param ElementType $type Element type.
	 * @return array<int, array<string, int>> Object ID => (language code => object ID).
	 */
	public function translations_of( array $ids, ElementType $type ): array;

	/**
	 * ID of the original (source-language) element, or null when the element has no language.
	 *
	 * @param int         $id   Object ID of any member of the group.
	 * @param ElementType $type Element type.
	 */
	public function original_id( int $id, ElementType $type ): ?int;

	/**
	 * One page of original (source-language) elements, ordered by ID.
	 *
	 * @param ElementType  $type          Element type.
	 * @param int          $limit         Page size (1-100).
	 * @param int          $offset        Number of elements to skip.
	 * @param list<string> $post_statuses Restrict post types to these statuses; empty = any except trash and auto-draft. Ignored for terms.
	 */
	public function original_ids( ElementType $type, int $limit, int $offset, array $post_statuses = array() ): IdPage;

	/**
	 * Whether the multilingual plugin is set to translate this post type or taxonomy.
	 *
	 * @param ElementType $type Element type.
	 */
	public function is_translatable( ElementType $type ): bool;

	/**
	 * Translation coverage per language across every original in the scope.
	 *
	 * States follow {@see \MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState::classify()},
	 * including open WPML translation jobs. An original in one of the languages counts for itself.
	 *
	 * @param CatalogScope $scope     Originals to look at.
	 * @param list<string> $languages Language codes.
	 * @return list<LanguageCoverage> One entry per language, in the given order.
	 */
	public function coverage( CatalogScope $scope, array $languages ): array;

	/**
	 * One page of originals with a gap: not translated (published) in at least one of the languages,
	 * or with an outdated translation ({@see \MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoverageRow::has_gap()}).
	 *
	 * @param CatalogScope $scope     Originals to look at.
	 * @param list<string> $languages Language codes.
	 * @param int          $limit     Page size (1-100).
	 * @param int          $offset    Number of originals to skip.
	 */
	public function incomplete_originals( CatalogScope $scope, array $languages, int $limit, int $offset ): CoveragePage;

	/**
	 * Every existing translation of every original post in the scope, in the given languages.
	 *
	 * "Existing" = any post status except trash and auto-draft (TranslationState not Missing).
	 * Ordered by original ID, then by the order of $languages. Post scopes only.
	 *
	 * @param CatalogScope $scope     One post type plus the statuses originals must have.
	 * @param list<string> $languages Translation language codes.
	 * @return list<TranslationPair>
	 */
	public function translation_pairs( CatalogScope $scope, array $languages ): array;

	/**
	 * WCML's attribute label translations stored on a translated product.
	 *
	 * @param int $translation_id Translated product ID.
	 * @return array<string, array<string, string>> Language => attribute key => label.
	 */
	public function attribute_label_translations( int $translation_id ): array;

	/**
	 * Which product fields are kept in sync across translations.
	 */
	public function sync_settings(): SyncSettings;

	/**
	 * Multi-currency configuration.
	 */
	public function currencies(): CurrencySettings;

	/**
	 * Whether manual per-currency prices are enabled for a product or variation (read from its original).
	 *
	 * @param int $product_id Product or variation ID, any language.
	 */
	public function has_manual_prices( int $product_id ): bool;

	/**
	 * Manual regular price in a currency (read from the original), or null when none is set.
	 *
	 * @param int    $product_id Product or variation ID, any language.
	 * @param string $currency   ISO 4217 code.
	 */
	public function manual_regular_price( int $product_id, string $currency ): ?string;
}
