<?php
/**
 * Translation coverage of one language.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain\Model;

/**
 * How many originals are translated, unpublished or missing in one language.
 */
final class LanguageCoverage {

	/**
	 * Creates the coverage.
	 *
	 * @param string $language    Language code.
	 * @param int    $translated  Published translations.
	 * @param int    $unpublished Translations that exist but are not published.
	 * @param int    $missing     Originals without a usable translation.
	 */
	public function __construct(
		public readonly string $language,
		public readonly int $translated,
		public readonly int $unpublished,
		public readonly int $missing,
	) {}

	/**
	 * Number of originals.
	 */
	public function total(): int {
		return $this->translated + $this->unpublished + $this->missing;
	}

	/**
	 * Share of published translations in percent, one decimal. 100 when there is nothing to translate.
	 */
	public function coverage_pct(): float {
		return 0 === $this->total() ? 100.0 : round( $this->translated / $this->total() * 100, 1 );
	}
}
