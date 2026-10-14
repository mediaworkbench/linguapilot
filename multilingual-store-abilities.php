<?php
/**
 * Plugin Name:       Multilingual Store Abilities for WooCommerce
 * Description:       Read-only abilities (WordPress Abilities API) that let AI agents audit translations, cross-language consistency and multi-currency prices in WooCommerce stores running WPML.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Media Workbench
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       multilingual-store-abilities
 * Domain Path:       /languages
 * WC requires at least: 10.9
 * WC tested up to:   11.2
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

MediaWorkbench\MultilingualStoreAbilities\Plugin::bootstrap( __FILE__ );
