<?php
/**
 * Static read-only check for CI: fails when plugin code calls a write API without a `verify-ignore` reason.
 *
 * Usage (from the plugin directory): php bin/check-readonly.php
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

use MediaWorkbench\MultilingualStoreAbilities\Tests\Tooling\ReadOnlyScanner;

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI tool, not WordPress output.

require dirname( __DIR__ ) . '/vendor/autoload.php';

$mlstore_hits       = ReadOnlyScanner::scan_paths( array( 'multilingual-store-abilities.php', 'uninstall.php', 'src' ), dirname( __DIR__ ) );
$mlstore_violations = 0;

foreach ( $mlstore_hits as $mlstore_hit ) {
	$mlstore_label = $mlstore_hit['suppressed'] ? 'allowed  ' : 'VIOLATION';
	fwrite( STDOUT, sprintf( "%s %s:%d [%s] %s\n", $mlstore_label, $mlstore_hit['file'], $mlstore_hit['line'], $mlstore_hit['rule'], $mlstore_hit['code'] ) );
	if ( ! $mlstore_hit['suppressed'] ) {
		++$mlstore_violations;
	}
}

if ( $mlstore_violations > 0 ) {
	fwrite( STDERR, sprintf( "\n%d write(s) in read-only code. Remove them, or mark a legitimate one with\n// verify-ignore: readonly -- <reason>\n", $mlstore_violations ) );
	exit( 1 );
}

fwrite( STDOUT, sprintf( "Read-only check passed (%d allowed, marked with verify-ignore).\n", count( $mlstore_hits ) ) );
