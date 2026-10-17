<?php
/**
 * Product fields compared across languages.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * The fields `linguapilot/cross-language-consistency` can compare. Values are the public input names.
 */
enum ProductField: string {
	case Sku               = 'sku';
	case StockStatus       = 'stock_status';
	case StockQuantity     = 'stock_quantity';
	case ManageStock       = 'manage_stock';
	case TaxClass          = 'tax_class';
	case CatalogVisibility = 'catalog_visibility';
	case PostStatus        = 'post_status';
	case RegularPrice      = 'regular_price';
	case SalePrice         = 'sale_price';
}
