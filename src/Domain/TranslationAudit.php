<?php
/**
 * Translation audit service.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogItem;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CatalogScope;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\CoverageRow;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\LanguageCoverage;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\TranslationState;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WooGateway;
use MediaWorkbench\MultilingualStoreAbilities\Support\Pagination;

/**
 * Finds originals whose translations are missing or unpublished.
 */
final class TranslationAudit {

	/**
	 * Creates the service.
	 *
	 * @param MultilingualGateway $gateway Multilingual plugin access.
	 * @param WooGateway          $woo     Catalog display data.
	 */
	public function __construct(
		private readonly MultilingualGateway $gateway,
		private readonly WooGateway $woo,
	) {}

	/**
	 * Translation completeness of a scope.
	 *
	 * @param CatalogScope $scope      Originals to audit.
	 * @param list<string> $languages  Target languages (validated by the caller).
	 * @param Pagination   $pagination Page of incomplete originals to return.
	 * @return array{
	 *     objects: list<array{id: int, type: string, title: string, sku: string|null, missing_languages: list<string>, unpublished_languages: list<string>, in_progress_languages: list<string>, outdated_languages: list<string>, edit_url: string}>,
	 *     total_items: int,
	 *     summary: array{total_objects: int, languages: list<array{language: string, translated: int, unpublished: int, in_progress: int, missing: int, outdated: int, coverage_pct: float}>}
	 * }
	 */
	public function completeness( CatalogScope $scope, array $languages, Pagination $pagination ): array {
		if ( array() === $languages ) {
			return array(
				'objects'     => array(),
				'total_items' => 0,
				'summary'     => array(
					'total_objects' => 0,
					'languages'     => array(),
				),
			);
		}

		$coverage = $this->gateway->coverage( $scope, $languages );
		$page     = $this->gateway->incomplete_originals( $scope, $languages, $pagination->per_page, $pagination->offset() );
		$ids      = array_map( static fn ( CoverageRow $row ): int => $row->original_id, $page->rows );
		$items    = $scope->is_terms() ? $this->woo->describe_terms( $ids ) : $this->woo->describe_posts( $ids );

		$objects = array();
		foreach ( $page->rows as $row ) {
			$item = $items[ $row->original_id ] ?? null;
			if ( $item instanceof CatalogItem ) {
				$objects[] = array(
					'id'                    => $item->id,
					'type'                  => $item->type,
					'title'                 => $item->title,
					'sku'                   => $item->sku,
					'missing_languages'     => $row->languages_in( TranslationState::Missing ),
					'unpublished_languages' => $row->languages_in( TranslationState::Unpublished ),
					'in_progress_languages' => $row->languages_in( TranslationState::InProgress ),
					'outdated_languages'    => $row->outdated,
					'edit_url'              => $item->edit_url,
				);
			}
		}

		return array(
			'objects'     => $objects,
			'total_items' => $page->total,
			'summary'     => array(
				'total_objects' => $coverage[0]->total(),
				'languages'     => array_map(
					static fn ( LanguageCoverage $language ): array => array(
						'language'     => $language->language,
						'translated'   => $language->translated,
						'unpublished'  => $language->unpublished,
						'in_progress'  => $language->in_progress,
						'missing'      => $language->missing,
						'outdated'     => $language->outdated,
						'coverage_pct' => $language->coverage_pct(),
					),
					$coverage
				),
			),
		);
	}
}
