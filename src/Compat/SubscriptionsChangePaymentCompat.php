<?php
/**
 * SubscriptionsChangePaymentCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

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
 * The JS side is already handled: PayForOrderProtector enqueues pay-for-order.js
 * on `is_checkout_pay_page()`, which matches the change-payment page. The script
 * gates the `#order_review` form submission to inject the Blackbox session ID.
 *
 * On BLOCK, the request is stopped entirely via redirect + exit, preventing both
 * `update_payment_method()` and `process_payment()` from running.
 *
 * Fail-open: SessionVerifier handles all internal errors and returns ALLOW.
 *
 * Lives in Compat/ rather than as a top-level Protector because it targets
 * a third-party extension hook and is only relevant when Subscriptions is active.
 *
 * @internal
 */
class SubscriptionsChangePaymentCompat {

	use ClassicFormDataExtractionTrait;

	/**
	 * Source identifier for verify requests from this compat layer.
	 */
	private const SOURCE = 'subscriptions_change_payment_method';

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
	 * Register hooks for change-payment-method fraud protection.
	 *
	 * The hook only fires when WooCommerce Subscriptions is active and
	 * a customer submits the change-payment-method form.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_subscription_change_payment_method_via_pay_shortcode', array( $this, 'verify_and_block' ) );
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
			$this->get_blackbox_session_id(),
			self::SOURCE,
			$subscription->get_id(),
			$request_data
		);

		if ( ApiClient::DECISION_BLOCK === $decision ) {
			$message = $this->blocked_session_notice->get_message_html( 'generic' );
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
