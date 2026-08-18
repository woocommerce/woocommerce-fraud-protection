<?php
/**
 * Plugin Name: WooCommerce Fraud Protection
 * Description: A plugin to protect WooCommerce from fraud.
 * Version: 0.1.8
 * Author: Automattic
 * Requires Plugins: woocommerce
 * Requires PHP: 8.1
 * WC requires at least: 9.5.0
 *
 * @package WooCommerce\FraudProtection
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

defined( 'ABSPATH' ) || exit;

// Kill-switch for unsupported PHP version.
// Required because the 'Requires PHP' header is not enforced for MU-plugins.
if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	return;
}

// Kill-switch: define WC_FRAUD_PROTECTION_DISABLED as true to disable.
if ( defined( 'WC_FRAUD_PROTECTION_DISABLED' ) && WC_FRAUD_PROTECTION_DISABLED ) {
	return;
}

require_once __DIR__ . '/src/Internal/FraudProtectionPlugin/PluginInitializer.php';
PluginInitializer::run( __FILE__ );
