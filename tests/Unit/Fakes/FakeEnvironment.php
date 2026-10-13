<?php
/**
 * Configurable environment for unit tests.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes;

use MediaWorkbench\MultilingualStoreAbilities\Environment\Environment;

/**
 * Environment whose detected versions are set by the test.
 */
final class FakeEnvironment implements Environment {

	/**
	 * Creates a fake environment. Defaults describe a complete, supported stack.
	 *
	 * @param bool        $abilities_api        Whether the Abilities API is available.
	 * @param string|null $woocommerce_version  Detected WooCommerce version, null when inactive.
	 * @param string|null $wpml_version         Detected WPML version, null when inactive.
	 * @param string|null $wcml_version         Detected WCML version, null when inactive.
	 */
	public function __construct(
		private readonly bool $abilities_api = true,
		private readonly ?string $woocommerce_version = '11.2.1',
		private readonly ?string $wpml_version = '5.1.0',
		private readonly ?string $wcml_version = '5.6.3',
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function has_abilities_api(): bool {
		return $this->abilities_api;
	}

	/**
	 * {@inheritDoc}
	 */
	public function woocommerce_version(): ?string {
		return $this->woocommerce_version;
	}

	/**
	 * {@inheritDoc}
	 */
	public function wpml_version(): ?string {
		return $this->wpml_version;
	}

	/**
	 * {@inheritDoc}
	 */
	public function wcml_version(): ?string {
		return $this->wcml_version;
	}
}
