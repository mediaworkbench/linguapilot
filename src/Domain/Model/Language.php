<?php
/**
 * An active site language.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * A language enabled in the multilingual plugin.
 */
final class Language {

	/**
	 * Creates a language.
	 *
	 * @param string $code         Language code as used by the multilingual plugin (not always two letters, e.g. `en-us`).
	 * @param string $native_name  Name in the language itself, e.g. `Deutsch`.
	 * @param string $english_name English name, e.g. `German`.
	 * @param bool   $is_default   Whether this is the site's default language.
	 */
	public function __construct(
		public readonly string $code,
		public readonly string $native_name,
		public readonly string $english_name,
		public readonly bool $is_default,
	) {}
}
