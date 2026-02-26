<?php
/**
 * PayForOrderProtector class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into the pay-for-order page.
 *
 * Handles the collect -> verify -> verdict flow for the pay-for-order page:
 * 1. Enqueues pay-for-order.js which gates form submission to acquire a
 *    Blackbox session ID and inject it as a hidden field.
 * 2. Hooks into `woocommerce_before_pay_action` (before payment processing)
 *    to verify the session with Blackbox and block on BLOCK decisions.
 *
 * Fail-open: If verification fails for any reason, the payment proceeds.
 *
 * @internal
 */
class PayForOrderProtector {

	use ClassicFormDataExtractionTrait;

	/**
	 * Source identifier for verify requests from this protector.
	 */
	private const SOURCE = 'pay_for_order';

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
	 * Register hooks for pay-for-order fraud protection.
	 *
	 * Called from FraudProtectionController::on_init() when fraud protection is enabled.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_before_pay_action', array( $this, 'verify_and_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_pay_for_order_script' ) );
	}

	/**
	 * Verify the session with Blackbox and block if needed.
	 *
	 * Called during the `woocommerce_before_pay_action` action, which fires
	 * AFTER nonce verification but BEFORE payment method validation and
	 * process_payment(). Adding a wc_add_notice error prevents
	 * process_payment() from executing (gated by wc_notice_count('error') === 0).
	 *
	 * Fail-open: If session_id is empty, verify is still called (Blackbox/server
	 * decides). If verify throws or returns an error, the payment proceeds.
	 *
	 * @internal
	 *
	 * @param \WC_Order $order The order being paid for.
	 * @return void
	 */
	public function verify_and_block( \WC_Order $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce form handler.
		$request_data = $this->build_request_data( $_POST );

		$payment_data = null;
		try {
			$payment_data = $this->payment_data_resolver->resolve(
				$request_data['payment_method'],
				$request_data['payment_data']
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
				$order->get_id(),
				self::SOURCE,
				$request_data,
				$payment_data
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'verify_and_block failed, allowing pay for order: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
			return;
		}

		if ( ApiClient::DECISION_BLOCK === $decision ) {
			$message = $this->blocked_session_notice->get_message_html( 'purchase' );
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}
	}

	/**
	 * Conditionally enqueue the pay-for-order.js script.
	 *
	 * Only enqueues on the pay-for-order page.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_pay_for_order_script(): void {
		if ( ! is_checkout_pay_page() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-pay-for-order',
			WC_FRAUD_PROTECTION_PLUGIN_URL . 'assets/js/pay-for-order.js',
			array( 'wc-fraud-protection-blackbox-init', 'jquery' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}
}
