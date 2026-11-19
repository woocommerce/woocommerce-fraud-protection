<?php
/**
 * Set up the WordPress Playground preview that pull requests link to.
 *
 * The "Test the build" workflow uploads this file, the two seeders next to it
 * and the plugin ZIP as one artifact. The Blueprint in that workflow installs
 * WooCommerce and the ZIP, then runs this file once with WP-CLI:
 *
 *   wp eval-file playground-setup.php [empty]
 *
 *   empty  Pass the literal word `empty` to leave the checkout attempts list
 *          and the rules empty.
 *
 * It skips the WooCommerce setup wizard, adds a product and an offline payment
 * method so a checkout can complete, installs the plugin's tables, and fills
 * the checkout attempts list and the rules with made-up rows from
 * seed-fake-sessions.php and seed-fake-rules.php.
 *
 * The "Pull request previews" section of README.md describes the preview.
 *
 * @package WooCommerce\FraudProtection
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WP-CLI eval-file script, not a loaded plugin file.

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! function_exists( 'WC' ) || ! defined( 'WC_FRAUD_PROTECTION_VERSION' ) ) {
	WP_CLI::error( 'Activate WooCommerce and WooCommerce Fraud Protection first.' );
}

$args      = isset( $args ) && is_array( $args ) ? $args : array();
$seed_data = ! in_array( 'empty', $args, true );

// A store that is ready to sell: no setup wizard, no "coming soon" page.
delete_transient( '_wc_activation_redirect' );
update_option( 'woocommerce_onboarding_profile', array( 'skipped' => true ) );
update_option( 'woocommerce_task_list_hidden', 'yes' );
update_option( 'woocommerce_task_list_reminder_bar_hidden', 'yes' );
update_option( 'woocommerce_allow_tracking', 'no' );
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_store_address', '166 Crosby Street' );
update_option( 'woocommerce_store_city', 'New York' );
update_option( 'woocommerce_default_country', 'US:NY' );
update_option( 'woocommerce_store_postcode', '10012' );

// New stores use high-performance order storage. A store installed by a
// Blueprint starts on the legacy tables.
update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );

// Cash on delivery needs no payment provider.
$cod_settings            = get_option( 'woocommerce_cod_settings', array() );
$cod_settings            = is_array( $cod_settings ) ? $cod_settings : array();
$cod_settings['enabled'] = 'yes';
update_option( 'woocommerce_cod_settings', $cod_settings );

// A product to buy. The slug guard keeps a second run from adding another.
if ( null === get_page_by_path( 'fp-test-product', OBJECT, 'product' ) ) {
	$product = new WC_Product_Simple();
	$product->set_name( 'Fraud Protection Test Product' );
	$product->set_slug( 'fp-test-product' );
	$product->set_regular_price( '10' );
	$product->set_virtual( true );
	$product->save();
}

// The checkout page uses the Checkout block. This page has the shortcode
// checkout, which the plugin protects through a different code path.
if ( null === get_page_by_path( 'shortcode-checkout' ) ) {
	wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Shortcode checkout',
			'post_name'    => 'shortcode-checkout',
			'post_content' => '<!-- wp:shortcode -->[woocommerce_checkout]<!-- /wp:shortcode -->',
		)
	);
}

// The tables are not created on their own under WP-CLI.
$run_in_this_process = array( 'launch' => false );
WP_CLI::runcommand( 'wc fraud-protection database install', $run_in_this_process );
if ( $seed_data ) {
	WP_CLI::runcommand( 'eval-file ' . escapeshellarg( __DIR__ . '/seed-fake-sessions.php' ) . ' 60', $run_in_this_process );
	WP_CLI::runcommand( 'eval-file ' . escapeshellarg( __DIR__ . '/seed-fake-rules.php' ), $run_in_this_process );
}

WP_CLI::success( 'The preview store is ready.' );
