<?php
/**
 * BlockedSessionNotice class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Handles blocked session messaging for fraud protection.
 *
 * This class provides:
 * - Hook into shortcode checkout to display blocked notice
 * - Message generation for both HTML (shortcode) and plaintext (Store API) contexts
 *
 * Note: Store API (block checkout) and payment gateway filtering are handled
 * directly in WC Core classes (Checkout.php and WC_Payment_Gateways).
 *
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class BlockedSessionNotice /* implements RegisterHooksInterface */ {

	/**
	 * Session clearance manager instance.
	 *
	 * @var SessionClearanceManager
	 */
	private SessionClearanceManager $session_manager;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionClearanceManager $session_manager The session clearance manager instance.
	 */
	final public function init( SessionClearanceManager $session_manager ): void {
		$this->session_manager = $session_manager;
	}

	/**
	 * Register hooks for displaying blocked notice.
	 *
	 * This method should only be called when fraud protection is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		// Shop, cart, and checkout pages (both blocks and shortcode) - add notice via wc_add_notice on wp hook.
		add_action( 'wp', array( $this, 'maybe_add_blocked_purchase_notice' ), 10, 0 );

		add_action( 'before_woocommerce_add_payment_method', array( $this, 'maybe_display_generic_blocked_notice' ), 1, 0 );
	}

	/**
	 * Add blocked purchase notice on shop, cart, and checkout pages (both blocks and shortcode),
	 * if the session is blocked. Skips duplicate notices.
	 *
	 * Uses wc_add_notice() to add an error notice that will be rendered by:
	 * - StoreNoticesContainer component for blocks
	 * - wc_print_notices() for shortcodes
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function maybe_add_blocked_purchase_notice(): void {
		if ( ! $this->session_manager->is_session_blocked() ) {
			return;
		}

		if ( ! is_checkout() && ! is_cart() && ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		$message = $this->get_message_html( 'purchase' );

		if ( wc_has_notice( $message, 'error' ) ) {
			return;
		}

		wc_add_notice( $message, 'error' );
	}

	/**
	 * Display blocked notice for non-cart/checkout pages, if the session is blocked.
	 *
	 * Shows a generic message explaining that the request cannot be
	 * processed online and provides contact information for support.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function maybe_display_generic_blocked_notice(): void {
		if ( ! $this->session_manager->is_session_blocked() ) {
			return;
		}

		$message = $this->get_message_html();
		if ( ! wc_has_notice( $message, 'error' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * Get the blocked session message as HTML.
	 *
	 * Includes a mailto link for the support email.
	 *
	 * @param string $context Message context: 'purchase' for purchase-specific message, 'generic' for general use.
	 * @return string HTML message with mailto link.
	 */
	public function get_message_html( string $context = 'generic' ): string {
		$email = $this->get_support_email();

		if ( '' === $email ) {
			return __( 'We are unable to process this request online.', 'woocommerce-fraud-protection' );
		}

		if ( 'purchase' === $context ) {
			return sprintf(
				/* translators: %1$s: mailto link, %2$s: email address */
				__( 'We are unable to process this request online. Please <a href="%1$s">contact support (%2$s)</a> to complete your purchase.', 'woocommerce-fraud-protection' ),
				esc_url( 'mailto:' . $email ),
				esc_html( $email )
			);
		}

		return sprintf(
			/* translators: %1$s: mailto link, %2$s: email address */
			__( 'We are unable to process this request online. Please <a href="%1$s">contact support (%2$s)</a> for assistance.', 'woocommerce-fraud-protection' ),
			esc_url( 'mailto:' . $email ),
			esc_html( $email )
		);
	}

	/**
	 * Get the blocked session message as plaintext.
	 *
	 * Used by Store API responses where HTML is not supported.
	 *
	 * @param string $context Message context: 'purchase' for purchase-specific message, 'generic' for general use.
	 * @return string Plaintext message with email address.
	 */
	public function get_message_plaintext( string $context = 'generic' ): string {
		$email = $this->get_support_email();

		if ( '' === $email ) {
			return __( 'We are unable to process this request online.', 'woocommerce-fraud-protection' );
		}

		if ( 'purchase' === $context ) {
			return sprintf(
				/* translators: %s: support email address */
				__( 'We are unable to process this request online. Please contact support (%s) to complete your purchase.', 'woocommerce-fraud-protection' ),
				$email
			);
		}

		return sprintf(
			/* translators: %s: support email address */
			__( 'We are unable to process this request online. Please contact support (%s) for assistance.', 'woocommerce-fraud-protection' ),
			$email
		);
	}

	/**
	 * Resolve the support email shown in blocked-session messages.
	 *
	 * Falls back along the chain: WooCommerce mailer "from" address -> admin_email.
	 * The WC_Emails mailer can be unavailable on early page renders or partial WC bootstraps,
	 * so the chain is wrapped in defensive checks to avoid fatalling render-time hooks.
	 *
	 * @return string Support email address. May be empty if no source produces a value.
	 */
	private function get_support_email(): string {
		if ( function_exists( 'WC' ) ) {
			$wc     = WC();
			$mailer = $wc instanceof \WooCommerce ? $wc->mailer() : null;
			if ( $mailer instanceof \WC_Emails ) {
				$from = $mailer->get_from_address();
				if ( is_string( $from ) && '' !== $from ) {
					return $from;
				}
			}
		}

		$admin_email = get_option( 'admin_email', '' );

		return is_string( $admin_email ) ? $admin_email : '';
	}
}
