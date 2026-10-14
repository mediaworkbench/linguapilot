<?php
/**
 * In-memory multilingual gateway for unit tests.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoveragePage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoverageRow;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Currency;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CurrencySettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\IdPage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\LanguageCoverage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\SyncSettings;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationPair;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;

/**
 * A configurable store: languages, translation groups, sync and currency settings, manual prices.
 *
 * Defaults mirror the concept's demo store: DE (default), EN, FR; multi-currency off; nothing synced.
 */
final class FakeMultilingualGateway implements MultilingualGateway {

	/**
	 * Enabled languages.
	 *
	 * @var list<Language>
	 */
	private array $languages;

	/**
	 * Elements by type: object ID => language, group key, whether it is the original, post status.
	 *
	 * @var array<string, array<int, array{language: string, group: int, original: bool, status: string|null}>>
	 */
	private array $elements = array();

	/**
	 * Counter for translation group keys.
	 *
	 * @var int
	 */
	private int $next_group = 1;

	/**
	 * Sync settings returned by {@see self::sync_settings()}.
	 *
	 * @var SyncSettings
	 */
	private SyncSettings $sync;

	/**
	 * Currency settings returned by {@see self::currencies()}.
	 *
	 * @var CurrencySettings
	 */
	private CurrencySettings $currencies;

	/**
	 * Manual prices by original ID: currency => regular price (null = none set).
	 *
	 * @var array<int, array<string, string|null>>
	 */
	private array $manual_prices = array();

	/**
	 * Whether setup is complete.
	 *
	 * @var bool
	 */
	private bool $setup_complete = true;

	/**
	 * Element type values that are not translatable.
	 *
	 * @var list<string>
	 */
	private array $untranslatable = array();

	/**
	 * Creates the fake.
	 *
	 * @param list<Language>|null $languages Languages, default first. Null = DE (default), EN, FR.
	 */
	public function __construct( ?array $languages = null ) {
		$this->languages  = $languages ?? array(
			new Language( 'de', 'Deutsch', 'German', true ),
			new Language( 'en', 'English', 'English', false ),
			new Language( 'fr', 'Français', 'French', false ),
		);
		$this->sync       = new SyncSettings( array() );
		$this->currencies = new CurrencySettings( false, 'EUR', array( new Currency( 'EUR', array() ) ) );
	}

	/**
	 * Adds a translation group.
	 *
	 * @param ElementType           $type     Element type.
	 * @param array<string, int>    $members  Language code => object ID.
	 * @param string|null           $source   Language of the original. Null = default language.
	 * @param array<string, string> $statuses Language code => post status (posts only). Missing = `publish`.
	 */
	public function add_group( ElementType $type, array $members, ?string $source = null, array $statuses = array() ): self {
		$source = $source ?? $this->default_language();
		$group  = $this->next_group++;

		foreach ( $members as $language => $id ) {
			$this->elements[ $type->value ][ $id ] = array(
				'language' => $language,
				'group'    => $group,
				'original' => $language === $source,
				'status'   => $type->is_term() ? null : ( $statuses[ $language ] ?? 'publish' ),
			);
		}

		return $this;
	}

	/**
	 * Sets the sync settings.
	 *
	 * @param SyncSettings $sync Settings.
	 */
	public function set_sync_settings( SyncSettings $sync ): self {
		$this->sync = $sync;
		return $this;
	}

	/**
	 * Sets the currency settings.
	 *
	 * @param CurrencySettings $currencies Settings.
	 */
	public function set_currencies( CurrencySettings $currencies ): self {
		$this->currencies = $currencies;
		return $this;
	}

	/**
	 * Enables manual prices on an original.
	 *
	 * @param int                        $original_id Original product or variation ID.
	 * @param array<string, string|null> $prices     Currency => regular price (null = not set).
	 */
	public function set_manual_prices( int $original_id, array $prices ): self {
		$this->manual_prices[ $original_id ] = $prices;
		return $this;
	}

	/**
	 * Sets whether setup is complete.
	 *
	 * @param bool $complete Setup state.
	 */
	public function set_setup_complete( bool $complete ): self {
		$this->setup_complete = $complete;
		return $this;
	}

