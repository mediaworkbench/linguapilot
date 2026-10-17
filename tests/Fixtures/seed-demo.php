<?php
/**
 * Seeds the DDEV demo store with deliberate multilingual test cases (concept §13.4.4).
 *
 * Run from the DDEV project root:
 *     ddev wp eval-file wp-content/plugins/linguapilot/tests/Fixtures/seed-demo.php
 *
 * Dev tooling only: never loaded by the plugin and excluded from release zips (.gitattributes).
 * This is the one place in the repository that writes and calls WPML/WCML APIs directly
 * (exception to the gateway-only rule, see CLAUDE.md). Take a DDEV snapshot before running.
 *
 * Idempotent: everything it creates is tagged with `_linguapilot_seed`, titled "[Seed] …", and removed
 * again at the start of the next run. Expected ability results are checked by
 * tests/Store/SeedScenarioStoreTest.php.
 *
 * Order of operations matters: WCML syncs translations from their original, so every original is
 * built completely first, translations are created and linked next, and the deliberate mistakes are
 * applied last as direct meta writes on the translations.
 *
 * @package MediaWorkbench\LinguaPilot
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML/WooCommerce hooks, dev tooling.
// No strict_types: `wp eval-file` runs this file through eval(), which rejects the declaration here.

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) ) {
	exit( 1 );
}

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to seed: WP_ENVIRONMENT_TYPE is not "local".' );
}

if ( ! defined( 'ICL_SITEPRESS_VERSION' ) || ! class_exists( 'WooCommerce' ) ) {
	WP_CLI::error( 'WPML and WooCommerce must be active.' );
}

const LINGUAPILOT_SEED_META   = '_linguapilot_seed';
const LINGUAPILOT_SEED_PREFIX = '[Seed] ';

/**
 * Removes everything a previous run created.
 */
