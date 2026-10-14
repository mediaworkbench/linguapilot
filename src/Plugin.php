<?php
/**
 * Plugin composition root.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities;

use Automattic\WooCommerce\Utilities\FeaturesUtil;
use MediaWorkbench\MultilingualStoreAbilities\Abilities\AbstractAbility;
use MediaWorkbench\MultilingualStoreAbilities\Abilities\Registrar;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyProblem;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Environment\RuntimeEnvironment;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway;

/**
 * Wires hooks and holds the dependency status for the current request.
 */
final class Plugin {

	public const VERSION = '0.1.0';

	/**
	 * The instance booted by {@see self::bootstrap()}.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Dependency status, available once `plugins_loaded` has run.
	 *
	 * @var DependencyStatus|null
	 */
	private ?DependencyStatus $status = null;

	/**
	 * Ability registrar, created on boot when dependencies allow it.
	 *
	 * @var Registrar|null
	 */
	private ?Registrar $registrar = null;

	/**
	 * Creates the plugin.
	 *
	 * @param string       $plugin_file  Absolute path of the main plugin file.
	 * @param Dependencies $dependencies Dependency checker.
	 */
	public function __construct(
		private readonly string $plugin_file,
		private readonly Dependencies $dependencies,
	) {}

	/**
	 * Entry point called from the main plugin file.
	 *
	 * @param string $plugin_file Absolute path of the main plugin file.
	 */
	public static function bootstrap( string $plugin_file ): void {
		if ( null !== self::$instance ) {
			return;
		}

		global $wpdb;

		self::$instance = new self( $plugin_file, new Dependencies( new RuntimeEnvironment( new WpmlGateway( $wpdb ) ) ) );
		self::$instance->register_hooks();
	}

	/**
	 * The booted instance, or null before {@see self::bootstrap()} ran.
	 */
	public static function instance(): ?self {
		return self::$instance;
	}

	/**
	 * Registers the hooks the plugin needs before any dependency is known.
	 */
	public function register_hooks(): void {
		add_action( 'before_woocommerce_init', array( $this, 'declare_woocommerce_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'boot' ) );
	}

	/**
	 * Declares compatibility with WooCommerce's High-Performance Order Storage.
	 *
	 * The plugin never reads orders from the posts table, so HPOS is supported.
	 */
	public function declare_woocommerce_compatibility(): void {
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', $this->plugin_file, true );
		}
	}

	/**
	 * Checks dependencies once all plugins are loaded and reports problems to admins.
	 */
	public function boot(): void {
		$this->status = $this->dependencies->check();

		if ( array() !== $this->status->problems() ) {
			add_action( 'admin_notices', array( $this, 'render_dependency_notice' ) );
		}

		if ( $this->status->can_register_abilities() ) {
			$this->registrar = new Registrar( $this->create_abilities() );
			$this->registrar->register_hooks();
		}
	}

	/**
	 * Ability registrar, or null when dependencies block registration.
	 */
	public function registrar(): ?Registrar {
		return $this->registrar;
	}

	/**
	 * Creates every ability the plugin provides.
	 *
	 * @return list<AbstractAbility>
	 */
	private function create_abilities(): array {
		return array();
	}


	/**
	 * Dependency status, or null before `plugins_loaded`.
	 */
	public function status(): ?DependencyStatus {
		return $this->status;
	}

	/**
	 * Prints one admin notice listing every unmet dependency.
	 */
	public function render_dependency_notice(): void {
		if ( null === $this->status || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$blocking = ! $this->status->can_register_abilities();
		$summary  = $blocking
			? __( 'Multilingual Store Abilities is inactive: no abilities are registered until the following requirements are met.', 'multilingual-store-abilities' )
			: __( 'Multilingual Store Abilities runs with reduced functionality: abilities that need WooCommerce Multilingual are unavailable.', 'multilingual-store-abilities' );

		printf(
			'<div class="notice %1$s"><p>%2$s</p><ul>',
			esc_attr( $blocking ? 'notice-error' : 'notice-warning' ),
			esc_html( $summary )
		);

		foreach ( $this->status->problems() as $problem ) {
			printf( '<li>%s</li>', esc_html( self::describe( $problem ) ) );
		}

		echo '</ul></div>';
	}

	/**
	 * Human-readable description of a dependency problem.
	 *
	 * @param DependencyProblem $problem Problem to describe.
	 */
	private static function describe( DependencyProblem $problem ): string {
		$names = array(
			DependencyProblem::ABILITIES_API => __( 'WordPress Abilities API (WordPress 6.9 or newer)', 'multilingual-store-abilities' ),
			DependencyProblem::WOOCOMMERCE   => 'WooCommerce',
			DependencyProblem::WPML          => 'WPML Multilingual CMS',
			DependencyProblem::WPML_SETUP    => 'WPML Multilingual CMS',
			DependencyProblem::WCML          => 'WooCommerce Multilingual & Multicurrency',
		);
		$name  = $names[ $problem->dependency ] ?? $problem->dependency;

		if ( DependencyProblem::WPML_SETUP === $problem->dependency ) {
			return __( 'The WPML setup wizard has not been completed (WPML → Setup).', 'multilingual-store-abilities' );
		}

		if ( $problem->is_missing() ) {
			/* translators: %s: Name of the required plugin or API. */
			return sprintf( __( '%s is not active.', 'multilingual-store-abilities' ), $name );
		}

		return sprintf(
			/* translators: 1: Plugin name, 2: Installed version, 3: Minimum required version. */
			__( '%1$s %2$s is active, but version %3$s or newer is required.', 'multilingual-store-abilities' ),
			$name,
			(string) $problem->installed_version,
			(string) $problem->required_version
		);
	}
}
