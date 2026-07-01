<?php
/**
 * PHPUnit bootstrap file for WooCommerce Fraud Protection.
 *
 * Replicates WooCommerce core's unit-test bootstrap as closely as a standalone
 * plugin can, so tests run against the *real* WC test framework:
 *
 *  - the genuine `WC_Unit_Test_Case` (and the canonical factories/helpers/traits);
 *  - a `TestingContainer` swapped in for the runtime DI container, which replaces
 *    `LegacyProxy` with `MockableLegacyProxy` (enables `register_legacy_proxy_*`).
 *
 * The WC test framework is loaded from a local WooCommerce checkout located via
 * the `WC_DIR` env var or a set of fallback paths (see `locate_wc_dir()`).
 *
 * @package WooCommerce_Fraud_Protection
 */

use Automattic\WooCommerce\Testing\Tools\TestingContainer;

/**
 * Class WC_Fraud_Protection_Unit_Tests_Bootstrap
 */
class WC_Fraud_Protection_Unit_Tests_Bootstrap {

	/** @var string Directory where wordpress-tests-lib is installed. */
	private static $wp_tests_dir;

	/** @var string This plugin's root directory. */
	private static $plugin_dir;

	/** @var string WooCommerce plugin directory (the WC checkout). */
	private static $wc_dir;

	/** @var string WC legacy tests directory ($wc_dir/tests/legacy). */
	private static $wc_tests_dir;

	/** @var string WC tests root directory ($wc_dir/tests). */
	private static $wc_tests_root;

	/**
	 * Set up the unit testing environment.
	 */
	public static function init() {
		self::$plugin_dir = dirname( __DIR__ );

		self::$wc_dir = self::locate_wc_dir();
		if ( ! self::$wc_dir ) {
			echo 'Could not find WooCommerce. Set the WC_DIR environment variable.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit( 1 );
		}
		echo 'WooCommerce found at: ' . self::$wc_dir . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		self::$wc_tests_root = self::$wc_dir . '/tests';
		self::$wc_tests_dir  = self::$wc_dir . '/tests/legacy';

		// Make the WC testing tools (TestingContainer, MockableLegacyProxy, ...) autoloadable.
		self::register_autoloader_for_testing_tools();

		// phpcs:ignore WordPress.PHP.IniSet.display_errors_Blacklisted
		ini_set( 'display_errors', 'on' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		error_reporting( E_ALL );

		// Ensure server variable is set for WP email functions.
		if ( ! isset( $_SERVER['SERVER_NAME'] ) ) {
			$_SERVER['SERVER_NAME'] = 'localhost';
		}

		self::$wp_tests_dir = getenv( 'WP_TESTS_DIR' ) ? getenv( 'WP_TESTS_DIR' ) : rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';

		if ( ! file_exists( self::$wp_tests_dir . '/includes/functions.php' ) ) {
			echo 'Could not find ' . self::$wp_tests_dir . '/includes/functions.php, have you run bin/install-wp-tests.sh ?' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit( 1 );
		}

		// Load WP test functions so tests_add_filter() is available.
		require_once self::$wp_tests_dir . '/includes/functions.php';

		// Load WooCommerce and this plugin as must-use plugins.
		tests_add_filter( 'muplugins_loaded', array( __CLASS__, 'load_plugins' ) );

		// Install WooCommerce after the environment is loaded.
		tests_add_filter( 'setup_theme', array( __CLASS__, 'install_wc' ) );

		// Load PHPUnit Polyfills for the WP testing suite (use this plugin's vendor copy).
		if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
			define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', self::$plugin_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php' );
		}

		// Load the WP testing environment (fires muplugins_loaded -> load_plugins, setup_theme -> install_wc).
		require_once self::$wp_tests_dir . '/includes/bootstrap.php';

		// Ensure theme install tests use the direct filesystem method.
		if ( ! defined( 'FS_METHOD' ) ) {
			define( 'FS_METHOD', 'direct' );
		}

		// Load the WC testing framework (must run after WP's bootstrap so WP_UnitTestCase exists).
		self::includes();

