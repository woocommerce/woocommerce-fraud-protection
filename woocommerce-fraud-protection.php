<?php
/**
 * Plugin Name: WooCommerce Fraud Protection
 * Description: A plugin to protect WooCommerce from fraud.
 * Version: 0.2.9
 * Author: Automattic
 * Requires Plugins: woocommerce
 * Requires PHP: 8.1
 * WC requires at least: 9.8.0
 * Text Domain: woocommerce-fraud-protection
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

// Another copy of the plugin is already running. This happens when WordPress loads
// the regular plugin to activate it while the MU-plugin copy is running.
if ( defined( 'WC_FRAUD_PROTECTION_VERSION' ) ) {
	return;
}

// A copy loaded before `muplugins_loaded` is the MU-plugin copy, unless it is a network-activated plugin.
// The MU-plugin copy yields to an active regular copy. This check must run before any plugin class
// is declared, because the regular copy declares the same classes from its own path.
$woocommerce_fraud_protection_is_mu_plugin = false;
if ( ! did_action( 'muplugins_loaded' ) ) {
	$woocommerce_fraud_protection_network_copies = array();
	$woocommerce_fraud_protection_site_copies    = array();
	if ( is_multisite() ) {
		foreach ( wp_get_active_network_plugins() as $woocommerce_fraud_protection_active_plugin ) {
			if ( basename( __FILE__ ) === basename( $woocommerce_fraud_protection_active_plugin ) ) {
				$woocommerce_fraud_protection_network_copies[] = realpath( $woocommerce_fraud_protection_active_plugin );
			}
		}
	}
	foreach ( wp_get_active_and_valid_plugins() as $woocommerce_fraud_protection_active_plugin ) {
		if ( basename( __FILE__ ) === basename( $woocommerce_fraud_protection_active_plugin ) ) {
			$woocommerce_fraud_protection_site_copies[] = realpath( $woocommerce_fraud_protection_active_plugin );
		}
	}

	if ( ! in_array( __FILE__, $woocommerce_fraud_protection_network_copies, true ) ) {
		// A regular copy that resolves to this same file is not another copy: WordPress will not include it again.
		$woocommerce_fraud_protection_other_copies = array_diff( array_merge( $woocommerce_fraud_protection_network_copies, $woocommerce_fraud_protection_site_copies ), array( __FILE__ ) );
		if ( array() !== $woocommerce_fraud_protection_other_copies ) {
			return;
		}

		$woocommerce_fraud_protection_is_mu_plugin = true;
	}
}

require_once __DIR__ . '/src/Internal/FraudProtectionPlugin/PluginInitializer.php';
PluginInitializer::run( __FILE__, $woocommerce_fraud_protection_is_mu_plugin );
