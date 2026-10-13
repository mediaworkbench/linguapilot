<?php
/**
 * Tests that the fake gateway honours the gateway contract.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * Domain tests rely on the fake, so its contract behaviour is pinned down here.
 * The real gateway is checked against the same invariants in the store suite.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Fakes\FakeMultilingualGateway
 */
final class FakeMultilingualGatewayTest extends TestCase {

	public function test_translation_group_is_visible_from_every_member(): void {
		$gateway = ( new FakeMultilingualGateway() )->add_group(
			ElementType::product(),
			array(
				'de' => 10,
				'en' => 11,
			)
		);

		$this->assertSame(
			array(
				'de' => 10,
				'en' => 11,
			),
			$gateway->translations( 11, ElementType::product() )
		);
		$this->assertSame( 10, $gateway->original_id( 11, ElementType::product() ) );
		$this->assertSame( 'en', $gateway->element_language( 11, ElementType::product() ) );
	}

	public function test_original_can_be_in_a_non_default_language(): void {
		$gateway = ( new FakeMultilingualGateway() )->add_group(
			ElementType::product(),
			array(
				'de' => 10,
				'fr' => 12,
			),
			'fr'
		);

		$this->assertSame( 12, $gateway->original_id( 10, ElementType::product() ) );
		$this->assertSame( array( 12 ), $gateway->original_ids( ElementType::product(), 10, 0 )->ids );
	}

	public function test_unknown_elements_have_no_language_translations_or_original(): void {
		$gateway = new FakeMultilingualGateway();

		$this->assertNull( $gateway->element_language( 99, ElementType::product() ) );
		$this->assertSame( array(), $gateway->translations( 99, ElementType::product() ) );
		$this->assertNull( $gateway->original_id( 99, ElementType::product() ) );
	}

	public function test_originals_are_paginated_and_filtered_by_status(): void {
		$gateway = ( new FakeMultilingualGateway() )
			->add_group( ElementType::product(), array( 'de' => 30 ), null, array( 'de' => 'draft' ) )
			->add_group( ElementType::product(), array( 'de' => 10 ) )
			->add_group( ElementType::product(), array( 'de' => 20 ) )
			->add_group( ElementType::product(), array( 'de' => 40 ), null, array( 'de' => 'trash' ) );

		$all = $gateway->original_ids( ElementType::product(), 2, 0 );
		$this->assertSame( array( 10, 20 ), $all->ids );
		$this->assertSame( 3, $all->total );
		$this->assertSame( array( 30 ), $gateway->original_ids( ElementType::product(), 2, 2 )->ids );
		$this->assertSame( array( 10, 20 ), $gateway->original_ids( ElementType::product(), 10, 0, array( 'publish' ) )->ids );
	}

	public function test_manual_prices_are_read_from_the_original(): void {
		$gateway = ( new FakeMultilingualGateway() )
			->add_group(
				ElementType::product(),
				array(
					'de' => 10,
					'en' => 11,
				)
			)
			->set_manual_prices(
				10,
				array(
					'CHF' => '19.90',
					'USD' => null,
				)
			);

		$this->assertTrue( $gateway->has_manual_prices( 11 ) );
		$this->assertSame( '19.90', $gateway->manual_regular_price( 11, 'CHF' ) );
		$this->assertNull( $gateway->manual_regular_price( 11, 'USD' ) );
		$this->assertFalse( $gateway->has_manual_prices( 99 ) );
	}
}
