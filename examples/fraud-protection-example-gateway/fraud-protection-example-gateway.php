<?php
/**
 * Plugin Name: Fraud Protection example gateway
 * Description: A barebones payment gateway that integrates WooCommerce Fraud Protection natively. Reference code only.
 * Version: 0.1.0
 * Requires Plugins: woocommerce
 * Text Domain: fp-example-gateway
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'FP_EXAMPLE_GATEWAY_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-fp-example-gateway-plugin.php';

add_action( 'plugins_loaded', array( 'FP_Example_Gateway_Plugin', 'load' ) );
