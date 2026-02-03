<?php
/**
 * PHPUnit bootstrap file for WooCommerce Fraud Protection.
 *
 * @package WooCommerce_Fraud_Protection
 */

// Composer autoloader.
$plugin_root = dirname( __DIR__ );
if ( file_exists( $plugin_root . '/vendor/autoload.php' ) ) {
	require_once $plugin_root . '/vendor/autoload.php';
}

// Get the tests directory.
$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

// Forward custom PHPUnit Polyfills configuration to PHPUnit bootstrap file.
$_phpunit_polyfills_path = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills_path ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills_path );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Could not find {$_tests_dir}/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Manually load the plugin and WooCommerce for testing.
 */
function _manually_load_plugins() {
	// Load WooCommerce first.
	$wc_dir = getenv( 'WC_DIR' );
	if ( ! $wc_dir ) {
		$wc_dir = dirname( __DIR__, 2 ) . '/woocommerce/plugins/woocommerce';
	}

	if ( file_exists( $wc_dir . '/woocommerce.php' ) ) {
		require $wc_dir . '/woocommerce.php';
	} else {
		echo "Could not find WooCommerce at {$wc_dir}/woocommerce.php" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo 'Set the WC_DIR environment variable to the WooCommerce plugin directory.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( 1 );
	}

	// Load this plugin.
	require dirname( __DIR__ ) . '/woocommerce-fraud-protection.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugins' );

// Start up the WP testing environment.
require "{$_tests_dir}/includes/bootstrap.php";

// Require the base test case.
require_once __DIR__ . '/framework/class-wc-fraud-protection-unit-test-case.php';
