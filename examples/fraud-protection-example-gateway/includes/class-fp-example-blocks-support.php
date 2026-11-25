<?php
/**
 * FP_Example_Blocks_Support class file.
 *
 * @package WooCommerce\FraudProtection\Examples
 */

declare( strict_types=1 );

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the example gateway available in the Cart and Checkout blocks.
 *
 * This is gateway code with nothing specific to Fraud Protection. The Checkout block places
 * the order through a Store API checkout request, which Fraud Protection's built-in protection
 * verifies by itself, with the session ID that the plugin's own Checkout block script adds. The
 * payment fields reach the gateway's payment data resolver as the request's payment data, as
 * they do on the classic checkout.
 *
 * The block script (assets/blocks.js) also posts the provider order's ID when the shopper comes
 * from the "Pay with provider" button, so the checkout reuses that request's decision, and adds
 * the two cart buttons to the blocks' express payment area when the gateway's Fraud Protection
 * integration loaded their scripts.
 */
final class FP_Example_Blocks_Support extends AbstractPaymentMethodType {

	/**
	 * Script handle of the block payment method.
	 */
	public const SCRIPT = 'fp-example-blocks';

	/**
	 * Payment method name: the gateway ID.
	 *
	 * @var string
	 */
	protected $name = WC_Gateway_FP_Example::ID;

	/**
	 * Load the gateway settings.
	 */
	public function initialize() {
		$settings       = get_option( 'woocommerce_' . WC_Gateway_FP_Example::ID . '_settings', array() );
		$this->settings = is_array( $settings ) ? $settings : array();
	}

	/**
	 * Whether the payment method is offered: when the gateway is enabled.
	 *
	 * @return bool
	 */
	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'no' );
	}

	/**
	 * Register the block script and return it as the script the blocks load for this payment method.
	 *
	 * The button scripts are registered by the Fraud Protection integration, only when Fraud
	 * Protection is available. When they are, the block script depends on them, so they load
	 * first with their settings, and the block script adds their buttons.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$dependencies = array( 'wc-blocks-registry', 'wc-settings', 'wp-element' );
		foreach ( array( FP_Example_Fraud_Protection::PROVIDER_SCRIPT, FP_Example_Fraud_Protection::EXPRESS_SCRIPT ) as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				$dependencies[] = $handle;
			}
		}

		wp_register_script( self::SCRIPT, plugins_url( 'assets/blocks.js', FP_EXAMPLE_GATEWAY_FILE ), $dependencies, '0.1.0', true );

		return array( self::SCRIPT );
	}

	/**
	 * The data the block script reads with `wc.wcSettings.getPaymentMethodData( 'fp_example' )`.
	 *
	 * @return array
	 */
	public function get_payment_method_data() {
		return array(
			'title'              => $this->get_setting( 'title', __( 'Example card payment', 'fp-example-gateway' ) ),
			'supports'           => $this->get_supported_features(),
			'labels'             => array(
				'cardBrand' => __( 'Card brand', 'fp-example-gateway' ),
				'cardLast4' => __( 'Last four digits', 'fp-example-gateway' ),
				'provider'  => __( 'Pay with provider (example)', 'fp-example-gateway' ),
				'express'   => __( 'Express pay (example)', 'fp-example-gateway' ),
			),
			'providerOrderField' => FP_Example_Fraud_Protection::PROVIDER_ORDER_FIELD,
		);
	}
}
