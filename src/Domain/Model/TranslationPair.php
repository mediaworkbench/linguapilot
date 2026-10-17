<?php
/**
 * An original and one of its translations.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * Original post, translated post and the translation's language.
 */
final class TranslationPair {

	/**
	 * Creates a pair.
	 *
	 * @param int    $original_id    Original post ID.
	 * @param int    $translation_id Translation post ID.
	 * @param string $language       Translation language code.
	 */
	public function __construct(
		public readonly int $original_id,
		public readonly int $translation_id,
		public readonly string $language,
	) {}
}
