<?php
/**
 * Display data of a catalog object.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * What an agent needs to name and locate a product or term.
 */
final class CatalogItem {

	/**
	 * Creates an item.
	 *
	 * @param int         $id       Object ID.
	 * @param string      $type     Post type or taxonomy slug.
	 * @param string      $title    Plain-text title or term name.
	 * @param string|null $sku      SKU (products only; null when none).
	 * @param string      $edit_url  Admin edit URL (for a variation: its parent product's).
	 * @param int|null    $parent_id Parent product ID (variations only).
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $title,
		public readonly ?string $sku,
		public readonly string $edit_url,
		public readonly ?int $parent_id = null,
	) {}
}
