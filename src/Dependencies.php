<?php
/**
 * Runtime dependency rules.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot;

use MediaWorkbench\LinguaPilot\Environment\DependencyProblem;
use MediaWorkbench\LinguaPilot\Environment\DependencyStatus;
use MediaWorkbench\LinguaPilot\Environment\Environment;

/**
 * Decides whether abilities can be registered in the current environment.
 *
 * WooCommerce, WPML (with its setup finished) and the Abilities API are required. Without WCML the plugin still
 * registers the abilities that don't need it; the WCML-dependent ones report
 * `linguapilot_dependency_missing` at execution time.
 */
final class Dependencies {

	/**
	 * First WooCommerce version with the canonical abilities conventions.
	 */
	public const MIN_WOOCOMMERCE = '10.9.0';

	/**
	 * WCML 5.6's own minimum WPML version.
	 */
	public const MIN_WPML = '5.0.0';

	/**
	 * The WCML version line the plugin is developed and tested against.
	 */
	public const MIN_WCML = '5.6.0';

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

		$wpml = $this->environment->wpml_version();
		if ( null === $wpml || version_compare( $wpml, self::MIN_WPML, '<' ) ) {
			$problems[] = new DependencyProblem( DependencyProblem::WPML, true, $wpml, self::MIN_WPML );
		} elseif ( ! $this->environment->is_wpml_setup_complete() ) {
			$problems[] = new DependencyProblem( DependencyProblem::WPML_SETUP, true, $wpml );
		}

		$wcml = $this->environment->wcml_version();
		if ( null === $wcml || version_compare( $wcml, self::MIN_WCML, '<' ) ) {
			$problems[] = new DependencyProblem( DependencyProblem::WCML, false, $wcml, self::MIN_WCML );
		}

		return new DependencyStatus( $problems );
	}
}
