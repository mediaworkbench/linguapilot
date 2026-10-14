<?php
/**
 * Equality rules for product fields across languages.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Domain;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;

/**
 * Decides whether two raw field values mean the same thing.
 */
final class FieldComparator {

	private const NUMERIC_FIELDS = array( ProductField::RegularPrice, ProductField::SalePrice, ProductField::StockQuantity );

	/**
	 * Whether a field is worth comparing for this pair at all.
	 *
	 * Stock quantity is meaningless unless at least one side manages stock.
	 *
	 * @param ProductField               $field       Field.
	 * @param array<string, string|null> $original    Original's field values.
	 * @param array<string, string|null> $translation Translation's field values.
	 */
	public static function applies( ProductField $field, array $original, array $translation ): bool {
		if ( ProductField::StockQuantity !== $field ) {
			return true;
		}

		return 'yes' === ( $original['manage_stock'] ?? null ) || 'yes' === ( $translation['manage_stock'] ?? null );
	}

	/**
	 * Whether two raw values are equal for a field.
	 *
	 * Empty and missing are the same; numbers compare by value ("10" = "10.00"); WooCommerce
	 * stores the standard tax class as an empty string, so "standard" equals "".
	 *
	 * @param ProductField $field       Field.
	 * @param string|null  $original    Original value.
	 * @param string|null  $translation Translation value.
	 */
	public static function equal( ProductField $field, ?string $original, ?string $translation ): bool {
		$a = self::normalize( $field, $original );
		$b = self::normalize( $field, $translation );

		if ( in_array( $field, self::NUMERIC_FIELDS, true ) && is_numeric( $a ) && is_numeric( $b ) ) {
			return abs( (float) $a - (float) $b ) < 0.000001;
		}

		return $a === $b;
	}

	/**
	 * Display form of a raw value: null when empty.
	 *
	 * @param string|null $value Raw value.
	 */
	public static function display( ?string $value ): ?string {
		return null === $value || '' === $value ? null : $value;
	}

	/**
	 * Normalizes a raw value for comparison.
	 *
	 * @param ProductField $field Field.
	 * @param string|null  $value Raw value.
	 */
	private static function normalize( ProductField $field, ?string $value ): string {
		$value = null === $value ? '' : trim( $value );

		return ProductField::TaxClass === $field && 'standard' === $value ? '' : $value;
	}
}