function linguapilot_seed_cleanup(): void {
	global $wpdb;

	/**
	 * With 'return' => 'ids' this is a list of IDs (the WooCommerce stubs type it as WC_Order[]).
	 *
	 * @var list<int|string> $found
	 */
	$found     = wc_get_orders(
		array(
			'limit'    => -1,
			'meta_key' => LINGUAPILOT_SEED_META, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Dev tooling.
			'return'   => 'ids',
		)
	);
	$order_ids = array_map( 'absint', $found );
	foreach ( $order_ids as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Dev tooling.
	$posts = $wpdb->get_col( $wpdb->prepare( 'SELECT post_id FROM %i WHERE meta_key = %s', $wpdb->postmeta, LINGUAPILOT_SEED_META ) );
	foreach ( array_map( 'intval', $posts ) as $post_id ) {
		wp_delete_post( $post_id, true );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Dev tooling.
	$terms = $wpdb->get_results( $wpdb->prepare( 'SELECT m.term_id, tt.taxonomy FROM %i m JOIN %i tt ON tt.term_id = m.term_id WHERE m.meta_key = %s', $wpdb->termmeta, $wpdb->term_taxonomy, LINGUAPILOT_SEED_META ) );
	$terms = $terms ?? array();
	foreach ( $terms as $term ) {
		$taxonomy = (string) $term->taxonomy;
		if ( '' !== $taxonomy ) {
			wp_delete_term( (int) $term->term_id, $taxonomy );
		}
	}

	WP_CLI::log( sprintf( 'Removed previous seed: %d orders, %d posts, %d terms.', count( $order_ids ), count( $posts ), count( $terms ) ) );
}

/**
 * Runs a callback with WPML switched to a language, so new content is assigned to it.
 *
 * @param string   $language Language code.
 * @param callable $callback Callback.
 * @return mixed
 */
function linguapilot_seed_in_language( string $language, callable $callback ) {
	$previous = apply_filters( 'wpml_current_language', null );
	do_action( 'wpml_switch_language', $language );
	try {
		return $callback();
	} finally {
		do_action( 'wpml_switch_language', $previous );
	}
}

/**
 * Links an element to the translation group of an original.
 *
 * @param int    $element_id  Post ID, or term_taxonomy_id for terms.
 * @param string $type        WPML element type.
 * @param int    $original_id Original's element ID.
 * @param string $language    Translation language.
 */
function linguapilot_seed_link( int $element_id, string $type, int $original_id, string $language ): void {
	$trid = (int) apply_filters( 'wpml_element_trid', null, $original_id, $type );
	do_action(
		'wpml_set_element_language_details',
		array(
			'element_id'           => $element_id,
			'element_type'         => $type,
			'trid'                 => $trid,
			'language_code'        => $language,
			'source_language_code' => apply_filters( 'wpml_default_language', null ),
		)
	);
}

/**
 * Tags a post as seed data.
 *
 * @param int $post_id Post ID.
 */
function linguapilot_seed_tag( int $post_id ): int {
	update_post_meta( $post_id, LINGUAPILOT_SEED_META, '1' );
	return $post_id;
}

/**
 * Creates a simple product in the current WPML language.
 *
 * @param string               $title Title (prefixed with "[Seed] ").
 * @param array<string, mixed> $props status, price, manage_stock, stock.
 */
function linguapilot_seed_simple( string $title, array $props = array() ): int {
	$product = new WC_Product_Simple();
	$product->set_name( LINGUAPILOT_SEED_PREFIX . $title );
	$product->set_status( $props['status'] ?? 'publish' );
	$product->set_regular_price( (string) ( $props['price'] ?? '10' ) );
	$product->set_manage_stock( (bool) ( $props['manage_stock'] ?? false ) );
	if ( isset( $props['stock'] ) ) {
		$product->set_stock_quantity( (int) $props['stock'] );
	}

	return linguapilot_seed_tag( $product->save() );
}

/**
 * Creates a translation of a simple product: same data, linked to the original, SKU copied afterwards
 * (WooCommerce rejects a duplicate SKU until WPML knows the post is a translation).
 *
 * @param int                  $original_id Original product ID.
 * @param string               $language    Language.
 * @param string               $title       Title.
 * @param array<string, mixed> $props       Overrides (status).
 */
function linguapilot_seed_translate_simple( int $original_id, string $language, string $title, array $props = array() ): int {
	$original = wc_get_product( $original_id );
	$id       = linguapilot_seed_in_language(
		$language,
		static fn (): int => linguapilot_seed_simple(
			$title,
			array(
				'status'       => $props['status'] ?? 'publish',
				'price'        => $original ? $original->get_regular_price( 'edit' ) : '10',
				'manage_stock' => $original && $original->get_manage_stock( 'edit' ),
				'stock'        => $original ? $original->get_stock_quantity( 'edit' ) : null,
			)
		)
	);
	linguapilot_seed_link( $id, 'post_product', $original_id, $language );
	if ( $original && '' !== $original->get_sku( 'edit' ) ) {
		update_post_meta( $id, '_sku', $original->get_sku( 'edit' ) );
	}

	return $id;
}

/**
 * Creates a pa_color term (tagged) in the current language.
 *
 * @param string $name Name.
 * @return array{term_id: int, term_taxonomy_id: int, slug: string}
 */
function linguapilot_seed_color( string $name ): array {
	$term = wp_insert_term( LINGUAPILOT_SEED_PREFIX . $name, 'pa_color', array( 'slug' => sanitize_title( 'seed-' . $name ) ) );
	if ( is_wp_error( $term ) ) {
		WP_CLI::error( 'Could not create term ' . $name . ': ' . $term->get_error_message() );
	}
	update_term_meta( (int) $term['term_id'], LINGUAPILOT_SEED_META, '1' );
	$slug = get_term_field( 'slug', (int) $term['term_id'], 'pa_color' );

	return array(
		'term_id'          => (int) $term['term_id'],
		'term_taxonomy_id' => (int) $term['term_taxonomy_id'],
		'slug'             => is_string( $slug ) ? $slug : '',
	);
}

/**
 * Creates a translated pa_color term linked to its original.
 *
 * @param array{term_id: int, term_taxonomy_id: int, slug: string} $original Original term.
 * @param string                                                   $language Language.
 * @param string                                                   $name     Translated name.
 * @return array{term_id: int, term_taxonomy_id: int, slug: string}
 */
function linguapilot_seed_translate_color( array $original, string $language, string $name ): array {
	$term = linguapilot_seed_in_language( $language, static fn (): array => linguapilot_seed_color( $name ) );
	linguapilot_seed_link( $term['term_taxonomy_id'], 'tax_pa_color', $original['term_taxonomy_id'], $language );

	return $term;
}

/**
 * Builds a variable product with pa_color + local "Size" attributes and its variations.
 *
 * @param string                                   $title      Title.
 * @param list<int>                                $color_ids  pa_color term IDs on the product.
 * @param list<string>                             $sizes      Size options.
 * @param list<array{color: string, size: string}> $variations Variation attribute values (term slug, size option).
 * @return array{product: int, variations: list<int>}
 */
function linguapilot_seed_variable( string $title, array $color_ids, array $sizes, array $variations ): array {
	$color = new WC_Product_Attribute();
	$color->set_id( wc_attribute_taxonomy_id_by_name( 'pa_color' ) );
	$color->set_name( 'pa_color' );
	$color->set_options( $color_ids );
	$color->set_visible( true );
	$color->set_variation( true );

	$size = new WC_Product_Attribute();
	$size->set_name( 'Size' );
	$size->set_options( $sizes );
	$size->set_visible( true );
	$size->set_variation( true );

	$product = new WC_Product_Variable();
	$product->set_name( LINGUAPILOT_SEED_PREFIX . $title );
	$product->set_status( 'publish' );
	$product->set_attributes( array( $color, $size ) );
	$product_id = linguapilot_seed_tag( $product->save() );
	wp_set_object_terms( $product_id, $color_ids, 'pa_color' );

	$variation_ids = array();
	foreach ( $variations as $values ) {
		$variation = new WC_Product_Variation();
		$variation->set_parent_id( $product_id );
		$variation->set_attributes(
			array(
				'pa_color' => $values['color'],
				'size'     => $values['size'],
			)
		);
		$variation->set_regular_price( '30' );
		$variation->set_status( 'publish' );
		$variation_ids[] = linguapilot_seed_tag( $variation->save() );
	}

	return array(
		'product'    => $product_id,
		'variations' => $variation_ids,
	);
}

/**
 * Creates a completed guest order and imports it into WooCommerce Analytics right away.
 *
 * @param array<int, int> $lines    Product ID => quantity.
 * @param int             $days_ago Order age.
 */
function linguapilot_seed_order( array $lines, int $days_ago ): int {
	$order = wc_create_order();
	if ( is_wp_error( $order ) ) {
		WP_CLI::error( 'Could not create order: ' . $order->get_error_message() );
	}
	foreach ( $lines as $product_id => $quantity ) {
		$product = wc_get_product( $product_id );
		if ( $product ) {
			$order->add_product( $product, $quantity );
		}
	}
	$order->set_date_created( time() - $days_ago * DAY_IN_SECONDS );
	$order->calculate_totals();
	$order->set_status( 'completed' );
	$order->update_meta_data( LINGUAPILOT_SEED_META, '1' );
	$order_id = $order->save();

	// Import into WooCommerce Analytics right away instead of waiting for the scheduled batch (internal API; dev tooling only).
	$import = array( 'Automattic\\WooCommerce\\Internal\\Admin\\Schedulers\\OrdersScheduler', 'import' );
	if ( is_callable( $import ) ) {
		call_user_func( $import, $order_id );
	}

	return $order_id;
}

// ---------------------------------------------------------------------------------------------
// Seed.
// ---------------------------------------------------------------------------------------------

/**
 * Step 1: originals, built completely before any translation exists (default language).
 *
 * @return array{
 *     complete: int, mug: int, cap: int, scarf: int, lamp: int, chair: int, hoodie: int, hoodie_variations: list<int>,
 *     red: array{term_id: int, term_taxonomy_id: int, slug: string}, blue: array{term_id: int, term_taxonomy_id: int, slug: string},
 *     category_ttid: int|null
 * }
 */
function linguapilot_seed_originals(): array {
	$products = array(
		'complete' => linguapilot_seed_simple( 'Complete Tee', array( 'price' => '20' ) ),
		'mug'      => linguapilot_seed_simple( 'Missing FR Mug', array( 'price' => '8' ) ),
		'cap'      => linguapilot_seed_simple( 'Draft DE Cap', array( 'price' => '10' ) ),
		'scarf'    => linguapilot_seed_simple(
			'Sync Error Scarf',
			array(
				'price'        => '25',
				'manage_stock' => true,
				'stock'        => 12,
			)
		),
		'lamp'     => linguapilot_seed_simple( 'Manual Price Lamp', array( 'price' => '40' ) ),
		'chair'    => linguapilot_seed_simple( 'Manual Price Chair', array( 'price' => '90' ) ),
	);
	foreach ( $products as $key => $id ) {
		update_post_meta( $id, '_sku', 'SEED-' . strtoupper( 'complete' === $key ? 'tee' : $key ) );
	}

	$red    = linguapilot_seed_color( 'Red' );
	$blue   = linguapilot_seed_color( 'Blue' );
	$hoodie = linguapilot_seed_variable(
		'Variable Hoodie',
		array( $red['term_id'], $blue['term_id'] ),
		array( 'S', 'M', 'L' ),
		array(
			array(
				'color' => $red['slug'],
				'size'  => 'S',
			),
			array(
				'color' => $blue['slug'],
				'size'  => 'M',
			),
		)
	);

	$category      = wp_insert_term( LINGUAPILOT_SEED_PREFIX . 'Category', 'product_cat', array( 'slug' => 'seed-category' ) );
	$category_ttid = null;
	if ( ! is_wp_error( $category ) ) {
		update_term_meta( (int) $category['term_id'], LINGUAPILOT_SEED_META, '1' );
		$category_ttid = (int) $category['term_taxonomy_id'];
	}

	// Manual prices (WCML, on originals): Lamp lacks USD, Chair is complete, the Blue variation lacks CHF.
	$manual = array(
		$products['lamp']        => array( 'CHF' => '49' ),
		$products['chair']       => array(
			'CHF' => '99',
			'USD' => '105',
		),
		$hoodie['variations'][1] => array( 'USD' => '35' ),
	);
	foreach ( $manual as $id => $prices ) {
		update_post_meta( $id, '_wcml_custom_prices_status', '1' );
		foreach ( $prices as $currency => $price ) {
			update_post_meta( $id, '_regular_price_' . $currency, $price );
			update_post_meta( $id, '_price_' . $currency, $price );
		}
	}

	return $products + array(
		'hoodie'            => $hoodie['product'],
		'hoodie_variations' => $hoodie['variations'],
		'red'               => $red,
		'blue'              => $blue,
		'category_ttid'     => $category_ttid,
	);
}

/**
 * Step 2: translations, linked to the originals.
 *
 * @param array<string, mixed> $o Originals (shape: linguapilot_seed_originals()).
 * @phpstan-param array{
 *     complete: int, mug: int, cap: int, scarf: int, lamp: int, chair: int, hoodie: int, hoodie_variations: list<int>,
 *     red: array{term_id: int, term_taxonomy_id: int, slug: string}, blue: array{term_id: int, term_taxonomy_id: int, slug: string},
 *     category_ttid: int|null
 * } $o
 * @return array<string, int> Translation IDs by key (e.g. `cap_fr`).
 */
function linguapilot_seed_translations( array $o ): array {
	$t = array(
		'complete_de' => linguapilot_seed_translate_simple( $o['complete'], 'de', 'Komplettes T-Shirt' ),
		'complete_fr' => linguapilot_seed_translate_simple( $o['complete'], 'fr', 'T-shirt complet' ),
		'mug_de'      => linguapilot_seed_translate_simple( $o['mug'], 'de', 'Tasse ohne FR' ),
		'cap_de'      => linguapilot_seed_translate_simple( $o['cap'], 'de', 'Mütze (Entwurf)', array( 'status' => 'draft' ) ),
		'cap_fr'      => linguapilot_seed_translate_simple( $o['cap'], 'fr', 'Casquette' ),
		'scarf_de'    => linguapilot_seed_translate_simple( $o['scarf'], 'de', 'Schal' ),
		'scarf_fr'    => linguapilot_seed_translate_simple( $o['scarf'], 'fr', 'Écharpe' ),
		'lamp_de'     => linguapilot_seed_translate_simple( $o['lamp'], 'de', 'Lampe' ),
		'lamp_fr'     => linguapilot_seed_translate_simple( $o['lamp'], 'fr', 'Lampe' ),
		'chair_de'    => linguapilot_seed_translate_simple( $o['chair'], 'de', 'Stuhl' ),
		'chair_fr'    => linguapilot_seed_translate_simple( $o['chair'], 'fr', 'Chaise' ),
	);

	// Terms: DE translates both colours, FR only Red.
	$rot   = linguapilot_seed_translate_color( $o['red'], 'de', 'Rot' );
	$blau  = linguapilot_seed_translate_color( $o['blue'], 'de', 'Blau' );
	$rouge = linguapilot_seed_translate_color( $o['red'], 'fr', 'Rouge' );

	if ( null !== $o['category_ttid'] ) {
		$category_ttid = $o['category_ttid'];
		$category_de   = linguapilot_seed_in_language( 'de', static fn () => wp_insert_term( LINGUAPILOT_SEED_PREFIX . 'Kategorie', 'product_cat', array( 'slug' => 'seed-kategorie' ) ) );
		if ( is_array( $category_de ) ) {
			update_term_meta( (int) $category_de['term_id'], LINGUAPILOT_SEED_META, '1' );
			linguapilot_seed_link( (int) $category_de['term_taxonomy_id'], 'tax_product_cat', $category_ttid, 'de' );
		}
	}

	// Variable Hoodie DE: correct (terms translated, both variations, label "Grösse"; Size options identical → review).
	$hoodie_de = linguapilot_seed_in_language(
		'de',
		static fn (): array => linguapilot_seed_variable(
			'Variabler Hoodie',
			array( $rot['term_id'], $blau['term_id'] ),
			array( 'S', 'M', 'L' ),
			array(
				array(
					'color' => $rot['slug'],
					'size'  => 'S',
				),
				array(
					'color' => $blau['slug'],
					'size'  => 'M',
				),
			)
		)
	);
	linguapilot_seed_link( $hoodie_de['product'], 'post_product', $o['hoodie'], 'de' );
	foreach ( $hoodie_de['variations'] as $index => $variation_id ) {
		linguapilot_seed_link( $variation_id, 'post_product_variation', $o['hoodie_variations'][ $index ], 'de' );
	}
	update_post_meta( $hoodie_de['product'], 'attr_label_translations', array( 'de' => array( 'size' => 'Grösse' ) ) );

	// Variable Hoodie FR: broken (Blue untranslated, Blue variation missing, Size "P | M", Red variation keeps "S", no label).
	$hoodie_fr = linguapilot_seed_in_language(
		'fr',
		static fn (): array => linguapilot_seed_variable(
			'Sweat à capuche',
			array( $rouge['term_id'] ),
			array( 'P', 'M' ),
			array(
				array(
					'color' => $rouge['slug'],
					'size'  => 'S',
				),
			)
		)
	);
	linguapilot_seed_link( $hoodie_fr['product'], 'post_product', $o['hoodie'], 'fr' );
	linguapilot_seed_link( $hoodie_fr['variations'][0], 'post_product_variation', $o['hoodie_variations'][0], 'fr' );

	return $t;
}

/**
 * Seeds all cases. See the file header for the order of operations.
 */
function linguapilot_seed_run(): void {
	linguapilot_seed_cleanup();

	$default  = apply_filters( 'wpml_default_language', null );
	$previous = apply_filters( 'wpml_current_language', null );
	do_action( 'wpml_switch_language', $default );
	try {
		$originals = linguapilot_seed_originals();
	} finally {
		do_action( 'wpml_switch_language', $previous );
	}

	$t = linguapilot_seed_translations( $originals );

	// Step 3: deliberate mistakes, as direct meta writes on translations (no WooCommerce setters → no WCML sync).
	update_post_meta( $t['scarf_de'], '_sku', 'SEED-SCARF-DE' ); // sync_error: sku.
	update_post_meta( $t['scarf_fr'], '_stock', '3' );           // sync_error: stock_quantity (original 12).
	update_post_meta( $t['lamp_fr'], '_regular_price', '44' );   // Differs, but FR uses CHF with a manual price → skipped.

	// Step 4: orders bought through translations (normalization demo): Cap 5× via FR + 1× original, Mug 3× via DE.
	$orders = array(
		linguapilot_seed_order( array( $t['cap_fr'] => 5 ), 2 ),
		linguapilot_seed_order( array( $originals['cap'] => 1 ), 3 ),
		linguapilot_seed_order( array( $t['mug_de'] => 3 ), 4 ),
	);

	WP_CLI::success(
		sprintf(
			"Seeded %d originals, %d translations, %d orders (Cap %d, Mug %d, Hoodie %d).\nVerify: ddev exec -d /var/www/html/wp-content/plugins/linguapilot vendor/bin/phpunit -c phpunit-store.xml.dist --filter SeedScenario",
			7,
			count( $t ) + 2,
			count( $orders ),
			$originals['cap'],
			$originals['mug'],
			$originals['hoodie']
		)
	);
}

linguapilot_seed_run();
