<?php
/**
 * Plugin Name: WooCommerce Fraud Protection
 * Description: A plugin to protect WooCommerce from fraud.
 * Version: 0.1.4
 * Author: Automattic
 * Requires Plugins: woocommerce
 * WC requires at least: 9.5.0
 *
 * @package WooCommerce\FraudProtection
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

defined( 'ABSPATH' ) || exit;

// Kill-switch: define WC_FRAUD_PROTECTION_DISABLED as true to disable.
if ( defined( 'WC_FRAUD_PROTECTION_DISABLED' ) && WC_FRAUD_PROTECTION_DISABLED ) {
	return;
}

require_once __DIR__ . '/src/Internal/FraudProtectionPlugin/PluginInitializer.php';
PluginInitializer::run( __FILE__ );
