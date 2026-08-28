<?php
/**
 * AddPaymentMethodProtector class file.
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
 * Integrates Blackbox fraud protection into the add-payment-method page.
 *
 * Handles the collect -> verify -> verdict flow for My Account > Add Payment Method:
 * 1. Enqueues add-payment-method.js which gates form submission to acquire a
 *    Blackbox session ID and inject it as a hidden field.
 * 2. Hooks into `woocommerce_add_payment_method_form_is_valid` (before gateway
 *    processing) to verify the session with Blackbox and block on BLOCK decisions.
 *
 * Fail-open: Delegated to SessionVerifier — all internal errors result in ALLOW.
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
	 * Register hooks for add-payment-method fraud protection.
	 *
	 * Called from FraudProtectionController::handle_init() when fraud protection is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_add_payment_method_form_is_valid', array( $this, 'verify_and_block' ) );
		add_action( 'woocommerce_add_payment_method_form_bottom', array( $this, 'enqueue_add_payment_method_script' ), 10, 0 );
	}

	/**
	 * Verify the session with Blackbox and block if needed.
	 *
	 * Called during the `woocommerce_add_payment_method_form_is_valid` filter,
	 * which fires AFTER nonce verification but BEFORE gateway processing.
	 * Returning false prevents the payment method from being added.
	 *
	 * Fail-open: If session_id is empty, verify is still called (Blackbox/server
	 * decides). SessionVerifier handles all internal errors and returns ALLOW.
	 *
	 * @internal
	 *
	 * @param mixed $is_valid Current form validity from prior filters. A falsey value stops processing.
	 * @return bool True to allow, false to block.
	 */
	public function verify_and_block( mixed $is_valid ): bool {
		// Respect prior validation failures.
		if ( ! $is_valid ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce form handler.
		$request_data = $this->build_request_data( $_POST );

		$decision = $this->session_verifier->verify_session(
			$this->get_submitted_session_id(),
			self::SOURCE,
			0, // No order in add-payment-method flow.
			$request_data
		);

		if ( FraudDecision::Block === $decision ) {
			$message = $this->blocked_session_message->get_html( MessageContext::Generic );
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}
			return false;
		}

		return true;
	}

	/**
	 * Enqueue the protector when the add-payment-method form renders.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function enqueue_add_payment_method_script(): void {
		if ( ! $this->blackbox_script_handler->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'wc-fraud-protection-add-payment-method',
			plugins_url( 'assets/js/add-payment-method.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init', 'jquery' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);
	}
}
