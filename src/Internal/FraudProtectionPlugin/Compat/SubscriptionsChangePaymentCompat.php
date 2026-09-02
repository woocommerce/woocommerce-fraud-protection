<?php
/**
 * SubscriptionsChangePaymentCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;

defined( 'ABSPATH' ) || exit;

/**
 * Integrates Blackbox fraud protection into the WooCommerce Subscriptions
 * "Change Payment Method" flow.
 *
 * WooCommerce Subscriptions hijacks the pay-for-order page with its own
 * request handler, so WC Core's `woocommerce_before_pay_action` never fires
 * and the PayForOrderProtector server-side hook is bypassed. This compat
 * layer hooks the Subscriptions action that fires after nonce verification
 * to verify the session and block on BLOCK decisions.
 *
 * The Subscriptions form does not fire the Core pay-form hook, so this compat
 * layer requests the shared scripts and enqueues pay-for-order.js from the
 * form-specific render hook. The script gates the `#order_review` form
 * submission to inject the Blackbox session ID.
 *
 * On BLOCK, the request is stopped entirely via redirect + exit, preventing both
 * `update_payment_method()` and `process_payment()` from running.
 *
 * Fail-open: SessionVerifier handles all internal errors and returns ALLOW.
 *
 * Lives in Compat/ rather than as a top-level Protector because it targets
 * a third-party extension hook and is only relevant when Subscriptions is active.
 */
class SubscriptionsChangePaymentCompat {

	use ClassicFormDataExtractionTrait;

	/**
	 * Source identifier for verify requests from this compat layer.
	 * Historical rows can contain `subscriptions_change_payment_met`; future session readers must group both values.
	 */
	private const SOURCE = 'subscriptions_change_payment';

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
	 * Blackbox script handler, asked for the shared scripts when the form renders.
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
	 * Register hooks for change-payment-method fraud protection.
	 *
	 * The hook only fires when WooCommerce Subscriptions is active and
	 * a customer submits the change-payment-method form.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_subscription_change_payment_method_via_pay_shortcode', array( $this, 'verify_and_block' ) );
		add_action( 'woocommerce_subscriptions_change_payment_after_submit', array( $this, 'enqueue_pay_for_order_script' ), 10, 0 );
	}

	/**
	 * Enqueue the pay-for-order protector when a Subscriptions change-payment form renders.
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

	/**
	 * Verify the session with Blackbox and block if needed.
	 *
	 * Called during the `woocommerce_subscription_change_payment_method_via_pay_shortcode`
	 * action, which fires AFTER nonce verification but BEFORE `update_payment_method()`
	 * and `process_payment()`.
	 *
	 * On BLOCK, stops the request entirely via redirect + exit. This prevents the
	 * Subscriptions handler from reaching `update_payment_method()` (which saves to DB
	 * and triggers gateway cancellation) and `process_payment()`.
	 *
	 * Fail-open: SessionVerifier handles all internal errors and returns ALLOW.
	 *
	 * @internal
	 *
	 * @param \WC_Order $subscription The subscription being updated (WC_Subscription extends WC_Order).
	 * @return void
	 */
	public function verify_and_block( \WC_Order $subscription ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by Subscriptions handler.
		$request_data = $this->build_request_data( $_POST );

		$decision = $this->session_verifier->verify_session(
			$this->get_submitted_session_id(),
			self::SOURCE,
			$subscription->get_id(),
			$request_data
		);

		if ( FraudDecision::Block === $decision ) {
			$message = $this->blocked_session_message->get_html( MessageContext::Generic );
			if ( ! wc_has_notice( $message, 'error' ) ) {
				wc_add_notice( $message, 'error' );
			}

			// Stop the request entirely — the Subscriptions handler would otherwise
			// proceed to update_payment_method() and process_payment().
			// Redirect to the view-subscription page rather than back to the
			// change-payment form, which would be unusable in a blocked state.
			wp_safe_redirect( $subscription->get_view_order_url() );
			exit;
		}
	}
}
