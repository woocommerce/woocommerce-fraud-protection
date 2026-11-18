<?php
/**
 * PayForOrderProtector class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;

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
 * Fail-open: Delegated to SessionVerifier — all internal errors result in ALLOW.
 */
class PayForOrderProtector {

	use ClassicFormDataExtractionTrait;

	/**
	 * Source identifier for verify requests from this protector.
	 */
	private const SOURCE = 'pay_for_order';

	/**
	 * Order meta key for the session IDs already sent to verify payments for the order.
	 *
	 * The order's session ID meta holds only the ID of the latest verification,
	 * and a verification without a session replaces it. This list keeps the
	 * earlier ones, so a session ID left in the form is recognized on every
	 * later submission, not only on the next one.
	 */
	private const USED_SESSION_IDS_KEY = '_wc_fraud_protection_pay_for_order_used_session_ids';

	/**
	 * Maximum number of used session IDs kept per order.
	 */
	private const MAX_USED_SESSION_IDS = 10;

	/**
	 * Maximum length of a session ID kept in the list.
	 */
	private const MAX_SESSION_ID_LENGTH = 255;

	/**
	 * Session verifier instance.
	 *
	 * @var SessionVerifier
	 */
	private SessionVerifier $session_verifier;

	/**
	 * Blocked-session message generator.
	 *
	 * @var BlockedSessionMessage
	 */
	private BlockedSessionMessage $blocked_session_message;

	/**
	 * Blackbox script handler, asked for the shared scripts at enqueue time.
	 *
	 * @var BlackboxScriptHandler
	 */
	private BlackboxScriptHandler $blackbox_script_handler;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionVerifier       $session_verifier        The session verifier instance.
	 * @param BlockedSessionMessage $blocked_session_message The blocked-session message generator.
	 * @param BlackboxScriptHandler $blackbox_script_handler The shared Blackbox script handler.
	 */
	final public function init(
		SessionVerifier $session_verifier,
		BlockedSessionMessage $blocked_session_message,
		BlackboxScriptHandler $blackbox_script_handler
	): void {
		$this->session_verifier        = $session_verifier;
		$this->blocked_session_message = $blocked_session_message;
		$this->blackbox_script_handler = $blackbox_script_handler;
	}

	/**
	 * Register hooks for pay-for-order fraud protection.
	 *
	 * Called from FraudProtectionController::handle_init() when fraud protection is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_before_pay_action', array( $this, 'verify_and_block' ) );
		add_action( 'before_woocommerce_pay_form', array( $this, 'enqueue_pay_for_order_script' ), 10, 0 );
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
	 * decides). SessionVerifier handles all internal errors and returns ALLOW.
	 *
	 * @internal
	 *
	 * @param \WC_Order $order The order being paid for.
	 * @return void
	 */
	public function verify_and_block( \WC_Order $order ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce form handler.
		$request_data = $this->build_request_data( $_POST );
		$submitted_id = $this->get_submitted_session_id();
		$session_id   = $submitted_id;
		$is_reused    = $this->is_used_session_id( $submitted_id, $order );

		// A payment that ends without leaving the page can leave the earlier
		// attempt's session ID in the form. That ID was already verified for
		// this order, so this attempt is verified without a session instead.
		if ( $is_reused ) {
			FraudProtectionController::log(
				'info',
				'Pay-for-order submitted the session ID already verified for the order; verifying without a session',
				array(
					'event_source' => self::SOURCE,
					'session_id'   => $submitted_id,
					'order_id'     => $order->get_id(),
				)
			);

			$session_id                            = '';
			$request_data[ SessionIdError::FIELD ] = SessionIdError::REUSED;
		}

		$decision = $this->session_verifier->verify_session(
			$session_id,
			self::SOURCE,
			$order->get_id(),
			$request_data
		);

		// A completed verification used the submitted session ID. A failed one
		// did not, so the same ID can be sent again.
		if ( $is_reused || '' !== $this->session_verifier->last_verified_session_id() ) {
			$this->remember_used_session_id( $submitted_id, $order );
		}

		if ( FraudDecision::Block === $decision ) {
			$message = $this->blocked_session_message->get_html( MessageContext::Purchase );
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}
	}

	/**
	 * Check whether a submitted session ID was already used to verify a payment for the order.
	 *
	 * It was when it is the session ID that the latest verification stored on
	 * the order, or one of the IDs that earlier pay-for-order submissions sent.
	 *
	 * @param mixed     $session_id The submitted session ID.
	 * @param \WC_Order $order      The order being paid for.
	 * @return bool True when the submitted ID is a nonempty string that was already used.
	 */
	private function is_used_session_id( mixed $session_id, \WC_Order $order ): bool {
		if ( ! is_string( $session_id ) || '' === $session_id ) {
			return false;
		}

		try {
			if ( $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) === $session_id ) {
				return true;
			}

			return in_array( $session_id, $this->get_used_session_ids( $order ), true );
		} catch ( \Throwable $e ) {
			// Fail open: the submitted session ID is verified as before.
			FraudProtectionController::log(
				'warning',
				'Reading the order session IDs failed',
				array(
					'event_source'    => self::SOURCE,
					'order_id'        => $order->get_id(),
					'exception_class' => $e::class,
				)
			);
			return false;
		}
	}

	/**
	 * Add a submitted session ID to the order's list of used ones.
	 *
	 * Keeps the latest MAX_USED_SESSION_IDS. Errors leave the list unchanged.
	 *
	 * @param mixed     $session_id The submitted session ID.
	 * @param \WC_Order $order      The order being paid for.
	 * @return void
	 */
	private function remember_used_session_id( mixed $session_id, \WC_Order $order ): void {
		if ( ! is_string( $session_id ) || '' === $session_id || strlen( $session_id ) > self::MAX_SESSION_ID_LENGTH ) {
			return;
		}

		try {
			$used = $this->get_used_session_ids( $order );
			if ( in_array( $session_id, $used, true ) ) {
				return;
			}

			$used[] = $session_id;
			$order->update_meta_data( self::USED_SESSION_IDS_KEY, array_slice( $used, -self::MAX_USED_SESSION_IDS ) );
			$order->save_meta_data();
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Storing the used order session ID failed',
				array(
					'event_source'    => self::SOURCE,
					'order_id'        => $order->get_id(),
					'exception_class' => $e::class,
				)
			);
		}
	}

	/**
	 * Read the session IDs already sent to verify payments for the order.
	 *
	 * @param \WC_Order $order The order being paid for.
	 * @return string[] The stored session IDs; values that are not strings are dropped.
	 */
	private function get_used_session_ids( \WC_Order $order ): array {
		$stored = $order->get_meta( self::USED_SESSION_IDS_KEY );

		return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : array();
	}

	/**
	 * Enqueue the pay-for-order protector when a validated pay form renders.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_pay_for_order_script(): void {
		if ( ! $this->blackbox_script_handler->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-pay-for-order',
			plugins_url( 'assets/js/pay-for-order.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init', 'jquery' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}
}
