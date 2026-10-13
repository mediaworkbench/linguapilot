<?php
/**
 * Environment detection contract.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Environment;

/**
 * Reports which runtime dependencies are present and in which version.
 *
 * Abstracted so the dependency rules can be unit-tested without WordPress.
 */
interface Environment {

	/**
	 * Whether the WordPress Abilities API is available.
	 */
	public function has_abilities_api(): bool;

	/**
	 * Active WooCommerce version, or null when WooCommerce is not active.
	 */
	public function woocommerce_version(): ?string;

	/**
	 * Active WPML Multilingual CMS version, or null when WPML is not active.
	 */
	public function wpml_version(): ?string;

	/**
	 * Active WooCommerce Multilingual version, or null when WCML is not active.
	 */
	public function wcml_version(): ?string;
}