	/**
	 * Marks an element type as not translatable.
	 *
	 * @param ElementType $type Element type.
	 */
	public function set_untranslatable( ElementType $type ): self {
		$this->untranslatable[] = $type->value;
		return $this;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_translatable( ElementType $type ): bool {
		return ! in_array( $type->value, $this->untranslatable, true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function coverage( CatalogScope $scope, array $languages ): array {
		$rows     = $this->coverage_rows( $scope, $languages );
		$coverage = array();

		foreach ( $languages as $language ) {
			$counts = array_fill_keys( array( 'translated', 'unpublished', 'missing' ), 0 );
			foreach ( $rows as $row ) {
				++$counts[ $row->states[ $language ]->value ];
			}
			$coverage[] = new LanguageCoverage( $language, $counts['translated'], $counts['unpublished'], $counts['missing'] );
		}

		return $coverage;
	}

	/**
	 * {@inheritDoc}
	 */
	public function incomplete_originals( CatalogScope $scope, array $languages, int $limit, int $offset ): CoveragePage {
		$incomplete = array_values(
			array_filter(
				$this->coverage_rows( $scope, $languages ),
				static fn ( CoverageRow $row ): bool => count( $row->languages_in( TranslationState::Translated ) ) < count( $row->states )
			)
		);

		return new CoveragePage( array_slice( $incomplete, max( 0, $offset ), max( 1, min( 100, $limit ) ) ), count( $incomplete ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function translation_pairs( CatalogScope $scope, array $languages ): array {
		$type  = $scope->types[0];
		$pairs = array();
		foreach ( $this->original_ids( $type, 100, 0, $scope->post_statuses )->ids as $original ) {
			$group = $this->translations( $original, $type );
			foreach ( $languages as $language ) {
				$member = $group[ $language ] ?? null;
				if ( null === $member || $member === $original ) {
					continue;
				}

				$status = $this->elements[ $type->value ][ $member ]['status'];
				if ( TranslationState::Missing !== TranslationState::from_post_status( $status ) ) {
					$pairs[] = new TranslationPair( $original, $member, $language );
				}
			}
		}

		return $pairs;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_setup_complete(): bool {
		return $this->setup_complete;
	}

	/**
	 * {@inheritDoc}
	 */
	public function active_languages(): array {
		return $this->languages;
	}

	/**
	 * {@inheritDoc}
	 */
	public function default_language(): string {
		foreach ( $this->languages as $language ) {
			if ( $language->is_default ) {
				return $language->code;
			}
		}

		return '';
	}

	/**
	 * {@inheritDoc}
	 */
	public function element_language( int $id, ElementType $type ): ?string {
		return $this->elements[ $type->value ][ $id ]['language'] ?? null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function translations( int $id, ElementType $type ): array {
		$group = $this->elements[ $type->value ][ $id ]['group'] ?? null;
		if ( null === $group ) {
			return array();
		}

		$translations = array();
		foreach ( $this->elements[ $type->value ] as $member_id => $element ) {
			if ( $element['group'] === $group ) {
				$translations[ $element['language'] ] = $member_id;
			}
		}

		ksort( $translations );

		return $translations;
	}

	/**
	 * {@inheritDoc}
	 */
	public function translations_of( array $ids, ElementType $type ): array {
		$result = array();
		foreach ( $ids as $id ) {
			$result[ $id ] = $this->translations( $id, $type );
		}

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	public function original_id( int $id, ElementType $type ): ?int {
		foreach ( $this->translations( $id, $type ) as $member_id ) {
			if ( $this->elements[ $type->value ][ $member_id ]['original'] ) {
				return $member_id;
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function original_ids( ElementType $type, int $limit, int $offset, array $post_statuses = array() ): IdPage {
		$ids = array();
		foreach ( $this->elements[ $type->value ] ?? array() as $id => $element ) {
			if ( ! $element['original'] ) {
				continue;
			}

			$status = $element['status'];
			if ( null !== $status && ( array() === $post_statuses ? in_array( $status, array( 'trash', 'auto-draft' ), true ) : ! in_array( $status, $post_statuses, true ) ) ) {
				continue;
			}

			$ids[] = $id;
		}

		sort( $ids );

		return new IdPage( array_slice( $ids, max( 0, $offset ), max( 1, min( 100, $limit ) ) ), count( $ids ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function sync_settings(): SyncSettings {
		return $this->sync;
	}

	/**
	 * {@inheritDoc}
	 */
	public function currencies(): CurrencySettings {
		return $this->currencies;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has_manual_prices( int $product_id ): bool {
		return isset( $this->manual_prices[ $this->original_product_id( $product_id ) ] );
	}

	/**
	 * {@inheritDoc}
	 */
	public function manual_regular_price( int $product_id, string $currency ): ?string {
		return $this->manual_prices[ $this->original_product_id( $product_id ) ][ $currency ] ?? null;
	}

	/**
	 * Post status of an element (test helper for building fake Woo data).
	 *
	 * @param int         $id   Object ID.
	 * @param ElementType $type Element type.
	 */
	public function post_status( int $id, ElementType $type ): ?string {
		return $this->elements[ $type->value ][ $id ]['status'] ?? null;
	}

	/**
	 * Coverage rows of every original in a scope, ordered by original ID.
	 *
	 * @param CatalogScope $scope     Scope.
	 * @param list<string> $languages Language codes.
	 * @return list<CoverageRow>
	 */
	private function coverage_rows( CatalogScope $scope, array $languages ): array {
		$rows = array();
		foreach ( $scope->types as $type ) {
			foreach ( $this->elements[ $type->value ] ?? array() as $id => $element ) {
				if ( ! $element['original'] || ( ! $type->is_term() && ! in_array( $element['status'], $scope->post_statuses, true ) ) ) {
					continue;
				}

				$group  = $this->translations( $id, $type );
				$states = array();
				foreach ( $languages as $language ) {
					$member = $group[ $language ] ?? null;
					if ( $type->is_term() ) {
						$states[ $language ] = null === $member ? TranslationState::Missing : TranslationState::Translated;
					} else {
						$states[ $language ] = TranslationState::from_post_status( null === $member ? null : $this->elements[ $type->value ][ $member ]['status'] );
					}
				}

				$rows[ $id ] = new CoverageRow( $id, $type, $states );
			}
		}

		ksort( $rows );

		return array_values( $rows );
	}

	/**
	 * Original of a product or variation, falling back to the ID itself.
	 *
	 * @param int $product_id Product or variation ID.
	 */
	private function original_product_id( int $product_id ): int {
		return $this->original_id( $product_id, ElementType::product() )
			?? $this->original_id( $product_id, ElementType::variation() )
			?? $product_id;
	}
}
