<?php
/**
 * Bootstrap for the read-only store suite: loads the real local site (with WPML), no test framework.
 *
 * Every v0.1 code path is read-only, so the suite runs against the local dev database (ideally
 * freshly seeded with tests/Fixtures/seed-demo.php). StoreTestCase checksums the content tables to prove nothing was written.
 *
 * Environment variables:
 * - LINGUAPILOT_WP_LOAD  Path to wp-load.php (default: the WordPress install this plugin lives in).
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

$linguapilot_wp_load = getenv( 'LINGUAPILOT_WP_LOAD' );
if ( false === $linguapilot_wp_load || '' === $linguapilot_wp_load ) {
	$linguapilot_wp_load = dirname( __DIR__, 5 ) . '/wp-load.php';
}

if ( ! is_readable( $linguapilot_wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at {$linguapilot_wp_load}. Set LINGUAPILOT_WP_LOAD.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Load WordPress like WP-CLI does: no theme output, no HTTP request.
define( 'WP_USE_THEMES', false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.

// Never spawn WP-Cron from the suite: cron jobs (e.g. WooCommerce scheduled sales) write to the
// guarded tables in a separate request and would make the read-only guard fail for the wrong reason.
define( 'DISABLE_WP_CRON', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant.
require_once $linguapilot_wp_load;

// Preflight: WooCommerce's daily scheduled-sales job (Action Scheduler) starts and ends sales by saving products.
// The generated catalog has thousands of sale date ranges, so whenever the job is due, any request to the site
// (browser tab, MCP client) runs it mid-suite and trips the read-only guard. Stop early instead, with the fix.
$linguapilot_due = $GLOBALS['wpdb']->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Read-only preflight on Action Scheduler's table.
	$GLOBALS['wpdb']->prepare(
		"SELECT hook, scheduled_date_gmt FROM %i
		WHERE status = 'pending' AND hook IN ( 'woocommerce_scheduled_sales', 'wc_product_start_scheduled_sale', 'wc_product_end_scheduled_sale' )
		AND scheduled_date_gmt <= %s ORDER BY scheduled_date_gmt LIMIT 5",
		$GLOBALS['wpdb']->prefix . 'actionscheduler_actions',
		gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS )
	),
	ARRAY_A
);
if ( ! empty( $linguapilot_due ) ) {
	$linguapilot_list = implode( ', ', array_map( static fn( array $row ): string => "{$row['hook']} ({$row['scheduled_date_gmt']} UTC)", $linguapilot_due ) );
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
	fwrite( STDERR, "Scheduled-sale actions are due or due within 5 minutes: {$linguapilot_list}.\nThey save products and would trip the read-only guard mid-run. Run them first (prefix with your container runner, e.g. `ddev`), then start the suite again:\n  wp action-scheduler run --hooks=woocommerce_scheduled_sales,wc_product_start_scheduled_sale,wc_product_end_scheduled_sale\n(If an action is not due yet, wait until it is and run the command.)\n" );
	exit( 1 );
}
