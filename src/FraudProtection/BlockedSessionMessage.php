<?php
/**
 * BlockedSessionMessage class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Generates the customer-facing message shown when a session is blocked.
 *
 * A stateless helper: given a {@see MessageContext}, it returns the "unable to
 * process this request online" copy in HTML (for page renders) or plaintext (for
 * Store API / JSON responses), with a support email resolved from the store.
 *
 * Exposed publicly so payment-gateway compat layers can render the same blocked
 * message from their own flows. The wording never reveals fraud detection.
 */
class BlockedSessionMessage {

	/**
	 * Get the blocked session message as HTML.
	 *
	 * Includes a mailto link for the support email. Use for page renders
	 * (e.g. wc_add_notice()).
	 *
	 * @param MessageContext $context Message context: Purchase for purchase-specific message, Generic for general use.
	 * @return string HTML message with mailto link.
	 */
	public function get_html( MessageContext $context = MessageContext::Generic ): string {
		$email = $this->get_support_email();

		if ( '' === $email ) {
			return __( 'We are unable to process this request online.', 'woocommerce-fraud-protection' );
		}

		if ( MessageContext::Purchase === $context ) {
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
	 * Use for responses where HTML is not supported (e.g. Store API JSON).
	 *
	 * @param MessageContext $context Message context: Purchase for purchase-specific message, Generic for general use.
	 * @return string Plaintext message with email address.
	 */
	public function get_plaintext( MessageContext $context = MessageContext::Generic ): string {
		$email = $this->get_support_email();

		if ( '' === $email ) {
			return __( 'We are unable to process this request online.', 'woocommerce-fraud-protection' );
		}

		if ( MessageContext::Purchase === $context ) {
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
