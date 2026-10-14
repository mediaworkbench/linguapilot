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
 *
 * ID lists in `IN ( … )` clauses use `implode( ',', wp_parse_id_list( $ids ) )`: core's integer sanitizer keeps the
 * primary-key index usable and is recognised as SQL-safe by Plugin Check.
 */
trait AssertsQueries {

	/**
	 * Fails loudly on a database error.
	 *
	 * @throws RuntimeException When the last query failed.
	 */
	private function assert_query_succeeded(): void {
		if ( '' !== $this->wpdb->last_error ) {
			throw new RuntimeException( esc_html( 'Database query failed: ' . $this->wpdb->last_error ) );
		}
	}
}
