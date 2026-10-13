<?php
/**
 * Contract tests for WpmlGateway against a real WPML/WCML store.
 *
 * @package MediaWorkbench\MultilingualStoreAbilities
 */

declare( strict_types = 1 );

namespace MediaWorkbench\MultilingualStoreAbilities\Tests\Store;

use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ElementType;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\Language;
use MediaWorkbench\MultilingualStoreAbilities\Domain\Model\ProductField;
use MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway;

/**
 * Each test checks a gateway invariant and cross-checks it against WPML's tables, so the
 * suite does not depend on particular seed data.
 *
 * @covers \MediaWorkbench\MultilingualStoreAbilities\Gateway\WpmlGateway
 */
final class WpmlGatewayTest extends StoreTestCase {

	/**
	 * Gateway under test.
	 *
	 * @var WpmlGateway
	 */
	private WpmlGateway $gateway;

	/**
	 * Creates a fresh gateway (no memoized state between tests).
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->gateway = new WpmlGateway( self::wpdb() );
	}

	public function test_setup_is_complete(): void {
		$this->assertTrue( $this->gateway->is_setup_complete() );
	}

	public function test_active_languages_match_wpml_tables_with_default_first(): void {
		$languages = $this->gateway->active_languages();
		$codes     = array_map( static fn ( Language $language ): string => $language->code, $languages );

		$expected = self::wpdb()->get_col( 'SELECT code FROM ' . self::wpdb()->prefix . 'icl_languages WHERE active = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		sort( $expected );
		$sorted = $codes;
		sort( $sorted );

		$this->assertSame( $expected, $sorted );
		$this->assertSame( $this->gateway->default_language(), $codes[0] );
		$this->assertTrue( $languages[0]->is_default );
		$this->assertCount( 1, array_filter( $languages, static fn ( Language $language ): bool => $language->is_default ) );

		foreach ( $languages as $language ) {
			$this->assertNotSame( '', $language->english_name, $language->code );
			$this->assertNotSame( '', $language->native_name, $language->code );
		}
	}

	/**
	 * Listing originals page by page yields exactly WPML's originals, in order.
	 *
	 * @dataProvider element_types
	 *
	 * @param ElementType $type Type to check.
	 */
	public function test_original_ids_match_icl_translations_and_paginate_without_gaps( ElementType $type ): void {
		$expected = $this->originals_from_sql( $type );

		$collected = array();
		$offset    = 0;
		do {
			$page      = $this->gateway->original_ids( $type, 7, $offset );
			$collected = array_merge( $collected, $page->ids );
			$offset   += 7;
		} while ( array() !== $page->ids );

		$this->assertSame( count( $expected ), $page->total );
		$this->assertSame( $expected, $collected );
	}

	/**
	 * Every member of a group sees the same group, the same original and its own language.
	 *
	 * @dataProvider element_types
	 *
	 * @param ElementType $type Type to check.
	 */
	public function test_translation_groups_are_consistent_from_every_member( ElementType $type ): void {
		$originals = $this->gateway->original_ids( $type, 100, 0 )->ids;
		if ( array() === $originals ) {
			$this->markTestSkipped( "No {$type->value} originals in this store." );
		}

		foreach ( $originals as $original ) {
			$group    = $this->gateway->translations( $original, $type );
			$language = $this->gateway->element_language( $original, $type );

			$this->assertNotNull( $language );
			$this->assertSame( $original, $group[ $language ] ?? null, "{$type->value} {$original} missing from its own group" );
			$this->assertSame( $original, $this->gateway->original_id( $original, $type ) );

			foreach ( $group as $member_language => $member ) {
				$this->assertSame( $member_language, $this->gateway->element_language( $member, $type ) );
				$this->assertSame( $original, $this->gateway->original_id( $member, $type ), "original of {$type->value} {$member}" );
				$this->assertSame( $group, $this->gateway->translations( $member, $type ) );
				$this->assertObjectExists( $member, $type );
			}
		}
	}

	public function test_translations_of_equals_single_lookups(): void {
		$ids = $this->gateway->original_ids( ElementType::product(), 20, 0 )->ids;

		$expected = array();
		foreach ( $ids as $id ) {
			$expected[ $id ] = $this->gateway->translations( $id, ElementType::product() );
		}

		$this->assertSame( $expected, $this->gateway->translations_of( $ids, ElementType::product() ) );
	}

