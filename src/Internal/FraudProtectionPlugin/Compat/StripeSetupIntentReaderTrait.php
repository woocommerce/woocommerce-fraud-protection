<?php
/**
 * StripeSetupIntentReaderTrait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Stripe setup intents for the Stripe compat layers.
 *
 * On My Account > Add payment method, Stripe saves the payment method of the
 * setup intent that the form posts, which the browser can change after the
 * setup intent request.
 */
trait StripeSetupIntentReaderTrait {

	/**
	 * Get the payment method ID that a Stripe setup intent holds.
	 *
	 * @param string $setup_intent_id The setup intent ID.
	 * @return ?string The payment method ID, or null when the setup intent cannot be read or holds none.
	 * @throws \Throwable When the Stripe API request throws.
	 */
	private function get_setup_intent_payment_method_id( string $setup_intent_id ): ?string {
		if ( '' === $setup_intent_id || ! class_exists( '\WC_Stripe_API' ) ) {
			return null;
		}

		$setup_intent = \WC_Stripe_API::retrieve( 'setup_intents/' . rawurlencode( $setup_intent_id ) );

		if ( ! is_object( $setup_intent ) || is_wp_error( $setup_intent ) || ! empty( $setup_intent->error ) ) {
			return null;
		}

		$payment_method_id = $setup_intent->payment_method ?? null;
		if ( is_object( $payment_method_id ) ) {
			$payment_method_id = $payment_method_id->id ?? null;
		}

		return is_string( $payment_method_id ) && '' !== $payment_method_id ? $payment_method_id : null;
	}
}
