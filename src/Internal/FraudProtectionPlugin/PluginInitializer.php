<?php
/**
 * PluginInitializer class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the plugin.
 *
 * Defines the runtime constants, forces off WooCommerce Core's built-in fraud
 * protection, and once WooCommerce has loaded, resolves the controller from
 * WooCommerce's dependency injection container. The controller in turn wires
 * and registers every plugin component (see {@see FraudProtectionController::handle_init()}).
 */
class PluginInitializer {

	/**
	 * Minimum WooCommerce version this plugin supports.
	 *
	 * Mirrors the `WC requires at least` header in the plugin main file. As an
	 * MU-plugin this bypasses WordPress's plugin-dependency enforcement, so the
	 * requirement is also enforced at runtime in {@see handle_woocommerce_loaded()}.
	 *
	 * Before WooCommerce 9.5 the built-in DI container required explicit class
	 * registration, and thus the class resolutions in handle_woocommerce_loaded
	 * would fail.
	 */
	private const MINIMUM_WC_VERSION = '9.5.0';

	/**
	 * Bootstrap the plugin at load time (before WooCommerce loads).
	 * Must be executed from the plugin main file.
	 *
	 * @param string $plugin_file Absolute path to the plugin's main file (pass `__FILE__`).
	 *
	 * @return void
	 */
	public static function run( string $plugin_file ): void {
		define( 'WC_FRAUD_PROTECTION_VERSION', '0.1.6' );
		define( 'WC_FRAUD_PROTECTION_PLUGIN_DIR', dirname( $plugin_file ) );
		define( 'WC_FRAUD_PROTECTION_PLUGIN_URL', plugin_dir_url( $plugin_file ) );

		// Force-disable WC Core's built-in fraud protection feature to prevent
		// session and script conflicts with this plugin's implementation.
		add_filter( 'woocommerce_feature_fraud_protection_enabled', '__return_false', 999 );

		// Bootstrap after WooCommerce loads (MU-plugins load before regular plugins).
		add_action( 'woocommerce_loaded', array( self::class, 'handle_woocommerce_loaded' ) );
	}

	/**
	 * Resolve and register every plugin component once WooCommerce has loaded.
	 *
	 * @internal Hook callback for `woocommerce_loaded`.
	 *
	 * @return void
	 */
	public static function handle_woocommerce_loaded(): void {
		$autoload = WC_FRAUD_PROTECTION_PLUGIN_DIR . '/vendor/autoload.php';

		$bail_reason = null;
		if ( ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, self::MINIMUM_WC_VERSION, '<' ) ) {
			$found_version = defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown';
			$bail_reason   = 'requires WooCommerce ' . self::MINIMUM_WC_VERSION . ' or later (found ' . $found_version . '); initialization skipped.';
		} elseif ( ! is_readable( $autoload ) ) {
			// vendor/ missing (broken build / partial deploy). Bail before touching any namespaced class.
			$bail_reason = 'autoloader is not readable at ' . $autoload;
		}

		if ( ! is_null( $bail_reason ) ) {
			if ( self::should_emit_bail_notice( $bail_reason ) ) {
				error_log( 'WooCommerce Fraud Protection: ' . $bail_reason ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, QITStandard.PHP.DebugCode.DebugFunctionFound -- Last-resort logging before the plugin's own logger is available.
			}
			return;
		}

		// PSR-4 autoloader: classes are loaded lazily on first use.
		require_once $autoload;

		wc_get_container()->get( FraudProtectionController::class )->register();
	}

	/**
	 * Decide whether a bail-out notice should be written to the PHP error log,
	 * throttling to at most once per day per distinct reason.
	 *
	 * @param string $reason Bail-out reason, used both as the throttle key and the logged message.
	 *
	 * @return bool True if the caller should emit the notice now, false if throttled.
	 */
	private static function should_emit_bail_notice( string $reason ): bool {
		$transient_key = 'wcfp_init_bail_notice_' . md5( $reason );

		if ( false !== get_transient( $transient_key ) ) {
			return false;
		}

		set_transient( $transient_key, $reason, DAY_IN_SECONDS );

		return true;
	}
}