		// Replace the runtime DI container with the TestingContainer. Must be the last step,
		// after WC has been loaded and its container initialized.
		self::initialize_dependency_injection();

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		error_reporting( error_reporting() & ~E_DEPRECATED );
	}

	/**
	 * Locate the WooCommerce plugin directory.
	 *
	 * @return string|null The WooCommerce directory path, or null if not found.
	 */
	private static function locate_wc_dir() {
		$env = getenv( 'WC_DIR' );
		if ( $env && file_exists( $env . '/woocommerce.php' ) ) {
			return $env;
		}

		$candidates = array(
			rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress/wp-content/plugins/woocommerce',
			dirname( self::$plugin_dir ) . '/woocommerce/plugins/woocommerce',
			dirname( self::$plugin_dir ) . '/woocommerce',
		);

		foreach ( $candidates as $path ) {
			if ( file_exists( $path . '/woocommerce.php' ) ) {
				return $path;
			}
		}

		return null;
	}

	/**
	 * Register an autoloader for the WC `Automattic\WooCommerce\Testing\Tools` namespace,
	 * resolved from the WooCommerce checkout's `tests/Tools` directory.
	 */
	private static function register_autoloader_for_testing_tools() {
		$base_dir = self::$wc_tests_root . '/Tools/';

		spl_autoload_register(
			function ( $class ) use ( $base_dir ) {
				$prefix = 'Automattic\\WooCommerce\\Testing\\Tools\\';
				$len    = strlen( $prefix );
				if ( strncmp( $prefix, $class, $len ) !== 0 ) {
					// Not ours; let the next registered autoloader handle it.
					return;
				}

				$relative_class = substr( $class, $len );
				$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';
				if ( file_exists( $file ) ) {
					require $file;
				}
			}
		);
	}

	/**
	 * Load WooCommerce and this plugin.
	 *
	 * Hooked to `muplugins_loaded`.
	 */
	public static function load_plugins() {
		define( 'WC_TAX_ROUNDING_MODE', 'auto' );
		define( 'WC_USE_TRANSACTIONS', false );

		require_once self::$wc_dir . '/woocommerce.php';

		// Load this plugin; it registers its autoloader and hooks when woocommerce_loaded fires.
		require self::$plugin_dir . '/woocommerce-fraud-protection.php';
	}

	/**
	 * Install WooCommerce after the test environment and WC have been loaded.
	 *
	 * Hooked to `setup_theme`.
	 */
	public static function install_wc() {
		// Clean existing install first.
		define( 'WP_UNINSTALL_PLUGIN', true );
		define( 'WC_REMOVE_ALL_DATA', true );
		include self::$wc_dir . '/uninstall.php';

		WC_Install::install();

		// Reload capabilities after install, see https://core.trac.wordpress.org/ticket/28374.
		if ( version_compare( $GLOBALS['wp_version'], '4.7', '<' ) ) {
			$GLOBALS['wp_roles']->reinit();
		} else {
			$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_roles();
		}

		echo esc_html( 'Installing WooCommerce...' . PHP_EOL );
	}

	/**
	 * Load the WC test framework: factories, mocks, base test cases, helpers and traits.
	 *
	 * Mirrors the relevant subset of WooCommerce core's `WC_Unit_Tests_Bootstrap::includes()`.
	 */
	public static function includes() {
		$framework = self::$wc_tests_dir . '/framework';
		$helpers   = $framework . '/helpers';

		// Framework.
		require_once $framework . '/class-wc-unit-test-factory.php';
		require_once $framework . '/class-wc-mock-session-handler.php';
		require_once $framework . '/class-wc-mock-wc-data.php';
		require_once $framework . '/class-wc-mock-wc-object-query.php';
		require_once $framework . '/class-wc-mock-payment-gateway.php';
		require_once $framework . '/class-wc-mock-enhanced-payment-gateway.php';
		require_once $framework . '/class-wc-payment-token-stub.php';
		require_once $framework . '/vendor/class-wp-test-spy-rest-server.php';

		// Test cases.
		require_once self::$wc_tests_dir . '/includes/wp-http-testcase.php';
		require_once $framework . '/class-wc-unit-test-case.php';
		require_once $framework . '/class-wc-rest-unit-test-case.php';

		// Helpers.
		require_once $helpers . '/class-wc-helper-product.php';
		require_once $helpers . '/class-wc-helper-coupon.php';
		require_once $helpers . '/class-wc-helper-fee.php';
		require_once $helpers . '/class-wc-helper-shipping.php';
		require_once $helpers . '/class-wc-helper-customer.php';
		require_once $helpers . '/class-wc-helper-order.php';
		require_once $helpers . '/class-wc-helper-shipping-zones.php';
		require_once $helpers . '/class-wc-helper-payment-token.php';
		require_once $helpers . '/class-wc-helper-settings.php';
	}

	/**
	 * Re-initialize the dependency injection engine.
	 *
	 * WC has already initialized DI as part of its load, but we need to replace the
	 * registered runtime container with one that has extra testing capabilities. We
	 * use reflection to grab the inner container the read-only `Container` stores in
	 * a private property.
	 *
	 * `TestingContainer` replaces the `LegacyProxy` instance with a `MockableLegacyProxy`.
	 *
	 * @throws \Exception When the `Container` class no longer has a 'container' property.
	 */
	private static function initialize_dependency_injection() {
		try {
			$inner_container_property = new \ReflectionProperty( \Automattic\WooCommerce\Container::class, 'container' );
		} catch ( ReflectionException $ex ) {
			throw new \Exception( "Error when trying to get the private 'container' property from the " . \Automattic\WooCommerce\Container::class . ' class using reflection during unit testing bootstrap, has the property been removed or renamed?' );
		}

		$inner_container_property->setAccessible( true );

		$container       = wc_get_container();
		$inner_container = $inner_container_property->getValue( $container );
		$inner_container = new TestingContainer( $inner_container );
		$inner_container_property->setValue( $container, $inner_container );

		$GLOBALS['wc_container'] = $inner_container;
	}
}

WC_Fraud_Protection_Unit_Tests_Bootstrap::init();
