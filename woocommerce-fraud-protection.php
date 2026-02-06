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
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/SessionClearanceManager.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/BlackboxScriptHandler.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/BlockedSessionNotice.php';
require_once WC_FRAUD_PROTECTION_PLUGIN_DIR . '/src/Internal/FraudProtectionController.php';

// Bootstrap after WooCommerce loads (MU-plugins load before regular plugins).
add_action( 'woocommerce_loaded', function () {
	$session_manager = new \Automattic\WooCommerce\Internal\FraudProtection\SessionClearanceManager();

	$blocked_notice = new \Automattic\WooCommerce\Internal\FraudProtection\BlockedSessionNotice();
	$blocked_notice->init( $session_manager );

	$blackbox_handler = new \Automattic\WooCommerce\Internal\FraudProtection\BlackboxScriptHandler();

	$controller = new \Automattic\WooCommerce\Internal\FraudProtection\FraudProtectionController();
	$controller->init( $blocked_notice, $blackbox_handler );
	$controller->register();
} );
