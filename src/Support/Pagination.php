<?php
/**
 * Bounded pagination.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Support;

/**
 * Page and page size, clamped so no ability can return unbounded data.
 */
final class Pagination {

	public const DEFAULT_PER_PAGE = 50;
	public const MAX_PER_PAGE     = 100;

	/**
	 * Creates a pagination.
	 *
	 * @param int $page     1-based page number.
	 * @param int $per_page Page size, 1 to MAX_PER_PAGE.
	 */
	private function __construct(
		public readonly int $page,
		public readonly int $per_page,
	) {}

	/**
	 * Reads `page` and `per_page` from ability input, clamping instead of rejecting.
	 *
	 * @param array<string, mixed> $input            Ability input.
	 * @param int                  $default_per_page Page size when none is given.
	 */
	public static function from_input( array $input, int $default_per_page = self::DEFAULT_PER_PAGE ): self {
		$page     = is_numeric( $input['page'] ?? null ) ? (int) $input['page'] : 1;
		$per_page = is_numeric( $input['per_page'] ?? null ) ? (int) $input['per_page'] : $default_per_page;

		return new self( max( 1, $page ), self::clamp_per_page( $per_page ) );
	}

	/**
	 * Number of items to skip.
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}

	/**
	 * Number of pages for a total item count (0 when there are no items).
	 *
	 * @param int $total_items Total matching items.
	 */
	public function total_pages( int $total_items ): int {
		return $total_items <= 0 ? 0 : (int) ceil( $total_items / $this->per_page );
	}

	/**
	 * Input schema properties `page` and `per_page`.
	 *
	 * @param int $default_per_page Default page size.
	 * @return array<string, array<string, mixed>>
	 */
	public static function input_properties( int $default_per_page = self::DEFAULT_PER_PAGE ): array {
		return array(
			'page'     => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'default'     => 1,
				'description' => __( 'Page number to return (1-indexed).', 'linguapilot' ),
			),
			'per_page' => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'maximum'     => self::MAX_PER_PAGE,
				'default'     => self::clamp_per_page( $default_per_page ),
				'description' => __( 'Maximum number of items per page.', 'linguapilot' ),
			),
		);
	}

	/**
	 * Clamps a page size to 1..MAX_PER_PAGE.
	 *
	 * @param int $per_page Requested page size.
	 */
	private static function clamp_per_page( int $per_page ): int {
		return max( 1, min( self::MAX_PER_PAGE, $per_page ) );
	}
}
