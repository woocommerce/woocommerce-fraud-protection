<?php
/**
 * ShortcodeCheckoutProtector class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into the shortcode (classic) checkout.
 *
 * Handles the collect -> verify -> verdict flow for the classic AJAX checkout:
 * 1. Enqueues shortcode-checkout.js which gates form submission to acquire a
 *    Blackbox session ID and inject it as a hidden field.
 * 2. Hooks into `woocommerce_after_checkout_validation` (before order creation)
 *    to verify the session with Blackbox and block on BLOCK decisions.
 *
 * Fail-open: If verification fails for any reason, checkout proceeds.
 *
 * @internal
 */
class ShortcodeCheckoutProtector {

	/**
	 * Name of the form field carrying the Blackbox session ID.
	 */
	private const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

	/**
	 * Source identifier for verify requests from this protector.
	 */
	private const SOURCE = 'shortcode_checkout';

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
	 * Payment data resolver instance.
	 *
	 * @var PaymentDataResolver
	 */
	private PaymentDataResolver $payment_data_resolver;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier      $session_verifier       The session verifier instance.
	 * @param BlockedSessionNotice $blocked_session_notice The blocked session notice instance.
	 * @param PaymentDataResolver  $payment_data_resolver  The payment data resolver instance.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionNotice $blocked_session_notice,
		PaymentDataResolver $payment_data_resolver
	): void {
		$this->session_verifier       = $session_verifier;
		$this->blocked_session_notice = $blocked_session_notice;
		$this->payment_data_resolver  = $payment_data_resolver;
	}

	/**
	 * Register hooks for shortcode checkout fraud protection.
	 *
	 * Called from FraudProtectionController::on_init() when fraud protection is enabled.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'verify_and_block' ), 10, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_shortcode_checkout_script' ) );
	}

	/**
	 * Verify the session with Blackbox and block checkout if needed.
	 *
	 * Called during `woocommerce_after_checkout_validation`, which fires BEFORE
	 * order creation. Adding an error to $errors prevents order creation entirely,
	 * avoiding orphan orders.
	 *
	 * Fail-open: If session_id is empty, verify is still called (Blackbox/server
	 * decides). If verify throws or returns an error, checkout proceeds.
	 *
	 * @internal
	 *
	 * @param array     $posted_data The posted checkout form data.
	 * @param \WP_Error $errors      Validation errors object — add errors here to block checkout.
	 * @return void
	 */
	public function verify_and_block( array $posted_data, \WP_Error $errors ): void {
		$request_data = $this->build_request_data( $posted_data );

		$payment_data = null;
		try {
			$payment_data = $this->payment_data_resolver->resolve(
				$request_data['payment_method'] ?? '',
				$request_data['payment_data'] ?? array()
			);
		} catch ( \Throwable $e ) {
			// Fail-open: resolve is enrichment only, verify still runs.
			FraudProtectionController::log(
				'warning',
				'Payment data resolution failed: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
		}

		try {
			$decision = $this->session_verifier->verify_session(
				$this->get_blackbox_session_id(),
				0, // No order_id yet — pre-order hook. Cart data used instead.
				self::SOURCE,
				$request_data,
				$payment_data
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'verify_and_block failed, allowing checkout: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
			return;
		}

		if ( ApiClient::DECISION_BLOCK === $decision ) {
			$errors->add(
				'woocommerce_checkout_error',
				$this->blocked_session_notice->get_message_plaintext( 'purchase' )
			);
		}
	}

	/**
	 * Conditionally enqueue the shortcode-checkout.js script.
	 *
	 * Only enqueues on the shortcode checkout page (not blocks checkout,
	 * not the order-received page).
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_shortcode_checkout_script(): void {
		if ( ! is_checkout() || is_order_received_page() || has_block( 'woocommerce/checkout' ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-shortcode-checkout',
			WC_FRAUD_PROTECTION_PLUGIN_URL . 'assets/js/shortcode-checkout.js',
			array( 'wc-fraud-protection-blackbox-init', 'jquery' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}

	/**
	 * Get the Blackbox session ID from the POST data.
	 *
	 * @return string The session ID, or empty string if not found.
	 */
	private function get_blackbox_session_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
		$session_id = isset( $_POST[ self::SESSION_ID_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::SESSION_ID_FIELD ] ) ) : '';
		return $session_id;
	}

	/**
	 * Build structured request data from the flat posted checkout form data.
	 *
	 * Maps the flat POST keys into nested billing/shipping address arrays
	 * that match the format used by the blocks checkout for consistency.
	 *
	 * @param array $posted_data The posted checkout form data.
	 * @return array Structured request data.
	 */
	private function build_request_data( array $posted_data ): array {
		return array(
			'billing_address'  => $this->extract_address( $posted_data, 'billing_' ),
			'shipping_address' => $this->extract_address( $posted_data, 'shipping_' ),
			'payment_method'   => $posted_data['payment_method'] ?? '',
			'payment_data'     => $this->extract_payment_data(),
		);
	}

	/**
	 * Extract an address array from flat posted data by prefix.
	 *
	 * @param array  $posted_data The posted checkout form data.
	 * @param string $prefix      The address prefix ('billing_' or 'shipping_').
	 * @return array Address data with prefix stripped from keys.
	 */
	private function extract_address( array $posted_data, string $prefix ): array {
		$address = array();
		$length  = strlen( $prefix );

		foreach ( $posted_data as $key => $value ) {
			if ( 0 === strpos( $key, $prefix ) && is_string( $value ) ) {
				$address[ substr( $key, $length ) ] = $value;
			}
		}

		return $address;
	}

	/**
	 * Extract gateway-specific payment data from $_POST.
	 *
	 * Excludes known non-payment prefixes and exact keys to isolate
	 * gateway-specific fields. Returns a flat key-value map — the same
	 * format compat layers receive from the blocks checkout.
	 *
	 * @return array Flat key-value map of payment-related POST fields.
	 */
	private function extract_payment_data(): array {
		$excluded_prefixes = array(
			'billing_',
			'shipping_',
			'order_',
			'account_',
			'woocommerce',
			'_wp',
			'wc_fraud_protection_',
			'wc_order_attribution_',
		);

		// WooCommerce core checkout form fields that are not gateway-specific.
		$excluded_keys = array(
			'terms',
			'terms-field',
			'ship_to_different_address',
		);

		$payment_data = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce checkout.
		foreach ( $_POST as $key => $value ) {
			if ( in_array( $key, $excluded_keys, true ) ) {
				continue;
			}

			$skip = false;
			foreach ( $excluded_prefixes as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
					$skip = true;
					break;
				}
			}

			if ( ! $skip ) {
				$payment_data[ sanitize_text_field( $key ) ] = wc_clean( wp_unslash( $value ) );
			}
		}

		return $payment_data;
	}
}
