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

// Load WooCommerce test framework.
$wc_dir = _get_wc_dir();

if ( $wc_dir ) {
	// Autoloader for WooCommerce testing tools (TestingContainer, MockableLegacyProxy).
	spl_autoload_register(
		function ( $class ) use ( $wc_dir ) {
			$tests_directory   = $wc_dir . '/tests';
			$helpers_directory = $tests_directory . '/php/helpers';

			if ( false === strpos( $class, '\\' ) ) {
				$helper_path = "$helpers_directory/$class.php";
				if ( file_exists( $helper_path ) ) {
					require $helper_path;
					return;
				}
			}

			$prefix   = 'Automattic\\WooCommerce\\Testing\\Tools\\';
			$base_dir = $tests_directory . '/Tools/';
			$len      = strlen( $prefix );
			if ( strncmp( $prefix, $class, $len ) === 0 ) {
				$relative_class = substr( $class, $len );
				$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
				if ( file_exists( $file ) ) {
					require $file;
				}
			}
		}
	);

	// Load WC test framework classes.
	require_once $wc_dir . '/tests/legacy/includes/wp-http-testcase.php';
	require_once $wc_dir . '/tests/legacy/framework/class-wc-unit-test-factory.php';
	require_once $wc_dir . '/tests/legacy/framework/class-wc-unit-test-case.php';

	// Load test helpers.
	foreach ( glob( $wc_dir . '/tests/legacy/framework/helpers/*.php' ) as $helper ) {
		require_once $helper;
	}

	// Load LoggerSpyTrait.
	$logger_spy_trait = $wc_dir . '/tests/php/helpers/LoggerSpyTrait.php';
	if ( file_exists( $logger_spy_trait ) ) {
		require_once $logger_spy_trait;
	}

	// Initialize TestingContainer with MockableLegacyProxy (enables LegacyProxy::reset()).
	$inner_container_property = new \ReflectionProperty( \Automattic\WooCommerce\Container::class, 'container' );
	$inner_container_property->setAccessible( true );
	$container       = wc_get_container();
	$inner_container = $inner_container_property->getValue( $container );
	$inner_container = new \Automattic\WooCommerce\Testing\Tools\TestingContainer( $inner_container );
	$inner_container_property->setValue( $container, $inner_container );
	$GLOBALS['wc_container'] = $inner_container;
}
