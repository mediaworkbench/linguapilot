<?php
/**
 * Tests for parsing WCML multi-currency settings.
 *
 * @package MediaWorkbench\LinguaPilot
 */

declare( strict_types = 1 );

namespace MediaWorkbench\LinguaPilot\Tests\Unit\Gateway;

use MediaWorkbench\LinguaPilot\Domain\Model\Currency;
use MediaWorkbench\LinguaPilot\Gateway\Wpml\CurrencySettingsParser;
use MediaWorkbench\LinguaPilot\Tests\Unit\TestCase;

/**
 * Turns the raw `_wcml_settings` option into a CurrencySettings model.
 *
 * @covers \MediaWorkbench\LinguaPilot\Gateway\Wpml\CurrencySettingsParser
 * @covers \MediaWorkbench\LinguaPilot\Domain\Model\CurrencySettings
 * @covers \MediaWorkbench\LinguaPilot\Domain\Model\Currency
 */
final class CurrencySettingsParserTest extends TestCase {

	public function test_enabled_multi_currency_lists_currencies_with_their_languages(): void {
		$settings = CurrencySettingsParser::parse(
			array(
				'enable_multi_currency' => 2,
				'currency_options'      => array(
					'EUR' => array(
						'languages' => array(
							'de'    => 1,
							'en-us' => 1,
						),
					),
					'CHF' => array(
						'languages' => array(
							'de'    => 1,
							'en-us' => 0,
						),
					),
				),
			),
			'EUR'
		);

		$this->assertTrue( $settings->enabled );
		$this->assertSame( 'EUR', $settings->default_currency );
		$this->assertEquals(
			array( new Currency( 'EUR', array( 'de', 'en-us' ) ), new Currency( 'CHF', array( 'de' ) ) ),
			$settings->currencies
		);
		$this->assertSame( array( 'CHF' ), $settings->secondary_codes() );
	}

	public function test_default_currency_is_listed_even_when_missing_from_options(): void {
		$settings = CurrencySettingsParser::parse(
			array(
				'enable_multi_currency' => 2,
				'currency_options'      => array( 'USD' => array( 'languages' => array( 'en-us' => 1 ) ) ),
			),
			'EUR'
		);

		$this->assertSame( array( 'EUR', 'USD' ), $settings->codes() );
		$this->assertSame( array(), $settings->currencies[0]->languages );
	}

	public function test_disabled_multi_currency(): void {
		$settings = CurrencySettingsParser::parse( array( 'enable_multi_currency' => 0 ), 'EUR' );

		$this->assertFalse( $settings->enabled );
		$this->assertSame( array( 'EUR' ), $settings->codes() );
		$this->assertSame( array(), $settings->secondary_codes() );
	}

	/**
	 * Broken option values never throw.
	 *
	 * @dataProvider malformed_settings
	 *
	 * @param mixed $raw Raw option value.
	 */
	public function test_malformed_settings_degrade_to_disabled( $raw ): void {
		$settings = CurrencySettingsParser::parse( $raw, 'EUR' );

		$this->assertFalse( $settings->enabled );
		$this->assertSame( array( 'EUR' ), $settings->codes() );
	}

	/**
	 * Malformed `_wcml_settings` values.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function malformed_settings(): array {
		return array(
			'option missing'    => array( false ),
			'not an array'      => array( 'garbage' ),
			'mode as string'    => array( array( 'enable_multi_currency' => 'yes' ) ),
			'options not array' => array(
				array(
					'enable_multi_currency' => 1,
					'currency_options'      => 'x',
				),
			),
		);
	}

	public function test_without_wcml_multi_currency_is_reported_disabled(): void {
		$settings = CurrencySettingsParser::parse(
			array(
				'enable_multi_currency' => 2,
				'currency_options'      => array( 'CHF' => array( 'languages' => array( 'de' => 1 ) ) ),
			),
			'EUR',
			false
		);

		$this->assertFalse( $settings->enabled );
		$this->assertSame( array( 'EUR' ), $settings->codes() );
	}

	public function test_invalid_currency_codes_and_language_maps_are_skipped(): void {
		$settings = CurrencySettingsParser::parse(
			array(
				'enable_multi_currency' => 2,
				'currency_options'      => array(
					'EUR' => array( 'languages' => 'not-a-map' ),
					'chf' => array( 'languages' => array( 'de' => 1 ) ),
					123   => array( 'languages' => array( 'de' => 1 ) ),
				),
			),
			'EUR'
		);

		$this->assertSame( array( 'EUR' ), $settings->codes() );
		$this->assertSame( array(), $settings->currencies[0]->languages );
	}
}
