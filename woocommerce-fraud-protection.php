<?php
/**
 * Plugin Name: WooCommerce Fraud Protection
 * Description: A plugin to protect WooCommerce from fraud.
 * Version: 0.1.3
 * Author: Automattic
 *
 * @package WooCommerce\FraudProtection
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;

defined( 'ABSPATH' ) || exit;

// Kill-switch: define WC_FRAUD_PROTECTION_DISABLED as true to disable.
if ( defined( 'WC_FRAUD_PROTECTION_DISABLED' ) && WC_FRAUD_PROTECTION_DISABLED ) {
	return;
}

define( 'WC_FRAUD_PROTECTION_VERSION', '0.1.3' );
define( 'WC_FRAUD_PROTECTION_PLUGIN_DIR', __DIR__ );
define( 'WC_FRAUD_PROTECTION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Force-disable WC Core's built-in fraud protection feature to prevent
// session and script conflicts with this plugin's implementation.
add_filter( 'woocommerce_feature_fraud_protection_enabled', '__return_false', 999 );

// Bootstrap after WooCommerce loads (MU-plugins load before regular plugins).
add_action(
	'woocommerce_loaded',
	function () {
		// PSR-4 autoloader: classes are loaded lazily on first use.
		$autoload = WC_FRAUD_PROTECTION_PLUGIN_DIR . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			// vendor/ missing (broken build / partial deploy). Bail before touching any namespaced class.
			error_log( 'WooCommerce Fraud Protection: autoloader is not readable at ' . $autoload ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, QITStandard.PHP.DebugCode.DebugFunctionFound -- Last-resort logging before the plugin's own logger is available.
			return;
		}
		require_once $autoload;

		// Core dependencies.
		$session_manager = new \Automattic\WooCommerce\FraudProtection\SessionClearanceManager();

		$api_client = new \Automattic\WooCommerce\FraudProtection\ApiClient();

		$decision_handler = new \Automattic\WooCommerce\FraudProtection\DecisionHandler();
		$decision_handler->init( $session_manager );

		$session_data_collector = new \Automattic\WooCommerce\FraudProtection\SessionDataCollector();
		$session_data_collector->init( $session_manager );

		// Event trackers.
		$cart_event_tracker = new \Automattic\WooCommerce\FraudProtection\CartEventTracker();
		$cart_event_tracker->init( $session_data_collector );

		$checkout_event_tracker = new \Automattic\WooCommerce\FraudProtection\CheckoutEventTracker();
		$checkout_event_tracker->init( $session_data_collector );

		$payment_method_event_tracker = new \Automattic\WooCommerce\FraudProtection\PaymentMethodEventTracker();
		$payment_method_event_tracker->init( $session_data_collector );

		// Notice and script handlers.
		$blocked_notice = new \Automattic\WooCommerce\FraudProtection\BlockedSessionNotice();
		$blocked_notice->init( $session_manager );

		$blackbox_handler = new \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler();
		$blackbox_handler->init( $session_manager );

		// Session blocking handler.
		$session_blocking_handler = new \Automattic\WooCommerce\FraudProtection\SessionBlockingHandler();
		$session_blocking_handler->init( $session_manager, $blocked_notice );

		$payment_data_resolver = new \Automattic\WooCommerce\FraudProtection\PaymentDataResolver();

		$session_verifier = new \Automattic\WooCommerce\FraudProtection\SessionVerifier();
		$session_verifier->init( $session_data_collector, $api_client, $decision_handler, $payment_data_resolver );

		$order_events_tracker = new \Automattic\WooCommerce\FraudProtection\OrderEventsTracker();
		$order_events_tracker->init( $api_client );

		$stripe_compat = new \Automattic\WooCommerce\FraudProtection\Compat\StripePaymentDataCompat();
		$stripe_compat->register();

		$square_compat = new \Automattic\WooCommerce\FraudProtection\Compat\SquarePaymentDataCompat();
		$square_compat->register();

		$paypal_payment_data_compat = new \Automattic\WooCommerce\FraudProtection\Compat\PayPalPaymentDataCompat();
		$paypal_payment_data_compat->register();

		$woopayments_compat = new \Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsPaymentDataCompat();
		$woopayments_compat->register();

		$paypal_compat = new \Automattic\WooCommerce\FraudProtection\Compat\PayPalCompat();
		$paypal_compat->init( $session_verifier, $blocked_notice );
		$paypal_compat->register();

		$subscriptions_change_payment_compat = new \Automattic\WooCommerce\FraudProtection\Compat\SubscriptionsChangePaymentCompat();
		$subscriptions_change_payment_compat->init( $session_verifier, $blocked_notice );
		$subscriptions_change_payment_compat->register();

		$blocks_checkout_protector = new \Automattic\WooCommerce\FraudProtection\BlocksCheckoutProtector();
		$blocks_checkout_protector->init( $session_verifier, $blocked_notice );

		$shortcode_checkout_protector = new \Automattic\WooCommerce\FraudProtection\ShortcodeCheckoutProtector();
		$shortcode_checkout_protector->init( $session_verifier, $blocked_notice );

		$add_payment_method_protector = new \Automattic\WooCommerce\FraudProtection\AddPaymentMethodProtector();
		$add_payment_method_protector->init( $session_verifier, $blocked_notice );

		$pay_for_order_protector = new \Automattic\WooCommerce\FraudProtection\PayForOrderProtector();
		$pay_for_order_protector->init( $session_verifier, $blocked_notice );

		// Main controller.
		$controller = new \Automattic\WooCommerce\FraudProtection\FraudProtectionController();
		$controller->init(
			$blocked_notice,
			$blackbox_handler,
			$cart_event_tracker,
			$checkout_event_tracker,
			$payment_method_event_tracker,
			$session_blocking_handler,
			$session_verifier,
			$blocks_checkout_protector,
			$shortcode_checkout_protector,
			$add_payment_method_protector,
			$pay_for_order_protector
		);
		$controller->register();
	}
);

/**
 * Report a normalized payment-outcome event to the Blackbox API.
 *
 * This is the public API for 3rd-party plugins (e.g. payment gateways) to report
 * payment, dispute, and refund outcomes correlated with the original fraud-check
 * session. Build `$context` with `ReportContextData::from_array()`.
 *
 * Must be called after the session ID has been persisted to order meta
 * (i.e. after `woocommerce_store_api_checkout_order_processed`).
 *
 * @param \WC_Order         $order   The order to report on.
 * @param string            $source  The source of the event. Use ApiClient::REPORT_SOURCE_* constants; an unknown value defaults to REPORT_SOURCE_API.
 * @param ReportContextData $context The normalized event context.
 * @param string            $notes   Free-form notes. Must not contain raw gateway or customer data.
 */
function wc_fraud_protection_report( \WC_Order $order, string $source, ReportContextData $context, string $notes = '' ): void {
	if ( ! function_exists( 'WC' ) ) {
		return;
	}

	// Callers may reach this before woocommerce_loaded fires, so the autoloader
	// may not yet be required. Defensively load it before touching any namespaced class.
	if ( ! class_exists( FraudProtectionController::class, false ) ) {
		$autoload = WC_FRAUD_PROTECTION_PLUGIN_DIR . '/vendor/autoload.php';
		if ( ! is_readable( $autoload ) ) {
			return;
		}
		require_once $autoload;
	}

	if ( ! class_exists( FraudProtectionController::class ) || ! FraudProtectionController::feature_is_enabled() ) {
		return;
	}

	$api_client = new \Automattic\WooCommerce\FraudProtection\ApiClient();

	$order_events_tracker = new \Automattic\WooCommerce\FraudProtection\OrderEventsTracker();
	$order_events_tracker->init( $api_client );
	$order_events_tracker->fraud_protection_report( $order, $source, $context, $notes );
}
