<?php
/**
 * Database error check for gateways.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Gateway;

use RuntimeException;

/**
 * `$wpdb` returns empty results on SQL errors, which an audit would report as confidently wrong
 * numbers. Gateways call assert_query_succeeded() after every direct query; AbstractAbility turns
 * the exception into `mlstore_internal_error` and logs the message only under WP_DEBUG.
 */
trait AssertsQueries {

	/**
	 * Fails loudly on a database error.
	 *
	 * @throws RuntimeException When the last query failed.
	 */
	private function assert_query_succeeded(): void {
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( 'Database query failed: ' . $this->wpdb->last_error );
		}
	}

	/**
	 * Comma-separated list of integer IDs for an SQL `IN ( ... )` clause.
	 *
	 * Interpolating is safe because every value is cast to int; it keeps the primary-key index
	 * usable, unlike FIND_IN_SET().
	 *
	 * @param list<int> $ids IDs.
	 */
	private static function id_list( array $ids ): string {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		return array() === $ids ? '0' : implode( ',', $ids );
	}
}
