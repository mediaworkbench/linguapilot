<?php
/**
 * Static read-only check: finds write APIs in plugin source.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Tooling;

use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

/**
 * Scans PHP source for calls that write durable state (database, options, meta, object cache, files, HTTP,
 * scheduled jobs, actions whose listeners may write), following the write patterns of the `wp-abilities-verify`
 * skill (references/annotation-correctness.md).
 *
 * Comments are ignored; string literals are scanned, so raw SQL writes are found. A hit is allowed only when the
 * line itself or the comment directly above it carries `verify-ignore: readonly -- <reason>` (or `all`).
 * A static scan cannot see every write (e.g. listeners of filters we call); `tests/Store/ReadOnlyGuaranteeStoreTest`
 * covers the runtime side.
 */
final class ReadOnlyScanner {

	/**
	 * `$wpdb` methods that only read.
	 */
	private const WPDB_READ_METHODS = array( 'get_results', 'get_var', 'get_col', 'get_row', 'prepare', 'esc_like' );

	/**
	 * Rule name => regex matched against one line of code (comments removed).
	 * `(?<![\w$>:\\\\])` keeps function rules from matching methods (`->copy()`) or longer names.
	 */
	private const RULES = array(
		'sql-write'    => '/\b(INSERT\s+(IGNORE\s+)?INTO|UPDATE\s+\S+\s+SET|DELETE\s+FROM|REPLACE\s+INTO|ALTER\s+TABLE|CREATE\s+(TEMPORARY\s+)?TABLE|DROP\s+TABLE|TRUNCATE\s)/i',
		'option-meta'  => '/(?<![\w$>:\\\\])(add|update|delete)_(option|site_option|network_option|user_option|post_meta|user_meta|term_meta|comment_meta|metadata)\s*\(/',
		'transient'    => '/(?<![\w$>:\\\\])(set|delete)_(site_)?transient\s*\(/',
		'wp-crud'      => '/(?<![\w$>:\\\\])(wp_(insert|update|delete|trash|untrash)_(post|term|user|comment|attachment)|wp_set_(object|post)_terms|wp_create_(user|term)|wp_publish_post)\s*\(/',
		'object-cache' => '/(?<![\w$>:\\\\])wp_cache_(set|add|replace|delete|incr|decr|flush)\w*\s*\(/',
		'wc-crud'      => '/->\s*(save|delete|set_status|save_meta_data|add_meta_data|update_meta_data|delete_meta_data)\s*\(/',
		'http-write'   => '/(?<![\w$>:\\\\])wp_(safe_)?remote_(post|request)\s*\(/',
		'filesystem'   => '/(?<![\w$>:\\\\])(file_put_contents|fwrite|fputcsv|unlink|rename|copy|mkdir|rmdir|touch|wp_mkdir_p|wp_upload_bits|move_uploaded_file)\s*\(/',
		'scheduler'    => '/(?<![\w$>:\\\\])(wp_schedule_event|wp_schedule_single_event|wp_unschedule_event|wp_clear_scheduled_hook|as_schedule_\w+|as_enqueue_async_action|as_unschedule_\w+)\s*\(|->\s*schedule_\w+\s*\(/',
		'action'       => '/(?<![\w$>:\\\\])do_action(_ref_array)?\s*\(/',
		'logger'       => '/(?<![\w$>:\\\\])wc_get_logger\s*\(/',
	);

	/**
	 * Matches a valid suppression comment.
	 */
	private const SUPPRESSION = '/verify-ignore:\s*(readonly|all)\s*--\s*\S/';

