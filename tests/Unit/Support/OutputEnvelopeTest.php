<?php
/**
 * Tests for the output envelope.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Support;

use Brain\Monkey\Functions;
use MediaWorkbench\LinguaPilot\Support\OutputEnvelope;
use MediaWorkbench\LinguaPilot\Support\Pagination;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Lists follow WooCommerce's flat envelope; extras sit next to it.
 *
 * @covers \MediaWorkbench\LinguaPilot\Support\OutputEnvelope
 */
final class OutputEnvelopeTest extends TestCase {

	/**
	 * Stubs translation functions used in schema descriptions.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\stubTranslationFunctions();
	}

	public function test_collection_envelope_shape(): void {
		$pagination = Pagination::from_input(
			array(
				'page'     => 2,
				'per_page' => 2,
			)
		);
		$envelope   = OutputEnvelope::collection( 'products', array( 5 => array( 'id' => 5 ) ), $pagination, 3, array( 'm' => 1 ), array( 's' => 1 ) );

		$this->assertSame(
			array(
				'products'    => array( array( 'id' => 5 ) ),
				'page'        => 2,
				'per_page'    => 2,
				'total_pages' => 2,
				'total_items' => 3,
				'summary'     => array( 's' => 1 ),
				'meta'        => array( 'm' => 1 ),
			),
			$envelope
		);
	}

	public function test_collection_without_summary_has_no_summary_key(): void {
		$envelope = OutputEnvelope::collection( 'products', array(), Pagination::from_input( array() ), 0, array() );

		$this->assertArrayNotHasKey( 'summary', $envelope );
		$this->assertSame( 0, $envelope['total_pages'] );
	}

	public function test_object_envelope_adds_meta_last(): void {
		$this->assertSame(
			array(
				'a'    => 1,
				'meta' => array( 'm' => 1 ),
			),
			OutputEnvelope::object( array( 'a' => 1 ), array( 'm' => 1 ) )
		);
	}

	public function test_collection_schema_declares_every_envelope_key(): void {
		$schema = OutputEnvelope::collection_schema( 'products', array( 'type' => 'object' ), array( 'type' => 'object' ), array( 'type' => 'object' ) );

		$this->assertSame( 'object', $schema['type'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( array( 'products', 'page', 'per_page', 'total_pages', 'total_items', 'summary', 'meta' ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'products', 'page', 'per_page', 'total_pages', 'total_items', 'meta', 'summary' ), $schema['required'] );
		$this->assertSame( 'array', $schema['properties']['products']['type'] );
	}

	public function test_object_schema_merges_meta(): void {
		$schema = OutputEnvelope::object_schema( array( 'a' => array( 'type' => 'integer' ) ), array( 'type' => 'object' ) );

		$this->assertSame( array( 'a', 'meta' ), array_keys( $schema['properties'] ) );
		$this->assertSame( array( 'a', 'meta' ), $schema['required'] );
	}
}
