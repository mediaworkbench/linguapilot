<?php
/**
 * Tests for WPML element type naming.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\Domain;

use InvalidArgumentException;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Tests\Unit\TestCase;

/**
 * Element types follow WPML's `post_{type}` / `tax_{taxonomy}` naming.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType
 */
final class ElementTypeTest extends TestCase {

	public function test_named_types(): void {
		$this->assertSame( 'post_product', ElementType::product()->value );
		$this->assertSame( 'post_product_variation', ElementType::variation()->value );
		$this->assertSame( 'tax_product_cat', ElementType::product_category()->value );
		$this->assertSame( 'tax_product_tag', ElementType::product_tag()->value );
		$this->assertSame( 'tax_pa_color', ElementType::taxonomy( 'pa_color' )->value );
	}

	public function test_kind_and_name(): void {
		$this->assertFalse( ElementType::variation()->is_term() );
		$this->assertSame( 'product_variation', ElementType::variation()->name() );
		$this->assertTrue( ElementType::taxonomy( 'pa_size' )->is_term() );
		$this->assertSame( 'pa_size', ElementType::taxonomy( 'pa_size' )->name() );
	}

	public function test_equal_types_compare_equal(): void {
		$this->assertTrue( ElementType::product()->equals( ElementType::post_type( 'product' ) ) );
		$this->assertFalse( ElementType::product()->equals( ElementType::variation() ) );
	}

	public function test_rejects_invalid_names(): void {
		$this->expectException( InvalidArgumentException::class );
		ElementType::taxonomy( 'pa color; DROP' );
	}
}
