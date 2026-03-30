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

require_once WPMU_PLUGIN_DIR . '/woocommerce-fraud-protection/woocommerce-fraud-protection.php';
