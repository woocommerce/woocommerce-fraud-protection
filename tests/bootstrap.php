<?php
/**
 * PHPUnit bootstrap file for WooCommerce Fraud Protection.
 *
 * @package WooCommerce_Fraud_Protection
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find $_tests_dir/includes/functions.php, have you run bin/install-wp-tests.sh ?" . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit( 1 );
}

// Give access to tests_add_filter() function.
require_once $_tests_dir . '/includes/functions.php';

/**
 * Find WooCommerce plugin directory.
 *
 * @return string|null WooCommerce directory path or null if not found.
 */
function _get_wc_dir() {
	$wc_dir = getenv( 'WC_DIR' );

	if ( $wc_dir ) {
		return $wc_dir;
	}

	$possible_paths = array(
		rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/wp-content/plugins/woocommerce',
		dirname( __DIR__, 2 ) . '/woocommerce/plugins/woocommerce',
		dirname( __DIR__, 2 ) . '/woocommerce',
	);

	foreach ( $possible_paths as $path ) {
		if ( file_exists( $path . '/woocommerce.php' ) ) {
			return $path;
		}
	}

	return null;
}

/**
 * Manually load the plugin and WooCommerce for testing.
 */
function _manually_load_plugins() {
	$plugin_dir = dirname( __DIR__ );

	// Load our plugin's class files BEFORE WooCommerce so they take precedence
	// over WooCommerce's versions (prevents "Cannot redeclare class" errors).
	require_once $plugin_dir . '/src/SessionClearanceManager.php';
	require_once $plugin_dir . '/src/SessionDataCollector.php';
	require_once $plugin_dir . '/src/ApiClient.php';
	require_once $plugin_dir . '/src/DecisionHandler.php';
	require_once $plugin_dir . '/src/CartEventTracker.php';
	require_once $plugin_dir . '/src/CheckoutEventTracker.php';
	require_once $plugin_dir . '/src/PaymentMethodEventTracker.php';
	require_once $plugin_dir . '/src/BlackboxScriptHandler.php';
	require_once $plugin_dir . '/src/BlockedSessionNotice.php';
	require_once $plugin_dir . '/src/SessionVerifier.php';
	require_once $plugin_dir . '/src/OrderEventsTracker.php';
	require_once $plugin_dir . '/src/BlocksCheckoutProtector.php';
	require_once $plugin_dir . '/src/SessionBlockingHandler.php';
	require_once $plugin_dir . '/src/FraudProtectionController.php';

	$wc_dir = _get_wc_dir();

	if ( $wc_dir && file_exists( $wc_dir . '/woocommerce.php' ) ) {
		require $wc_dir . '/woocommerce.php';
	} else {
		echo "Could not find WooCommerce. Set the WC_DIR environment variable." . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit( 1 );
	}

	// Load this plugin (hooks registration only, classes already loaded above).
	require $plugin_dir . '/woocommerce-fraud-protection.php';
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugins' );

// Start up the WP testing environment.
require $_tests_dir . '/includes/bootstrap.php';

$plugin_test_helpers = dirname( __FILE__ ) . '/php/helpers';

require_once $plugin_test_helpers . '/class-wc-helper-product.php';
require_once $plugin_test_helpers . '/class-wc-helper-order.php';
require_once $plugin_test_helpers . '/LoggerSpyTrait.php';

// Provide a fallback WC_Unit_Test_Case if WooCommerce test framework is not available.
if ( ! class_exists( 'WC_Unit_Test_Case' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Unit_Test_Case extends \WP_UnitTestCase {}
}
