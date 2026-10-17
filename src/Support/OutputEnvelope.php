<?php
/**
 * Output envelope and matching JSON schemas.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Support;

/**
 * Lists use WooCommerce's flat collection shape (`{ <key>: [...], page, per_page, total_pages }`) plus
 * `total_items`, an optional `summary` and `meta`. Non-list abilities return their fields plus `meta`.
 */
final class OutputEnvelope {

	/**
	 * Builds a list response.
	 *
	 * @param string                    $key         Collection key, e.g. `products`.
	 * @param array<int|string, mixed>  $items       Items on this page.
	 * @param Pagination                $pagination  Pagination used to fetch the items.
	 * @param int                       $total_items Total matching items across all pages.
	 * @param array<string, mixed>      $meta        Response metadata.
	 * @param array<string, mixed>|null $summary     Optional summary across all pages.
	 * @return array<string, mixed>
	 */
	public static function collection( string $key, array $items, Pagination $pagination, int $total_items, array $meta, ?array $summary = null ): array {
		$envelope = array(
			$key          => array_values( $items ),
			'page'        => $pagination->page,
			'per_page'    => $pagination->per_page,
			'total_pages' => $pagination->total_pages( $total_items ),
			'total_items' => $total_items,
		);

		if ( null !== $summary ) {
			$envelope['summary'] = $summary;
		}

		$envelope['meta'] = $meta;

		return $envelope;
	}

	/**
	 * Builds a non-list response.
	 *
	 * @param array<string, mixed> $fields Response fields.
	 * @param array<string, mixed> $meta   Response metadata.
	 * @return array<string, mixed>
	 */
	public static function object( array $fields, array $meta ): array {
		return $fields + array( 'meta' => $meta );
	}

	/**
	 * Output schema for {@see self::collection()}.
	 *
	 * @param string                    $key            Collection key.
	 * @param array<string, mixed>      $item_schema    Schema of one item.
	 * @param array<string, mixed>      $meta_schema    Schema of `meta`.
	 * @param array<string, mixed>|null $summary_schema Schema of `summary`, when the ability returns one.
	 * @return array<string, mixed>
	 */
	public static function collection_schema( string $key, array $item_schema, array $meta_schema, ?array $summary_schema = null ): array {
		$properties = array(
			$key          => array(
				'type'  => 'array',
				'items' => $item_schema,
			),
			'page'        => array(
				'type'        => 'integer',
				'description' => __( 'Current page.', 'linguapilot' ),
			),
			'per_page'    => array(
				'type'        => 'integer',
				'description' => __( 'Maximum number of items per page.', 'linguapilot' ),
			),
			'total_pages' => array(
				'type'        => 'integer',
				'description' => __( 'Total number of pages.', 'linguapilot' ),
			),
			'total_items' => array(
				'type'        => 'integer',
				'description' => __( 'Total number of matching items across all pages.', 'linguapilot' ),
			),
		);
		$required   = array( $key, 'page', 'per_page', 'total_pages', 'total_items', 'meta' );

		if ( null !== $summary_schema ) {
			$properties['summary'] = $summary_schema;
			$required[]            = 'summary';
		}

		$properties['meta'] = $meta_schema;

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema for {@see self::object()}.
	 *
	 * @param array<string, array<string, mixed>> $properties  Schemas of the response fields (all required).
	 * @param array<string, mixed>                $meta_schema Schema of `meta`.
	 * @return array<string, mixed>
	 */
	public static function object_schema( array $properties, array $meta_schema ): array {
		$properties['meta'] = $meta_schema;

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}
}
