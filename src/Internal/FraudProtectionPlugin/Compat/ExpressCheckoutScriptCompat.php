<?php
/**
 * ExpressCheckoutScriptCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Loads Fraud Protection browser scripts where WooPayments or Stripe render express payment buttons.
 *
 * The express buttons place the order through the Store API checkout route, where
 * BlocksCheckoutProtector reads the session ID that express-checkout.js adds.
 */
class ExpressCheckoutScriptCompat {

	/**
	 * Script handles that render express buttons on product, cart, checkout, and pay-for-order pages.
	 */
	private const CLASSIC_HANDLES = array(
		'WCPAY_EXPRESS_CHECKOUT_ECE',
		'wc_stripe_express_checkout',
	);

	/**
	 * Script handles that render express buttons in the Cart and Checkout blocks.
	 */
	private const BLOCKS_HANDLES = array(
		'WCPAY_BLOCKS_CHECKOUT',
		'wc-stripe-blocks-integration',
	);

	/**
	 * Shared script handler.
	 *
	 * @var BlackboxScriptHandler
	 */
	private BlackboxScriptHandler $blackbox_script_handler;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param BlackboxScriptHandler $blackbox_script_handler The shared script handler.
	 */
	final public function init( BlackboxScriptHandler $blackbox_script_handler ): void {
		$this->blackbox_script_handler = $blackbox_script_handler;
	}

	/**
	 * Register express payment surface hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_express_checkout_script_if_buttons_enqueued' ), PHP_INT_MAX, 0 );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this, 'enqueue_express_checkout_block_script_if_registered' ), 20, 0 );
		add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this, 'enqueue_express_checkout_block_script_if_registered' ), 20, 0 );
	}

	/**
	 * Enqueue the express checkout script when a gateway enqueued its express button script.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_express_checkout_script_if_buttons_enqueued(): void {
		foreach ( self::CLASSIC_HANDLES as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) && wp_script_is( $handle, 'enqueued' ) ) {
				$this->enqueue_express_checkout_script();
				return;
			}
		}
	}

	/**
	 * Enqueue the express checkout script for a frontend Cart or Checkout block.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_express_checkout_block_script_if_registered(): void {
		// WooCommerce renders these endpoints with classic views, covered by the classic handles.
		if ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		foreach ( self::BLOCKS_HANDLES as $handle ) {
			if ( wp_script_is( $handle, 'registered' ) ) {
				$this->enqueue_express_checkout_script();
				return;
			}
		}
	}

	/**
	 * Request the shared scripts and enqueue the express checkout script.
	 *
	 * @return void
	 */
	private function enqueue_express_checkout_script(): void {
		if ( ! $this->blackbox_script_handler->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-express-checkout',
			plugins_url( 'assets/js/express-checkout.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init', 'wp-hooks' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}
}