	/**
	 * Scans files and directories (recursively, `*.php` only).
	 *
	 * @param list<string> $paths Files or directories, relative to `$base`.
	 * @param string       $base  Base directory; reported file names are relative to it.
	 * @return list<array{file: string, line: int, rule: string, code: string, suppressed: bool}>
	 * @throws RuntimeException When a path does not exist or a file cannot be read.
	 */
	public static function scan_paths( array $paths, string $base ): array {
		$hits = array();
		foreach ( self::php_files( $paths, $base ) as $file ) {
			$source = file_get_contents( $base . '/' . $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, CLI tool.
			if ( false === $source ) {
				throw new RuntimeException( 'Cannot read ' . $file ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI tool, not HTML.
			}
			foreach ( self::scan_source( $source ) as $hit ) {
				$hits[] = array( 'file' => $file ) + $hit;
			}
		}
		return $hits;
	}

	/**
	 * Scans one PHP source string.
	 *
	 * @param string $source PHP source.
	 * @return list<array{line: int, rule: string, code: string, suppressed: bool}>
	 */
	public static function scan_source( string $source ): array {
		$code_lines       = array();
		$suppressed_lines = array();
		$malformed_lines  = array();
		$pending          = false;

		foreach ( PhpToken::tokenize( $source ) as $token ) {
			$line  = $token->line;
			$parts = explode( "\n", $token->text );

			if ( $token->is( array( T_COMMENT, T_DOC_COMMENT ) ) ) {
				if ( str_contains( $token->text, 'verify-ignore' ) ) {
					if ( 1 === preg_match( self::SUPPRESSION, $token->text ) ) {
						$pending = true;
					} else {
						$malformed_lines[] = $line;
					}
				}
				continue;
			}

			foreach ( $parts as $offset => $part ) {
				$current = $line + $offset;
				if ( '' === trim( $part ) ) {
					// Keep a separator so `return unlink(` does not become `returnunlink(`.
					if ( isset( $code_lines[ $current ] ) ) {
						$code_lines[ $current ] .= ' ';
					}
					continue;
				}
				$code_lines[ $current ] = ( $code_lines[ $current ] ?? '' ) . $part;
				if ( $pending ) {
					$suppressed_lines[ $current ] = true;
					$pending                      = false;
				}
			}
		}

		$hits = array();
		foreach ( $malformed_lines as $line ) {
			$hits[] = array(
				'line'       => $line,
				'rule'       => 'malformed-suppression',
				'code'       => 'verify-ignore needs "readonly" or "all" and a reason: // verify-ignore: readonly -- <reason>',
				'suppressed' => false,
			);
		}
		ksort( $code_lines );
		foreach ( $code_lines as $line => $code ) {
			foreach ( self::rules_matching( $code ) as $rule ) {
				$hits[] = array(
					'line'       => $line,
					'rule'       => $rule,
					'code'       => trim( $code ),
					'suppressed' => isset( $suppressed_lines[ $line ] ),
				);
			}
		}
		return $hits;
	}

	/**
	 * Returns the names of all rules a line of code violates.
	 *
	 * @param string $code One line of code without comments.
	 * @return list<string>
	 */
	private static function rules_matching( string $code ): array {
		$rules = array();
		foreach ( self::RULES as $rule => $pattern ) {
			if ( 1 === preg_match( $pattern, $code ) ) {
				$rules[] = $rule;
			}
		}
		if ( preg_match_all( '/\$wpdb\s*->\s*(\w+)\s*\(/', $code, $matches ) > 0 ) {
			foreach ( $matches[1] as $method ) {
				if ( ! in_array( $method, self::WPDB_READ_METHODS, true ) ) {
					$rules[] = 'wpdb-write';
					break;
				}
			}
		}
		return $rules;
	}

	/**
	 * Expands paths to a sorted list of PHP files relative to `$base`.
	 *
	 * @param list<string> $paths Files or directories.
	 * @param string       $base  Base directory.
	 * @return list<string>
	 * @throws RuntimeException When a path does not exist.
	 */
	private static function php_files( array $paths, string $base ): array {
		$files = array();
		foreach ( $paths as $path ) {
			$full = $base . '/' . $path;
			if ( is_file( $full ) ) {
				$files[] = $path;
				continue;
			}
			if ( ! is_dir( $full ) ) {
				throw new RuntimeException( 'No such path: ' . $path ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI tool, not HTML.
			}
			$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $full, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $iterator as $file ) {
				if ( $file instanceof \SplFileInfo && 'php' === $file->getExtension() ) {
					$files[] = $path . substr( $file->getPathname(), strlen( $full ) );
				}
			}
		}
		sort( $files );
		return $files;
	}
}
