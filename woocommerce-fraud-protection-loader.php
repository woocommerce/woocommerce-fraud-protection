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

require_once WPMU_PLUGIN_DIR . '/woocommerce-fraud-protection/woocommerce-fraud-protection.php';
