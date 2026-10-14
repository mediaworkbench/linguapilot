<?php
/**
 * Shared behaviour of all abilities.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Abilities;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Environment\DependencyStatus;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\MultilingualGateway;
use MediaWorkbench\MultilingualStoreAbilities\Plugin;
use MediaWorkbench\MultilingualStoreAbilities\Support\AuditLogger;
use Throwable;
use WP_Error;

/**
 * Base class for every `mlstore/*` ability.
 *
 * Subclasses only declare their schema and map input to a domain service (`run()`); this class
 * owns the registration args, permission check, input normalization, error mapping and auditing.
 *
 * Core applies only the schema's top-level default, so property defaults and integer bounds
 * are re-applied here (docs/api-notes.md §1). No Throwable ever reaches core, which would
 * echo its message to the caller.
 */
abstract class AbstractAbility {

	public const NAMESPACE          = 'mlstore';
	public const CATEGORY           = 'woocommerce';
	public const DEFAULT_CAPABILITY = 'manage_woocommerce';

	/**
	 * Creates the ability.
	 *
	 * @param MultilingualGateway                 $gateway Multilingual plugin access.
	 * @param AuditLogger                         $audit   Audit log.
	 * @param DependencyStatus                    $status  Detected dependencies.
	 * @param (Closure(): DateTimeImmutable)|null $clock  Current time; injectable for tests.
	 */
	public function __construct(
		protected readonly MultilingualGateway $gateway,
		private readonly AuditLogger $audit,
		private readonly DependencyStatus $status,
		private readonly ?Closure $clock = null,
	) {}

	/**
	 * Full ability name, e.g. `mlstore/languages-overview`.
	 *
	 * @return lowercase-string&non-falsy-string
	 */
	abstract public function name(): string;

	/**
	 * Human-readable label.
	 */
	abstract protected function label(): string;

	/**
	 * What the ability does and when an agent should use it.
	 */
	abstract protected function description(): string;

	/**
	 * Input schema properties.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	abstract protected function input_properties(): array;

	/**
	 * Output schema; must describe exactly what run() returns (core validates it on every call).
	 *
	 * @return array<string, mixed>
	 */
	abstract protected function output_schema(): array;

	/**
	 * Does the work.
	 *
	 * @param array<string, mixed> $input Normalized input: defaults applied, integers clamped, unknown keys dropped.
	 * @return array<string, mixed>
	 * @throws AbilityError On expected failures.
	 */
	abstract protected function run( array $input ): array;

	/**
	 * Detected dependencies.
	 */
	protected function status(): DependencyStatus {
		return $this->status;
	}

	/**
	 * Whether the ability needs WooCommerce Multilingual.
	 */
	protected function requires_wcml(): bool {
		return false;
	}

	/**
	 * Number of returned items for the audit log; null for non-list abilities.
	 *
	 * @param array<string, mixed> $output Output of run().
	 */
	protected function result_count( array $output ): ?int { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Overridden by list abilities.
		return null;
	}

	/**
	 * Arguments for `wp_register_ability()`.
	 *
	 * @return array<string, mixed>
	 */
	public function registration_args(): array {
		return array(
			'label'               => $this->label(),
			'description'         => $this->description(),
			'category'            => self::CATEGORY,
			'input_schema'        => $this->input_schema(),
			'output_schema'       => $this->output_schema(),
			'execute_callback'    => array( $this, 'execute' ),
			'permission_callback' => array( $this, 'check_permission' ),
			'meta'                => array(
				'show_in_rest' => true,
				'public'       => true,
				'mcp'          => array(
					'public' => true,
					'type'   => 'tool',
				),
				'annotations'  => array(
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				),
			),
		);
	}

	/**
	 * Full input schema. The top-level default lets agents call the ability without arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		$schema     = array( 'type' => 'object' );
		$properties = $this->input_properties();

		// An empty PHP array would be JSON-encoded as `[]`, which is not a valid `properties` value.
		if ( array() !== $properties ) {
			$schema['properties'] = $properties;
		}

		// `(object) array()` encodes as `{}`: a valid default for an object schema. Core then passes an empty
		// stdClass, which execute() treats as "no input".
		return $schema + array(
			'additionalProperties' => false,
			'default'              => (object) array(),
		);
	}

	/**
	 * Permission callback.
	 *
	 * @param mixed $input Ability input (unused; access does not depend on it).
	 */
	public function check_permission( mixed $input = null ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Core passes the input.
		/**
		 * Filters the capability required to run an ability.
		 *
		 * @since 0.1.0
		 *
		 * @param string $capability Capability. Default `manage_woocommerce`.
		 * @param string $ability    Ability name.
		 */
		$capability = apply_filters( 'mlstore_ability_capability', self::DEFAULT_CAPABILITY, $this->name() );
		/**
		 * A filter may return anything, whatever the default's type.
		 *
		 * @var mixed $capability
		 */

		return is_string( $capability ) && '' !== $capability && current_user_can( $capability );
	}

