<?php
/**
 * MU-plugin loader for WooCommerce Fraud Protection.
 *
 * This file lives in the plugin directory and is symlinked into mu-plugins/
 * on WPCloud. It loads the main plugin file from the expected location.
 *
 * @package WooCommerce\FraudProtection
 */

declare( strict_types = 1 );

/**
 * Filter plugins_url for when __FILE__ is outside of WP_CONTENT_DIR.
 *
 * @param string $url The complete URL to the plugins directory including scheme and path.
 * @return string Filtered URL.
 */
function woocommerce_fraud_protection_symlinked_plugins_url( $url ) {
	return preg_replace(
		'#((?<!/)/[^/]+)*/wp-content/plugins/wordpress/plugins/woocommerce-fraud-protection/([^/]+)/?#',
		'/wp-content/mu-plugins/woocommerce-fraud-protection/',
		$url
	);
}
add_filter( 'plugins_url', 'woocommerce_fraud_protection_symlinked_plugins_url', 0, 1 );

$woocommerce_fraud_protection_target = WPMU_PLUGIN_DIR . '/woocommerce-fraud-protection/woocommerce-fraud-protection.php';

if ( ! is_readable( $woocommerce_fraud_protection_target ) ) {
	// Symlink missing or chroot misconfigured. Bail out instead of fatalling the site.
	error_log( 'WooCommerce Fraud Protection: target plugin file is not readable at ' . $woocommerce_fraud_protection_target ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Last-resort logging on broken rollout symlink, before the plugin's own logger is available.
	return;
}

require_once $woocommerce_fraud_protection_target;
