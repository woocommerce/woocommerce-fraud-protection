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

		$decision = $this->session_verifier->verify_session(
			$this->get_submitted_session_id(),
			self::SOURCE,
			$order->get_id(),
			$request_data
		);

		if ( FraudDecision::Block === $decision ) {
			$message = $this->blocked_session_message->get_html( MessageContext::Purchase );
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}
		}
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
