<?php
/**
 * PayPalScriptCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;

defined( 'ABSPATH' ) || exit;

/**
 * Loads Fraud Protection browser scripts on PayPal payment surfaces.
 */
class PayPalScriptCompat {

	/**
	 * First PayPal Payments version that uses the styling option at runtime.
	 */
	private const PAYPAL_STYLING_SETTINGS_VERSION = '4.0.0';

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
	 * Register PayPal payment-surface hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_paypal_payments_single_product_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_cart_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_checkout_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_payorder_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_paypal_payments_minicart_button_render', array( $this, 'enqueue_paypal_script' ), 10, 0 );
		add_action( 'woocommerce_before_mini_cart', array( $this, 'enqueue_paypal_mini_cart_script_if_enabled' ), 10, 0 );
		add_filter( 'woocommerce_widget_cart_is_hidden', array( $this, 'enqueue_paypal_script_for_visible_mini_cart_widget' ), 20, 1 );
		add_action( 'woocommerce_blocks_enqueue_checkout_block_scripts_before', array( $this, 'enqueue_paypal_block_script_if_registered' ), 20, 0 );
		add_action( 'woocommerce_blocks_enqueue_cart_block_scripts_before', array( $this, 'enqueue_paypal_cart_block_scripts_if_registered' ), 20, 0 );
		add_action( 'woocommerce_checkout_before_order_review', array( $this, 'enqueue_paypal_script_if_smart_button_enqueued' ), 20, 0 );
		add_action( 'before_woocommerce_pay_form', array( $this, 'enqueue_paypal_script_if_smart_button_enqueued' ), 20, 0 );
		add_action( 'woocommerce_add_payment_method_form_bottom', array( $this, 'enqueue_paypal_script_for_add_payment_method' ), 20, 0 );
		add_action( 'woocommerce_subscriptions_change_payment_after_submit', array( $this, 'enqueue_paypal_script_if_add_payment_method_enqueued' ), 20, 0 );
	}

	/**
	 * Request the shared scripts and enqueue the PayPal fetch interceptor.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script(): void {
		if ( ! $this->blackbox_script_handler->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-paypal-express',
			plugins_url( 'assets/js/paypal-express.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Enqueue the PayPal interceptor for a frontend Checkout block.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_block_script_if_registered(): void {
		if ( $this->is_checkout_endpoint() || ! wp_script_is( 'ppcp-checkout-block', 'registered' ) ) {
			return;
		}

		$this->enqueue_paypal_script();
	}

	/**
	 * Enqueue the PayPal interceptor and Store API carrier for a frontend Cart block.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_cart_block_scripts_if_registered(): void {
		if ( $this->is_checkout_endpoint() || ! wp_script_is( 'ppcp-checkout-block', 'registered' ) ) {
			return;
		}

		$this->enqueue_paypal_script();

		if ( ! wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-blocks-checkout',
			plugins_url( 'assets/js/blocks-checkout.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init', 'wp-data', 'wc-blocks-checkout-events' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Enqueue the interceptor before a mini-cart can gain a PayPal button through AJAX fragments.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_mini_cart_script_if_enabled(): void {
		if (
			! $this->is_paypal_mini_cart_enabled()
			|| ! wp_script_is( 'ppcp-smart-button', 'registered' )
			|| ! wp_script_is( 'ppcp-smart-button', 'enqueued' )
		) {
			return;
		}

		$this->enqueue_paypal_script();
	}

	/**
	 * Prepare a visible classic cart widget for PayPal AJAX fragments.
	 *
	 * @internal
	 *
	 * @param mixed $hidden Whether WooCommerce will hide the cart widget.
	 * @return mixed The unchanged widget visibility decision.
	 */
	public function enqueue_paypal_script_for_visible_mini_cart_widget( $hidden ) {
		if ( false === $hidden ) {
			$this->enqueue_paypal_mini_cart_script_if_enabled();
		}

		return $hidden;
	}

	/**
	 * Enqueue the interceptor when its smart-button script can render later.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script_if_smart_button_enqueued(): void {
		if ( ! wp_script_is( 'ppcp-smart-button', 'registered' ) || ! wp_script_is( 'ppcp-smart-button', 'enqueued' ) ) {
			return;
		}

		$this->enqueue_paypal_script();
	}

	/**
	 * Enqueue the interceptor for the My Account add-payment-method form.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script_for_add_payment_method(): void {
		if ( ! is_add_payment_method_page() || ! is_wc_endpoint_url( 'add-payment-method' ) ) {
			return;
		}

		$this->enqueue_paypal_script_if_add_payment_method_enqueued();
	}

	/**
	 * Enqueue the interceptor when PayPal's add-payment-method script is active.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script_if_add_payment_method_enqueued(): void {
		if ( ! wp_script_is( 'ppcp-add-payment-method', 'registered' ) || ! wp_script_is( 'ppcp-add-payment-method', 'enqueued' ) ) {
			return;
		}

		$this->enqueue_paypal_script();
	}

	/**
	 * Check whether the current page is a Checkout endpoint fallback.
	 *
	 * @return bool
	 */
	private function is_checkout_endpoint(): bool {
		return is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'order-received' );
	}

	/**
	 * Check whether PayPal Payments enables its classic mini-cart button.
	 *
	 * @return bool
	 */
	private function is_paypal_mini_cart_enabled(): bool {
		$paypal_version = get_option( 'woocommerce-ppcp-version', '' );

		if (
			is_string( $paypal_version )
			&& '' !== $paypal_version
			&& version_compare( $paypal_version, self::PAYPAL_STYLING_SETTINGS_VERSION, '<' )
		) {
			return $this->is_legacy_paypal_mini_cart_enabled();
		}

		$styling = get_option( 'woocommerce-ppcp-data-styling', null );

		if ( null !== $styling ) {
			if ( ! is_array( $styling ) || ! array_key_exists( 'mini_cart', $styling ) ) {
				return false;
			}

			$mini_cart = $styling['mini_cart'];

			if ( is_object( $mini_cart ) ) {
				$mini_cart = get_object_vars( $mini_cart );
			}

			if ( is_array( $mini_cart ) && array_key_exists( 'enabled', $mini_cart ) ) {
				return true === $mini_cart['enabled'];
			}

			return false;
		}

		return $this->is_legacy_paypal_mini_cart_enabled();
	}

	/**
	 * Check the PayPal Payments mini-cart location in its legacy settings.
	 *
	 * @return bool
	 */
	private function is_legacy_paypal_mini_cart_enabled(): bool {
		$settings = get_option( 'woocommerce-ppcp-settings', array() );

		if ( ! is_array( $settings ) ) {
			return false;
		}

		$locations = $settings['smart_button_locations'] ?? array();

		return is_array( $locations ) && in_array( 'mini-cart', $locations, true );
	}
}
