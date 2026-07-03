<?php
/**
 * ShortcodeCheckoutProtector class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ClassicFormDataExtractionTrait;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\MessageContext;

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
 * Fail-open: Delegated to SessionVerifier — all internal errors result in ALLOW.
 */
class ShortcodeCheckoutProtector {

	use ClassicFormDataExtractionTrait;

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
	 * Register hooks for shortcode checkout fraud protection.
	 *
	 * Called from FraudProtectionController::handle_init() when fraud protection is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		// Run as late as possible so verify_and_block()'s guard sees other validators' errors.
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'verify_and_block' ), PHP_INT_MAX, 2 );
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
	 * decides). SessionVerifier handles all internal errors and returns ALLOW.
	 *
	 * @internal
	 *
	 * @param array     $posted_data The posted checkout form data.
	 * @param \WP_Error $errors      Validation errors object — add errors here to block checkout.
	 * @return void
	 */
	public function verify_and_block( array $posted_data, \WP_Error $errors ): void {
		// Other validation already failed — the order won't be created, so skip verify.
		if ( $this->checkout_has_blocking_error( $errors ) ) {
			return;
		}

		$request_data = $this->build_request_data( $posted_data );

		$decision = $this->session_verifier->verify_session(
			$this->get_blackbox_session_id(),
			self::SOURCE,
			0, // No order_id yet — pre-order hook. Cart data used instead.
			$request_data
		);

		if ( FraudDecision::Block === $decision ) {
			$errors->add(
				'woocommerce_checkout_error',
				$this->blocked_session_notice->get_message_plaintext( MessageContext::Purchase )
			);
		}
	}

	/**
	 * Whether the checkout already has an error that will stop order creation.
	 *
	 * Core creates the order only when wc_notice_count( 'error' ) is zero, after
	 * converting each $errors message to a notice. Two error sources exist when
	 * this hook runs:
	 *
	 * - Error notices already added via wc_add_notice() — e.g. by a
	 *   woocommerce_checkout_process validator. These are counted directly.
	 * - The $errors object from field validation, not yet flushed to notices.
	 *   Core flushes these via wc_add_notice(), which runs each message through the
	 *   woocommerce_add_error filter and then drops empty ones, so a message that is
	 *   empty (or filtered to empty) does not block — only a surviving message does.
	 *
	 * @param \WP_Error $errors The checkout validation errors.
	 * @return bool True if the checkout will be blocked from creating an order.
	 */
	private function checkout_has_blocking_error( \WP_Error $errors ): bool {
		if ( wc_notice_count( 'error' ) > 0 ) {
			return true;
		}

		foreach ( $errors->get_error_messages() as $message ) {
			if ( ! empty( apply_filters( 'woocommerce_add_error', $message ) ) ) { // phpcs:ignore WooCommerce.Commenting.CommentHooks -- Re-applying core's existing filter to mirror wc_add_notice()'s empty-message check, not defining a hook.
				return true;
			}
		}

		return false;
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
		if ( ! is_checkout() || is_order_received_page() || is_checkout_pay_page() || has_block( 'woocommerce/checkout' ) ) {
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
}
