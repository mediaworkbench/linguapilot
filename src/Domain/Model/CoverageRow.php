<?php
/**
 * Translation states of one original.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain\Model;

/**
 * An original and the state of each requested language.
 */
final class CoverageRow {

	/**
	 * Creates a row.
	 *
	 * @param int                             $original_id Original object ID (post ID or term ID).
	 * @param ElementType                     $type        Element type of the original.
	 * @param array<string, TranslationState> $states      Language code => state, for every requested language.
	 * @param list<string>                    $outdated    Languages whose existing translation needs an update.
	 */
	public function __construct(
		public readonly int $original_id,
		public readonly ElementType $type,
		public readonly array $states,
		public readonly array $outdated = array(),
	) {}

	/**
	 * Whether the original has a gap: a language not translated (published), or an outdated translation.
	 */
	public function has_gap(): bool {
		return count( $this->languages_in( TranslationState::Translated ) ) < count( $this->states ) || array() !== $this->outdated;
	}

	/**
	 * Languages in a given state, in the order they were requested.
	 *
	 * @param TranslationState $state State to select.
	 * @return list<string>
	 */
	public function languages_in( TranslationState $state ): array {
		return array_keys( array_filter( $this->states, static fn ( TranslationState $candidate ): bool => $candidate === $state ) );
	}
}
