<?php
/**
 * Which product fields are kept in sync across translations.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Domain\Model;

/**
 * A difference in a synced field is a sync error; in any other field it is a (possibly intentional) deviation.
 */
final class SyncSettings {

	/**
	 * Creates the settings.
	 *
	 * @param list<ProductField> $synced_fields Fields the multilingual plugin keeps identical across translations.
	 */
	public function __construct( private readonly array $synced_fields ) {}

	/**
	 * Whether a field is synced.
	 *
	 * @param ProductField $field Field to check.
	 */
	public function is_synced( ProductField $field ): bool {
		return in_array( $field, $this->synced_fields, true );
	}

	/**
	 * All synced fields.
	 *
	 * @return list<ProductField>
	 */
	public function synced_fields(): array {
		return $this->synced_fields;
	}

	/**
	 * All fields that are not synced, in enum order.
	 *
	 * @return list<ProductField>
	 */
	public function unsynced_fields(): array {
		return array_values( array_filter( ProductField::cases(), fn ( ProductField $field ): bool => ! $this->is_synced( $field ) ) );
	}
}
