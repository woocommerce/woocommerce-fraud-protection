<?php
/**
 * PayPalCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlackboxScriptHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into PayPal Payments express checkout flows.
 *
 * PayPal express checkout (product page, cart, mini-cart) bypasses the standard
 * WC checkout pipeline. This class hooks into PayPal's CreateOrder AJAX endpoint
 * to verify sessions before PayPal order creation.
 *
 * The JS fetch interceptor resets Blackbox after the CreateOrder fetch returns,
 * so subsequent payment attempts (retry, different method) get a fresh session.
 */
class PayPalCompat {

	/**
	 * Source identifier for verify requests from PayPal express flows.
	 */
	private const ORDER_CREATION_SOURCE = 'paypal_express_order_creation';

	/**
	 * Gateway ID prefix shared by all PayPal Payments gateways.
	 */
	private const PAYPAL_GATEWAY_PREFIX = 'ppcp-';

	/**
	 * WC session key for the Blackbox session ID verified during ppc-create-order.
	 */
	private const VERIFIED_SESSION_ID_KEY = '_fraud_protection_paypal_verified_session_id';

	/**
	 * Session verifier instance.
	 *
	 * @var SessionVerifier
	 */
	private SessionVerifier $session_verifier;

	/**
	 * Blocked session notice instance.
	 *
	 * @var BlockedSessionNotice
	 */
	private BlockedSessionNotice $blocked_session_notice;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier      $session_verifier       The session verifier instance.
	 * @param BlockedSessionNotice $blocked_session_notice The blocked session notice instance.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionNotice $blocked_session_notice
	): void {
		$this->session_verifier       = $session_verifier;
		$this->blocked_session_notice = $blocked_session_notice;
	}

	/**
	 * Register hooks for PayPal express fraud protection.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this, 'verify_and_block_create_order' ) );
		add_filter( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', array( $this, 'should_enqueue_blackbox' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_paypal_script' ), 20 );
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this, 'skip_default_verify_for_paypal_express' ), 10, 4 );
	}

	/**
	 * Verify the session and block the PayPal CreateOrder request if needed.
	 *
	 * Called during `woocommerce_paypal_payments_create_order_request_started`,
	 * which fires after nonce validation but before PayPal order creation.
	 *
	 * On BLOCK, sends a JSON error response and terminates execution via
	 * wp_send_json_error(). On ALLOW, returns normally and the PayPal flow
	 * continues.
	 *
	 * @internal
	 *
	 * @param array $data The CreateOrder request data from PayPal Payments.
	 * @return void
	 */
	public function verify_and_block_create_order( array $data ): void {
		$session_id = sanitize_text_field( $data[ BlackboxScriptHandler::SESSION_ID_FIELD ] ?? '' );

		$decision = $this->session_verifier->verify_session( $session_id, self::ORDER_CREATION_SOURCE, 0, $data );

		// Store the verified session ID so standard protectors can skip
		// redundant verification for the same Blackbox session (e.g. when
		// BlocksCheckoutProtector fires after ppc-create-order for card flows).
		// Stored regardless of decision: Blackbox sessions are single-use, so
		// re-verifying the same ID would fail rather than produce a fresh verdict.
		if ( '' !== $session_id && function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::VERIFIED_SESSION_ID_KEY, $session_id );
		}

		if ( ApiClient::DECISION_BLOCK === $decision ) {
			wp_send_json_error(
				array( 'message' => $this->blocked_session_notice->get_message_plaintext( 'purchase' ) ),
				403
			);
		}
	}

	/**
	 * Filter whether Blackbox scripts should be enqueued on the current page.
	 *
	 * Extends the default enqueue logic (checkout, pay-for-order, add-payment-method)
	 * to also load on pages where PayPal express buttons can trigger checkout:
	 * product pages, cart pages, and any page when mini-cart buttons are enabled
	 * (the mini-cart renders in the header/template, so buttons appear on every page).
	 *
	 * @internal
	 *
	 * @param bool $should Whether scripts are already set to be enqueued.
	 * @return bool
	 */
	public function should_enqueue_blackbox( bool $should ): bool {
		if ( $should ) {
			return true;
		}

		if ( ! $this->is_paypal_available() ) {
			return false;
		}

		return is_product()
			|| is_cart()
			|| has_block( 'woocommerce/cart' )
			|| $this->is_paypal_mini_cart_enabled();
	}

	/**
	 * Enqueue the PayPal express fetch interceptor script.
	 *
	 * Only enqueues when Blackbox init script is already loaded (which means
	 * Blackbox is configured and ready on the current page).
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_paypal_script(): void {
		if ( ! wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-paypal-express',
			WC_FRAUD_PROTECTION_PLUGIN_URL . 'assets/js/paypal-express.js',
			array( 'wc-fraud-protection-blackbox-init' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Skip redundant verification for PayPal flows handled by PayPalCompat.
	 *
	 * PayPal smart-button flows go through ppc-create-order where PayPalCompat
	 * verifies the session. Standard protectors should skip when:
	 *
	 * 1. The payment method is a PayPal gateway (ppcp-* prefix), AND
	 * 2. Any of:
	 *    a. The current request IS ppc-create-order — PayPal's
	 *       CreateOrderEndpoint fires woocommerce_checkout_process during
	 *       form validation before our verify action runs, so the shortcode
	 *       protector should defer to PayPalCompat.
	 *    b. The Blackbox session ID matches one already verified during
	 *       ppc-create-order — the standard protector captured the same
	 *       session ID before ppc-create-order ran (e.g. card flows on
	 *       blocks checkout where blocks-checkout.js acquires the ID first).
	 *    c. An approved PayPal order exists in the WC session — the request
	 *       is completing an express flow already verified at CreateOrder
	 *       (ppc-approve-order stored the order after approval).
	 *
	 * Flows that do NOT go through ppc-create-order (Blocks "Place Order"
	 * with ppcp-gateway server-side redirect, APM gateways) are NOT skipped.
	 *
	 * @internal
	 *
	 * @param bool   $skip         Whether to skip verification.
	 * @param string $source       Source identifier.
	 * @param array  $request_data Request data with payment_method, payment_data, etc.
	 * @param string $session_id   The Blackbox session ID being verified.
	 * @return bool
	 */
	public function skip_default_verify_for_paypal_express( bool $skip, string $source, array $request_data, string $session_id ): bool {
		if ( $skip ) {
			return true;
		}

		// Don't skip PayPalCompat's own verification sources.
		if ( self::ORDER_CREATION_SOURCE === $source ) {
			return false;
		}

		$payment_method = (string) ( $request_data['payment_method'] ?? '' );

		// Not a PayPal gateway — nothing for this filter to do.
		if ( ! $this->is_paypal_gateway( $payment_method ) ) {
			return false;
		}

		// During ppc-create-order: early validation fires woocommerce_checkout_process
		// before PayPalCompat verifies. Skip — PayPalCompat handles it next.
		if ( $this->is_paypal_create_order_request() ) {
			return true;
		}

		// Same Blackbox session already verified during ppc-create-order in this
		// payment flow (e.g. card fields on blocks checkout where blocks-checkout.js
		// captured the session ID before ppc-create-order ran).
		if ( $this->was_session_verified_at_create_order( $session_id ) ) {
			return true;
		}

		// After express approval: PayPal order in session means ppc-create-order
		// already verified (Blackbox was reset since, so session IDs won't match).
		if ( $this->has_paypal_order_in_session() ) {
			return true;
		}

		// All other ppcp-* flows (Blocks "Place Order" with ppcp-gateway, APMs): don't skip.
		return false;
	}

	/**
	 * Check if an approved PayPal order exists in the WC session.
	 *
	 * PayPal Payments stores the approved order in the 'ppcp' session key
	 * after ppc-create-order and ppc-approve-order. Its presence indicates
	 * the checkout is completing a flow where ppc-create-order already ran.
	 *
	 * @return bool
	 */
	private function has_paypal_order_in_session(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		$ppcp_session = WC()->session->get( 'ppcp' );

		return is_array( $ppcp_session ) && ! empty( $ppcp_session['order'] );
	}

	/**
	 * Check if a Blackbox session ID was already verified during ppc-create-order.
	 *
	 * Smart-button flows (cards, wallets, express) call ppc-create-order where
	 * PayPalCompat verifies the session. For flows where the standard protector
	 * captures the same Blackbox session ID before ppc-create-order runs (e.g.
	 * blocks-checkout.js acquires the ID, then PayPal's createOrder callback
	 * fires ppc-create-order), the session IDs match and we can skip.
	 *
	 * @param string $session_id The session ID from the current verify call.
	 * @return bool
	 */
	private function was_session_verified_at_create_order( string $session_id ): bool {
		if ( '' === $session_id || ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}

		return WC()->session->get( self::VERIFIED_SESSION_ID_KEY, '' ) === $session_id;
	}

	/**
	 * Check if the current request is a PayPal ppc-create-order AJAX request.
	 *
	 * During ppc-create-order, PayPal's CreateOrderEndpoint may fire
	 * woocommerce_checkout_process (via validate_form()) before our
	 * verify_and_block_create_order action fires. The standard protector
	 * should skip because PayPalCompat will verify later in the same request.
	 *
	 * @return bool
	 */
	private function is_paypal_create_order_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only checking the endpoint name, not processing data.
		return isset( $_GET['wc-ajax'] ) && 'ppc-create-order' === $_GET['wc-ajax'];
	}

	/**
	 * Check if a gateway ID belongs to PayPal Payments.
	 *
	 * @param string $gateway_id The gateway ID to check.
	 * @return bool
	 */
	private function is_paypal_gateway( string $gateway_id ): bool {
		return 0 === strpos( $gateway_id, self::PAYPAL_GATEWAY_PREFIX );
	}

	/**
	 * Check if any PayPal Payments gateway is available.
	 *
	 * @return bool
	 */
	private function is_paypal_available(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
			return false;
		}

		$gateways = WC()->payment_gateways()->get_available_payment_gateways();

		foreach ( array_keys( $gateways ) as $id ) {
			if ( $this->is_paypal_gateway( $id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if PayPal Payments has mini-cart smart buttons enabled.
	 *
	 * When enabled, PayPal renders express buttons inside the mini-cart widget
	 * which appears on every frontend page (header/template part). Reads the
	 * same `smart_button_locations` setting that PayPal uses to decide where
	 * to load its scripts.
	 *
	 * @return bool
	 */
	private function is_paypal_mini_cart_enabled(): bool {
		$ppcp_settings = get_option( 'woocommerce-ppcp-settings', array() );

		if ( ! is_array( $ppcp_settings ) ) {
			return false;
		}

		$locations = $ppcp_settings['smart_button_locations'] ?? array();

		return is_array( $locations ) && in_array( 'mini-cart', $locations, true );
	}
}
