<?php
/**
 * Plugin Name:       LinguaPilot – Multilingual Store Abilities for WooCommerce
 * Description:       Read-only abilities (WordPress Abilities API) that let AI agents audit translations, cross-language consistency and multi-currency prices in WooCommerce stores running WPML.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            MEDIAWORKBENCH
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       linguapilot
 * Domain Path:       /languages
 * WC requires at least: 10.9
 * WC tested up to:   11.2
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

MediaWorkbench\LinguaPilot\Plugin::bootstrap( __FILE__ );