	/**
	 * Execute callback.
	 *
	 * @param mixed $input Ability input, already validated against the input schema by core.
	 * @return array<string, mixed>|WP_Error
	 */
	public function execute( mixed $input = null ): array|WP_Error {
		$started    = hrtime( true );
		$normalized = $this->normalize_input( is_array( $input ) ? $input : array() );
		$count      = null;
		$error_code = null;

		try {
			$this->assert_dependencies();

			$output = $this->run( $normalized );
			$count  = $this->result_count( $output );

			return $output;
		} catch ( AbilityError $error ) {
			$error_code = $error->error_code;

			return new WP_Error( $error->error_code, $error->getMessage(), array( 'status' => $error->http_status ) );
		} catch ( Throwable $exception ) {
			$error_code = AbilityError::INTERNAL;
			$this->audit->record_exception( $this->name(), $exception );

			return new WP_Error(
				AbilityError::INTERNAL,
				__( 'The ability failed unexpectedly. Details were written to the WooCommerce log (source: mlstore-abilities).', 'multilingual-store-abilities-for-woocommerce' ),
				array( 'status' => 500 )
			);
		} finally {
			$this->audit->record( $this->name(), get_current_user_id(), $normalized, $count, ( hrtime( true ) - $started ) / 1e6, $error_code );
		}
	}

	/**
	 * Fails when a dependency this ability needs is missing.
	 *
	 * @throws AbilityError When WCML is required but not active.
	 */
	private function assert_dependencies(): void {
		if ( $this->requires_wcml() && ! $this->status->has_wcml() ) {
			throw AbilityError::dependency_missing( 'WooCommerce Multilingual & Multicurrency' );
		}
	}

	/**
	 * Applies property defaults, clamps integers to their schema bounds and drops unknown keys.
	 *
	 * @param array<mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public function normalize_input( array $input ): array {
		$normalized = array();

		foreach ( $this->input_properties() as $name => $schema ) {
			if ( ! array_key_exists( $name, $input ) ) {
				if ( array_key_exists( 'default', $schema ) ) {
					$normalized[ $name ] = $schema['default'];
				}
				continue;
			}

			$value = $input[ $name ];
			if ( 'integer' === ( $schema['type'] ?? null ) && is_numeric( $value ) ) {
				$value = (int) $value;
				if ( isset( $schema['minimum'] ) && is_int( $schema['minimum'] ) ) {
					$value = max( $schema['minimum'], $value );
				}
				if ( isset( $schema['maximum'] ) && is_int( $schema['maximum'] ) ) {
					$value = min( $schema['maximum'], $value );
				}
			}

			$normalized[ $name ] = $value;
		}

		return $normalized;
	}

	/**
	 * Input property for a list of target (non-default) languages.
	 *
	 * @return array<string, mixed>
	 */
	protected static function languages_input_property(): array {
		return array(
			'type'        => 'array',
			'items'       => array( 'type' => 'string' ),
			'uniqueItems' => true,
			'default'     => array(),
			'description' => __( 'Translation languages to check, as language codes from mlstore/languages-overview. Default: all active languages except the default language.', 'multilingual-store-abilities-for-woocommerce' ),
		);
	}

	/**
	 * Validates requested target languages. Empty = every active non-default language.
	 *
	 * @param mixed $requested Value of the `languages` input.
	 * @return list<string> Valid codes, deduplicated, in the site's language order.
	 * @throws AbilityError When a code is not an active non-default language.
	 */
	protected function resolve_languages( mixed $requested ): array {
		$targets = array_values(
			array_map(
				static fn ( Language $language ): string => $language->code,
				array_filter( $this->gateway->active_languages(), static fn ( Language $language ): bool => ! $language->is_default )
			)
		);

		$requested = is_array( $requested ) ? array_values( array_unique( array_filter( $requested, 'is_string' ) ) ) : array();
		if ( array() === $requested ) {
			return $targets;
		}

		$invalid = array_values( array_diff( $requested, $targets ) );
		if ( array() !== $invalid ) {
			throw AbilityError::invalid_language( array_map( 'esc_html', $invalid ) );
		}

		return array_values( array_intersect( $targets, $requested ) );
	}

	/**
	 * Response metadata.
	 *
	 * @param array<string, mixed> $extra Ability-specific fields (declare them in meta_schema()).
	 * @return array<string, mixed>
	 */
	protected function meta( array $extra = array() ): array {
		$now = $this->now();

		return array(
			'generated_at'     => $now->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' ),
			'default_language' => $this->gateway->default_language(),
			'languages'        => array_map( static fn ( Language $language ): string => $language->code, $this->gateway->active_languages() ),
			'plugin_version'   => Plugin::VERSION,
		) + $extra;
	}

	/**
	 * Current time (injectable clock for tests).
	 */
	protected function now(): DateTimeImmutable {
		return null !== $this->clock ? ( $this->clock )() : new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Schema of {@see self::meta()}.
	 *
	 * @param array<string, array<string, mixed>> $extra Schemas of ability-specific fields.
	 * @return array<string, mixed>
	 */
	protected function meta_schema( array $extra = array() ): array {
		$properties = array(
			'generated_at'     => array(
				'type'        => 'string',
				'format'      => 'date-time',
				'description' => __( 'When the response was generated (UTC).', 'multilingual-store-abilities-for-woocommerce' ),
			),
			'default_language' => array( 'type' => 'string' ),
			'languages'        => array(
				'type'  => 'array',
				'items' => array( 'type' => 'string' ),
			),
			'plugin_version'   => array( 'type' => 'string' ),
		) + $extra;

		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}
}
