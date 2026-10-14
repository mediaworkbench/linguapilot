<?php
/**
 * State of one translation of an original.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain\Model;

/**
 * Whether a language version exists and is visible to shoppers.
 *
 * The single source of truth for this rule; WpmlGateway's SQL mirrors it (see from_post_status()).
 */
enum TranslationState: string {
	case Translated  = 'translated';
	case Unpublished = 'unpublished';
	case Missing     = 'missing';

	/**
	 * Classifies a post translation by its post status.
	 *
	 * `publish` = translated; absent, `trash` or `auto-draft` = missing (not served, decision 2026-10-14);
	 * anything else (draft, pending, private, future, custom) = present but unpublished.
	 *
	 * @param string|null $post_status Post status, or null when there is no translation.
	 */
	public static function from_post_status( ?string $post_status ): self {
		return match ( true ) {
			'publish' === $post_status => self::Translated,
			null === $post_status, 'trash' === $post_status, 'auto-draft' === $post_status => self::Missing,
			default => self::Unpublished,
		};
	}
}
