<?php
/**
 * FP_Example_Gateway_Plugin class file.
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

/**
 * Loads the gateway and its Fraud Protection integration once WooCommerce is available.
 */
final class FP_Example_Gateway_Plugin {

	/**
	 * Load the gateway and register its hooks.
	 *
	 * Runs on `plugins_loaded`, after WooCommerce fired `woocommerce_loaded` and Fraud
	 * Protection made its classes available.
	 */
	public static function load(): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		require_once __DIR__ . '/class-wc-gateway-fp-example.php';
		require_once __DIR__ . '/class-fp-example-fraud-protection.php';

		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'register_gateway' ) );
		add_action( 'woocommerce_blocks_payment_method_type_registration', array( __CLASS__, 'register_blocks_support' ) );
		( new FP_Example_Fraud_Protection() )->register();
	}

	/**
	 * Make the gateway available in the Cart and Checkout blocks.
	 *
	 * @param Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $registry The blocks' payment method registry.
	 */
	public static function register_blocks_support( $registry ): void {
		require_once __DIR__ . '/class-fp-example-blocks-support.php';

		$registry->register( new FP_Example_Blocks_Support() );
	}

	/**
	 * Add the gateway to WooCommerce's list.
	 *
	 * @param mixed $gateways Registered gateway class names and instances.
	 * @return array
	 */
	public static function register_gateway( $gateways ): array {
		$gateways   = is_array( $gateways ) ? $gateways : array();
		$gateways[] = WC_Gateway_FP_Example::class;

		return $gateways;
	}
}
