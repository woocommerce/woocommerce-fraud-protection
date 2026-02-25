<?php
/**
 * AddPaymentMethodProtector class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into the add-payment-method page.
 *
 * Handles the collect -> verify -> verdict flow for My Account > Add Payment Method:
 * 1. Enqueues add-payment-method.js which gates form submission to acquire a
 *    Blackbox session ID and inject it as a hidden field.
 * 2. Hooks into `woocommerce_add_payment_method_form_is_valid` (before gateway
 *    processing) to verify the session with Blackbox and block on BLOCK decisions.
 *
 * Fail-open: If verification fails for any reason, the form proceeds.
 *
 * @internal
 */
class AddPaymentMethodProtector {

	use ClassicFormDataExtractionTrait;

	/**
	 * Source identifier for verify requests from this protector.
	 */
	private const SOURCE = 'add_payment_method';

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
	 * Register hooks for add-payment-method fraud protection.
	 *
	 * Called from FraudProtectionController::on_init() when fraud protection is enabled.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_payment_method_form_is_valid', array( $this, 'verify_and_block' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_add_payment_method_script' ) );
	}

	/**
	 * Verify the session with Blackbox and block if needed.
	 *
	 * Called during the `woocommerce_add_payment_method_form_is_valid` filter,
	 * which fires AFTER nonce verification but BEFORE gateway processing.
	 * Returning false prevents the payment method from being added.
	 *
	 * Fail-open: If session_id is empty, verify is still called (Blackbox/server
	 * decides). If verify throws or returns an error, the form proceeds.
	 *
	 * @internal
	 *
	 * @param bool $is_valid Current form validity from prior filters.
	 * @return bool True to allow, false to block.
	 */
	public function verify_and_block( bool $is_valid ): bool {
		// Respect prior validation failures.
		if ( ! $is_valid ) {
			return $is_valid;
		}

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
				0, // No order in add-payment-method flow.
				self::SOURCE,
				$request_data,
				$payment_data
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'verify_and_block failed, allowing add payment method: ' . $e->getMessage(),
				array( 'exception' => $e )
			);
			return true;
		}

		if ( ApiClient::DECISION_BLOCK === $decision ) {
			$message = $this->blocked_session_notice->get_message_html( 'generic' );
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}
			return false;
		}

		return true;
	}

	/**
	 * Conditionally enqueue the add-payment-method.js script.
	 *
	 * Only enqueues on the actual add-payment-method page, not the
	 * payment methods listing page.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_add_payment_method_script(): void {
		global $wp;

		if ( ! is_add_payment_method_page() || ! isset( $wp->query_vars['add-payment-method'] ) ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-add-payment-method',
			WC_FRAUD_PROTECTION_PLUGIN_URL . 'assets/js/add-payment-method.js',
			array( 'wc-fraud-protection-blackbox-init', 'jquery' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}
}
