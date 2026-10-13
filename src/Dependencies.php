<?php
/**
 * Runtime dependency rules.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities;

use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyProblem;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Environment\Environment;

/**
 * Decides whether abilities can be registered in the current environment.
 *
 * WooCommerce, WPML and the Abilities API are required. Without WCML the plugin still
 * registers the abilities that don't need it; the WCML-dependent ones report
 * `mlstore_dependency_missing` at execution time.
 */
final class Dependencies {

	/**
	 * First WooCommerce version with the canonical abilities conventions (see docs/api-notes.md §3).
	 */
	public const MIN_WOOCOMMERCE = '10.9.0';

	/**
	 * Creates the checker.
	 *
	 * @param Environment $environment Source of detected versions.
	 */
	public function __construct( private readonly Environment $environment ) {}

	/**
	 * Evaluates all dependencies.
	 */
	public function check(): DependencyStatus {
		$problems = array();

		if ( ! $this->environment->has_abilities_api() ) {
			$problems[] = new DependencyProblem( DependencyProblem::ABILITIES_API, true );
		}

		$woocommerce = $this->environment->woocommerce_version();
		if ( null === $woocommerce || version_compare( $woocommerce, self::MIN_WOOCOMMERCE, '<' ) ) {
			$problems[] = new DependencyProblem( DependencyProblem::WOOCOMMERCE, true, $woocommerce, self::MIN_WOOCOMMERCE );
		}

		if ( null === $this->environment->wpml_version() ) {
			$problems[] = new DependencyProblem( DependencyProblem::WPML, true );
		}

		if ( null === $this->environment->wcml_version() ) {
			$problems[] = new DependencyProblem( DependencyProblem::WCML, false );
		}

		return new DependencyStatus( $problems );
	}
}
