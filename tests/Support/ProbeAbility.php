<?php
/**
 * Test-only ability exercising every AbstractAbility code path.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Support;

use MediaWorkbench\LinguaPilot\Abilities\AbilityError;
use MediaWorkbench\LinguaPilot\Abilities\AbstractAbility;
use MediaWorkbench\LinguaPilot\Support\OutputEnvelope;
use MediaWorkbench\LinguaPilot\Support\Pagination;
use RuntimeException;

/**
 * Lists the requested languages as items. `mode` switches to the error paths.
 */
final class ProbeAbility extends AbstractAbility {

	/**
	 * Whether the probe pretends to need WCML.
	 *
	 * @var bool
	 */
	public bool $needs_wcml = false;

	/**
	 * Last normalized input passed to run().
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $last_input = null;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'linguapilot/probe';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function label(): string {
		return 'Probe';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function description(): string {
		return 'Test-only ability.';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function input_properties(): array {
		return array_merge(
			array(
				'languages' => self::languages_input_property(),
				'mode'      => array(
					'type'    => 'string',
					'enum'    => array( 'ok', 'fail', 'crash' ),
					'default' => 'ok',
				),
			),
			Pagination::input_properties( 2 )
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function output_schema(): array {
		return OutputEnvelope::collection_schema(
			'languages',
			array(
				'type'                 => 'object',
				'properties'           => array( 'code' => array( 'type' => 'string' ) ),
				'required'             => array( 'code' ),
				'additionalProperties' => false,
			),
			$this->meta_schema()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function requires_wcml(): bool {
		return $this->needs_wcml;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws AbilityError     In `fail` mode.
	 * @throws RuntimeException In `crash` mode.
	 */
	protected function run( array $input ): array {
		$this->last_input = $input;

		if ( 'fail' === $input['mode'] ) {
			throw AbilityError::currency_disabled();
		}

		if ( 'crash' === $input['mode'] ) {
			throw new RuntimeException( 'secret internal detail' );
		}

		$languages  = $this->resolve_languages( $input['languages'] );
		$pagination = Pagination::from_input( $input, 2 );
		$items      = array_map( static fn ( string $code ): array => array( 'code' => $code ), $languages );

		return OutputEnvelope::collection(
			'languages',
			array_slice( $items, $pagination->offset(), $pagination->per_page ),
			$pagination,
			count( $items ),
			$this->meta()
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function result_count( array $output ): ?int {
		return is_array( $output['languages'] ?? null ) ? count( $output['languages'] ) : null;
	}
}
