<?php
/**
 * Plugin Name: WooCommerce Fraud Protection
 * Description: A plugin to protect WooCommerce from fraud.
 * Version: 1.0.0
 * Author: Automattic
 *
 * @package WooCommerce\FraudProtection
 */

defined( 'ABSPATH' ) || exit;

define( 'WC_FRAUD_PROTECTION_VERSION', '1.0.0' );
define( 'WC_FRAUD_PROTECTION_PLUGIN_DIR', __DIR__ );
define( 'WC_FRAUD_PROTECTION_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Require class files (no autoloader).
// Order matters: typed properties require dependencies to be loaded first.
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/SessionClearanceManager.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/Address.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/CartItem.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/OrderData.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/SessionInfo.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/CustomerData.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/SessionDataCollector.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/ApiClient.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/DecisionHandler.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/CartEventTracker.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/CheckoutEventTracker.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/PaymentMethodEventTracker.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/BlackboxScriptHandler.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/BlockedSessionNotice.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/CardPaymentMethodData.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/PaymentMethodData.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/PaymentDataResolver.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Compat/StripePaymentDataCompat.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Compat/SquarePaymentDataCompat.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/SessionVerifier.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/BlocksCheckoutProtector.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/ShortcodeCheckoutProtector.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/SessionBlockingHandler.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/FraudProtectionController.php';

// Bootstrap after WooCommerce loads (MU-plugins load before regular plugins).
add_action(
	'woocommerce_loaded',
	function () {
		// Core dependencies.
		$session_manager = new \Automattic\WooCommerce\Internal\FraudProtection\SessionClearanceManager();

		$api_client = new \Automattic\WooCommerce\Internal\FraudProtection\ApiClient();

		$decision_handler = new \Automattic\WooCommerce\Internal\FraudProtection\DecisionHandler();
		$decision_handler->init( $session_manager );

		$session_data_collector = new \Automattic\WooCommerce\Internal\FraudProtection\SessionDataCollector();
		$session_data_collector->init( $session_manager );

		// Event trackers.
		$cart_event_tracker = new \Automattic\WooCommerce\Internal\FraudProtection\CartEventTracker();
		$cart_event_tracker->init( $session_data_collector );

		$checkout_event_tracker = new \Automattic\WooCommerce\Internal\FraudProtection\CheckoutEventTracker();
		$checkout_event_tracker->init( $session_data_collector );

		$payment_method_event_tracker = new \Automattic\WooCommerce\Internal\FraudProtection\PaymentMethodEventTracker();
		$payment_method_event_tracker->init( $session_data_collector );

		// Notice and script handlers.
		$blocked_notice = new \Automattic\WooCommerce\Internal\FraudProtection\BlockedSessionNotice();
		$blocked_notice->init( $session_manager );

		$blackbox_handler = new \Automattic\WooCommerce\Internal\FraudProtection\BlackboxScriptHandler();
		$blackbox_handler->init( $session_manager );

		// Session blocking handler.
		$session_blocking_handler = new \Automattic\WooCommerce\Internal\FraudProtection\SessionBlockingHandler();
		$session_blocking_handler->init( $session_manager, $blocked_notice );

		$session_verifier = new \Automattic\WooCommerce\Internal\FraudProtection\SessionVerifier();
		$session_verifier->init( $session_data_collector, $api_client, $decision_handler );

		$payment_data_resolver = new \Automattic\WooCommerce\Internal\FraudProtection\PaymentDataResolver();

		$stripe_compat = new \Automattic\WooCommerce\Internal\FraudProtection\Compat\StripePaymentDataCompat();
		$stripe_compat->register();

		$square_compat = new \Automattic\WooCommerce\Internal\FraudProtection\Compat\SquarePaymentDataCompat();
		$square_compat->register();

		$blocks_checkout_protector = new \Automattic\WooCommerce\Internal\FraudProtection\BlocksCheckoutProtector();
		$blocks_checkout_protector->init( $session_verifier, $blocked_notice, $payment_data_resolver );

		$shortcode_checkout_protector = new \Automattic\WooCommerce\Internal\FraudProtection\ShortcodeCheckoutProtector();
		$shortcode_checkout_protector->init( $session_verifier, $blocked_notice, $payment_data_resolver );

		// Main controller.
		$controller = new \Automattic\WooCommerce\Internal\FraudProtection\FraudProtectionController();
		$controller->init(
			$blocked_notice,
			$blackbox_handler,
			$cart_event_tracker,
			$checkout_event_tracker,
			$payment_method_event_tracker,
			$session_blocking_handler,
			$blocks_checkout_protector,
			$shortcode_checkout_protector
		);
		$controller->register();
	}
);
