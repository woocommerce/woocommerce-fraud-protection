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
	require_once $plugin_dir . '/src/Internal/FraudProtection/SessionClearanceManager.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/SessionDataCollector.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/ApiClient.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/DecisionHandler.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/CartEventTracker.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/CheckoutEventTracker.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/PaymentMethodEventTracker.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/BlackboxScriptHandler.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/BlockedSessionNotice.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/SessionBlockingHandler.php';
	require_once $plugin_dir . '/src/Internal/FraudProtection/FraudProtectionController.php';

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

// Load WooCommerce test framework if available (when WC is installed from GitHub).
$wc_dir              = _get_wc_dir();
$wc_test_framework   = false;
$plugin_test_helpers = dirname( __FILE__ ) . '/php/helpers';

if ( $wc_dir ) {
	$wc_test_case_file = $wc_dir . '/tests/legacy/framework/class-wc-unit-test-case.php';
	if ( file_exists( $wc_test_case_file ) ) {
		$wc_test_framework = true;

		// Full WC test framework is available.
		$wp_http_testcase = $wc_dir . '/tests/legacy/includes/wp-http-testcase.php';
		if ( file_exists( $wp_http_testcase ) ) {
			require_once $wp_http_testcase;
		}
		require_once $wc_dir . '/tests/legacy/framework/class-wc-unit-test-factory.php';
		require_once $wc_test_case_file;

		// Load WC test helpers.
		foreach ( glob( $wc_dir . '/tests/legacy/framework/helpers/*.php' ) as $helper ) {
			require_once $helper;
		}

		// Load LoggerSpyTrait from WC's modern test helpers.
		$logger_spy_trait = $wc_dir . '/tests/php/helpers/LoggerSpyTrait.php';
		if ( file_exists( $logger_spy_trait ) ) {
			require_once $logger_spy_trait;
		}

		// Initialize TestingContainer if available.
		if ( class_exists( \Automattic\WooCommerce\Testing\Tools\TestingContainer::class ) ) {
			$inner_container_property = new \ReflectionProperty( \Automattic\WooCommerce\Container::class, 'container' );
			$inner_container_property->setAccessible( true );
			$container       = wc_get_container();
			$inner_container = $inner_container_property->getValue( $container );
			$inner_container = new \Automattic\WooCommerce\Testing\Tools\TestingContainer( $inner_container );
			$inner_container_property->setValue( $container, $inner_container );
			$GLOBALS['wc_container'] = $inner_container;
		}
	}
}

// Load our fallback test helpers if WC test framework is not available.
if ( ! $wc_test_framework ) {
	require_once $plugin_test_helpers . '/class-wc-helper-product.php';
	require_once $plugin_test_helpers . '/class-wc-helper-order.php';
	require_once $plugin_test_helpers . '/LoggerSpyTrait.php';
}

// Provide a fallback WC_Unit_Test_Case if WooCommerce test framework is not available.
if ( ! class_exists( 'WC_Unit_Test_Case' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class WC_Unit_Test_Case extends \WP_UnitTestCase {}
}
