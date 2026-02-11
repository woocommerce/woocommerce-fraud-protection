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
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/BlackboxScriptHandler.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/BlockedSessionNotice.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/CardPaymentMethodData.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Schemas/PaymentMethodData.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/PaymentDataResolver.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Compat/StripePaymentDataCompat.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/Compat/SquarePaymentDataCompat.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/SessionVerifier.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/BlocksCheckoutProtector.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtection/FraudProtectionController.php';

// Bootstrap after WooCommerce loads (MU-plugins load before regular plugins).
add_action(
	'woocommerce_loaded',
	function () {
		$session_manager = new \Automattic\WooCommerce\Internal\FraudProtection\SessionClearanceManager();

		$data_collector = new \Automattic\WooCommerce\Internal\FraudProtection\SessionDataCollector();
		$data_collector->init( $session_manager );

		$api_client = new \Automattic\WooCommerce\Internal\FraudProtection\ApiClient();

		$decision_handler = new \Automattic\WooCommerce\Internal\FraudProtection\DecisionHandler();
		$decision_handler->init( $session_manager );

		$blocked_notice = new \Automattic\WooCommerce\Internal\FraudProtection\BlockedSessionNotice();
		$blocked_notice->init( $session_manager );

		$blackbox_handler = new \Automattic\WooCommerce\Internal\FraudProtection\BlackboxScriptHandler();

		$session_verifier = new \Automattic\WooCommerce\Internal\FraudProtection\SessionVerifier();
		$session_verifier->init( $data_collector, $api_client, $decision_handler );

		$payment_data_resolver = new \Automattic\WooCommerce\Internal\FraudProtection\PaymentDataResolver();

		$stripe_compat = new \Automattic\WooCommerce\Internal\FraudProtection\Compat\StripePaymentDataCompat();
		$stripe_compat->register();

		$square_compat = new \Automattic\WooCommerce\Internal\FraudProtection\Compat\SquarePaymentDataCompat();
		$square_compat->register();

		$blocks_checkout_protector = new \Automattic\WooCommerce\Internal\FraudProtection\BlocksCheckoutProtector();
		$blocks_checkout_protector->init( $session_verifier, $blocked_notice, $payment_data_resolver );

		$controller = new \Automattic\WooCommerce\Internal\FraudProtection\FraudProtectionController();
		$controller->init( $blocked_notice, $blackbox_handler, $blocks_checkout_protector );
		$controller->register();
	}
);
