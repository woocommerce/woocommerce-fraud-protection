<?php
/**
 * PluginInitializer class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Compat\PayPalCompat;
use Automattic\WooCommerce\FraudProtection\Compat\PayPalPaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Compat\SquarePaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Compat\StripePaymentDataCompat;
use Automattic\WooCommerce\FraudProtection\Compat\SubscriptionsChangePaymentCompat;
use Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsPaymentDataCompat;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstraps the plugin.
 *
 * Defines the runtime constants, forces off WooCommerce Core's built-in fraud
 * protection, and once WooCommerce has loaded, wires every component through
 * WooCommerce's dependency injection container.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class PluginInitializer {

	/**
	 * Bootstrap the plugin at load time (before WooCommerce loads).
	 * Must be executed from the plugin main file.
	 *
	 * @internal
	 *
	 * @param string $plugin_file Absolute path to the plugin's main file (pass `__FILE__`).
	 *
	 * @return void
	 */
	public static function run( string $plugin_file ): void {
		define( 'WC_FRAUD_PROTECTION_VERSION', '0.1.3' );
		define( 'WC_FRAUD_PROTECTION_PLUGIN_DIR', dirname( $plugin_file ) );
		define( 'WC_FRAUD_PROTECTION_PLUGIN_URL', plugin_dir_url( $plugin_file ) );

		// Force-disable WC Core's built-in fraud protection feature to prevent
		// session and script conflicts with this plugin's implementation.
		add_filter( 'woocommerce_feature_fraud_protection_enabled', '__return_false', 999 );

		// Bootstrap after WooCommerce loads (MU-plugins load before regular plugins).
		add_action( 'woocommerce_loaded', array( self::class, 'on_woocommerce_loaded' ) );
	}

	/**
	 * Resolve and register every plugin component once WooCommerce has loaded.
	 *
	 * @internal Hook callback for `woocommerce_loaded`.
	 *
	 * @return void
	 */
	public static function on_woocommerce_loaded(): void {
		// PSR-4 autoloader: classes are loaded lazily on first use.
		$autoload = WC_FRAUD_PROTECTION_PLUGIN_DIR . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			// vendor/ missing (broken build / partial deploy). Bail before touching any namespaced class.
			error_log( 'WooCommerce Fraud Protection: autoloader is not readable at ' . $autoload ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, QITStandard.PHP.DebugCode.DebugFunctionFound -- Last-resort logging before the plugin's own logger is available.
			return;
		}
		require_once $autoload;

		$container = wc_get_container();

		$container->get( FraudProtectionController::class )->register();
		$container->get( StripePaymentDataCompat::class )->register();
		$container->get( SquarePaymentDataCompat::class )->register();
		$container->get( PayPalPaymentDataCompat::class )->register();
		$container->get( WooPaymentsPaymentDataCompat::class )->register();
		$container->get( PayPalCompat::class )->register();
		$container->get( SubscriptionsChangePaymentCompat::class )->register();
	}
}
