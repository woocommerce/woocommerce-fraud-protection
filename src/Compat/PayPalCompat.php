<?php
/**
 * PayPalCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into PayPal Payments express checkout flows.
 *
 * PayPal express checkout (product page, cart, mini-cart) bypasses the standard
 * WC checkout pipeline. This class hooks into PayPal's CreateOrder AJAX endpoint
 * to verify sessions before PayPal order creation.
 *
 * Payment data resolution (card details, wallet/payer identity) is handled
 * separately by PayPalPaymentDataCompat.
 *
 * The JS fetch interceptor resets Blackbox after the CreateOrder fetch returns,
 * so subsequent payment attempts (retry, different method) get a fresh session.
 *
 * @internal
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
	 * @internal
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this, 'verify_and_block_create_order' ) );
		add_filter( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', array( $this, 'should_enqueue_blackbox' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_paypal_script' ), 20 );
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
