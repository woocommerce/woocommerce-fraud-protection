<?php
/**
 * Plugin Name: Fraud Protection example change-payment flow
 * Description: A stand-in for the WooCommerce Subscriptions change-payment-method flow with a native Fraud Protection integration. Reference code only.
 * Version: 0.1.0
 * Requires Plugins: woocommerce
 * Text Domain: fp-example-subscriptions
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-fp-example-subscriptions-plugin.php';

add_action( 'plugins_loaded', array( 'FP_Example_Subscriptions_Plugin', 'load' ) );
