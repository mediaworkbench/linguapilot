<?php
/**
 * A single unmet runtime dependency.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Environment;

/**
 * Describes one dependency that is missing or too old.
 */
final class DependencyProblem {

	public const ABILITIES_API = 'abilities-api';
	public const WOOCOMMERCE   = 'woocommerce';
	public const WPML          = 'wpml';
	public const WCML          = 'wcml';

	/**
	 * Creates a problem.
	 *
	 * @param string      $dependency        One of the class constants.
	 * @param bool        $blocking          True when no abilities can be registered because of it.
	 * @param string|null $installed_version Installed version, or null when the dependency is missing.
	 * @param string|null $required_version  Minimum required version, or null when any version is accepted.
	 */
	public function __construct(
		public readonly string $dependency,
		public readonly bool $blocking,
		public readonly ?string $installed_version = null,
		public readonly ?string $required_version = null,
	) {}

	/**
	 * Whether the dependency is absent (as opposed to present but outdated).
	 */
	public function is_missing(): bool {
		return null === $this->installed_version;
	}
}
