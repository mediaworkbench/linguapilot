<?php
/**
 * Static read-only check for CI: fails when plugin code calls a write API without a `verify-ignore` reason.
 *
 * Usage (from the plugin directory): php bin/check-readonly.php
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

use MediaWorkbench\LinguaPilot\Tests\Tooling\ReadOnlyScanner;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool, not WordPress output.

require dirname( __DIR__ ) . '/vendor/autoload.php';

$linguapilot_hits       = ReadOnlyScanner::scan_paths( array( 'linguapilot.php', 'uninstall.php', 'src' ), dirname( __DIR__ ) );
$linguapilot_violations = 0;

foreach ( $linguapilot_hits as $linguapilot_hit ) {
	$linguapilot_label = $linguapilot_hit['suppressed'] ? 'allowed  ' : 'VIOLATION';
	fwrite( STDOUT, sprintf( "%s %s:%d [%s] %s\n", $linguapilot_label, $linguapilot_hit['file'], $linguapilot_hit['line'], $linguapilot_hit['rule'], $linguapilot_hit['code'] ) );
	if ( ! $linguapilot_hit['suppressed'] ) {
		++$linguapilot_violations;
	}
}

if ( $linguapilot_violations > 0 ) {
	fwrite( STDERR, sprintf( "\n%d write(s) in read-only code. Remove them, or mark a legitimate one with\n// verify-ignore: readonly -- <reason>\n", $linguapilot_violations ) );
	exit( 1 );
}

fwrite( STDOUT, sprintf( "Read-only check passed (%d allowed, marked with verify-ignore).\n", count( $linguapilot_hits ) ) );
