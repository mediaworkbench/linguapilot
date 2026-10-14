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
	case InProgress  = 'in_progress';
	case Missing     = 'missing';

	/**
	 * WPML job statuses (ICL_TM_*) that mean "a translation is on its way": waiting for translator (1),
	 * in progress (2), ready to download (4), needs review (30), ATE needs retry (40), pending at a
	 * translation service (102). Verified against WPML 5.1.0 inc/constants.php (docs/api-notes.md §14).
	 */
	public const OPEN_JOB_STATUSES = array( 1, 2, 4, 30, 40, 102 );

	/**
	 * Classifies one language of an original, including WPML translation jobs.
	 *
	 * Without a translated element, an open job means in progress, otherwise missing. With one, terms are
	 * translated and posts follow from_post_status(). WpmlGateway's SQL feeds exactly these inputs.
	 *
	 * @param bool        $is_term        Whether the element is a term.
	 * @param bool        $element_exists Whether the translated post or term exists.
	 * @param string|null $post_status    Post status of the translation (posts only).
	 * @param int|null    $job_status     WPML translation job status, null when there is no job.
	 */
	public static function classify( bool $is_term, bool $element_exists, ?string $post_status, ?int $job_status ): self {
		if ( ! $element_exists ) {
			return null !== $job_status && in_array( $job_status, self::OPEN_JOB_STATUSES, true ) ? self::InProgress : self::Missing;
		}

		return $is_term ? self::Translated : self::from_post_status( $post_status );
	}

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