	public function test_unknown_elements_have_no_language_group_or_original(): void {
		$missing = (int) self::wpdb()->get_var( 'SELECT MAX(ID) + 1000 FROM ' . self::wpdb()->posts ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$this->assertNull( $this->gateway->element_language( $missing, ElementType::product() ) );
		$this->assertSame( array(), $this->gateway->translations( $missing, ElementType::product() ) );
		$this->assertNull( $this->gateway->original_id( $missing, ElementType::product() ) );
		$this->assertSame( array(), $this->gateway->translations( $missing, ElementType::product_category() ) );
	}

	public function test_sync_settings_follow_stored_wpml_preferences(): void {
		$settings    = get_option( 'icl_sitepress_settings' );
		$preferences = is_array( $settings ) ? ( $settings['translation-management']['custom_fields_translation'] ?? array() ) : array();
		$sync        = $this->gateway->sync_settings();

		$this->assertSame( 1 === (int) ( $preferences['_sku'] ?? 0 ), $sync->is_synced( ProductField::Sku ) );
		$this->assertSame( 1 === (int) ( $preferences['_tax_class'] ?? 0 ), $sync->is_synced( ProductField::TaxClass ) );
		$this->assertTrue( $sync->is_synced( ProductField::StockQuantity ) );
		$this->assertFalse( $sync->is_synced( ProductField::PostStatus ) );
	}

	public function test_currencies_follow_wcml_settings(): void {
		$wcml       = get_option( '_wcml_settings' );
		$currencies = $this->gateway->currencies();

		$this->assertSame( get_option( 'woocommerce_currency' ), $currencies->default_currency );
		$this->assertSame( $currencies->default_currency, $currencies->codes()[0] );
		$this->assertSame( 2 === (int) ( is_array( $wcml ) ? ( $wcml['enable_multi_currency'] ?? 0 ) : 0 ), $currencies->enabled );
	}

	public function test_manual_prices_are_read_from_the_original_for_every_translation(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$originals = self::wpdb()->get_col(
			'SELECT post_id FROM ' . self::wpdb()->postmeta . " WHERE meta_key = '_wcml_custom_prices_status' AND meta_value = '1' LIMIT 20"
		);
		if ( array() === $originals ) {
			$this->markTestSkipped( 'No products with manual prices in this store.' );
		}

		$secondary = $this->gateway->currencies()->secondary_codes();
		foreach ( array_map( 'intval', $originals ) as $original ) {
			$type = ElementType::post_type( (string) get_post_type( $original ) );
			foreach ( $this->gateway->translations( $original, $type ) as $member ) {
				$this->assertTrue( $this->gateway->has_manual_prices( $member ) );
				foreach ( $secondary as $currency ) {
					$raw      = get_post_meta( $original, '_regular_price_' . $currency, true );
					$expected = is_string( $raw ) && '' !== $raw ? $raw : null;
					$this->assertSame( $expected, $this->gateway->manual_regular_price( $member, $currency ) );
				}
			}
		}
	}

	/**
	 * Element types covered by the contract tests.
	 *
	 * @return array<string, array{ElementType}>
	 */
	public static function element_types(): array {
		return array(
			'products'           => array( ElementType::product() ),
			'variations'         => array( ElementType::variation() ),
			'product categories' => array( ElementType::product_category() ),
			'product tags'       => array( ElementType::product_tag() ),
		);
	}

	/**
	 * Originals of a type straight from WPML's table, as WordPress object IDs.
	 *
	 * @param ElementType $type Element type.
	 * @return list<int>
	 */
	private function originals_from_sql( ElementType $type ): array {
		$wpdb  = self::wpdb();
		$table = $wpdb->prefix . 'icl_translations';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ids = $type->is_term()
			? $wpdb->get_col(
				$wpdb->prepare(
					'SELECT tt.term_id FROM %i t JOIN %i tt ON tt.term_taxonomy_id = t.element_id
					WHERE t.element_type = %s AND t.source_language_code IS NULL AND tt.taxonomy = %s ORDER BY tt.term_id',
					$table,
					$wpdb->term_taxonomy,
					$type->value,
					$type->name()
				)
			)
			: $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM %i t JOIN %i p ON p.ID = t.element_id
					WHERE t.element_type = %s AND t.source_language_code IS NULL AND p.post_type = %s
					AND p.post_status NOT IN ('trash', 'auto-draft') ORDER BY p.ID",
					$table,
					$wpdb->posts,
					$type->value,
					$type->name()
				)
			);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Asserts that an object ID refers to an existing post or term of the right kind.
	 *
	 * @param int         $id   Object ID.
	 * @param ElementType $type Element type.
	 */
	private function assertObjectExists( int $id, ElementType $type ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit assertion naming.
		if ( $type->is_term() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bypasses WPML's get_term() ID adjustment on purpose.
			$taxonomy = self::wpdb()->get_var( self::wpdb()->prepare( 'SELECT taxonomy FROM %i WHERE term_id = %d', self::wpdb()->term_taxonomy, $id ) );
			$this->assertSame( $type->name(), $taxonomy, "term {$id}" );
		} else {
			$this->assertSame( $type->name(), get_post_type( $id ), "post {$id}" );
		}
	}
}
