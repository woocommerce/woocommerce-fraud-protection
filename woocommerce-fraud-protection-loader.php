<?php
/**
 * MU-plugin loader for WooCommerce Fraud Protection.
 *
 * This file lives in the plugin directory and is symlinked into mu-plugins/
 * on WPCloud. It loads the main plugin file from the expected location.
 * The main file does not start this copy when a regular plugin copy is active.
 *
 * @package WooCommerce\FraudProtection
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Correct Fraud Protection asset URLs for managed installations.
 *
 * @param mixed $url The URL being filtered.
 * @return mixed Filtered URL.
 */
function woocommerce_fraud_protection_symlinked_plugins_url( $url ) {
	if ( ! is_string( $url ) ) {
		return $url;
	}

	return preg_replace(
		'#((?<!/)/[^/]+)*/wp-content/plugins/wordpress/plugins/woocommerce-fraud-protection/([^/]+)/?#',
		'/wp-content/mu-plugins/woocommerce-fraud-protection/',
		$url
	);
}

$woocommerce_fraud_protection_target = WPMU_PLUGIN_DIR . '/woocommerce-fraud-protection/woocommerce-fraud-protection.php';

if ( ! is_readable( $woocommerce_fraud_protection_target ) ) {
	// Symlink missing or chroot misconfigured. Bail out instead of fatalling the site.
	error_log( 'WooCommerce Fraud Protection: target plugin file is not readable at ' . $woocommerce_fraud_protection_target ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log, QITStandard.PHP.DebugCode.DebugFunctionFound -- Last-resort logging on broken rollout symlink, before the plugin's own logger is available.
	return;
}

require_once $woocommerce_fraud_protection_target;

// The main file does not start the MU-plugin copy when a kill switch applies or an active
// regular plugin copy takes precedence. Managed-installation behavior must then stay off.
if (
	! class_exists( '\Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer', false )
	|| ! \Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer::is_mu_plugin()
) {
	return;
}

add_filter( 'plugins_url', 'woocommerce_fraud_protection_symlinked_plugins_url', 0, 1 );

if ( ! defined( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL' ) ) {
	define( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL', true );
}

\Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer::register_managed_textdomain();
