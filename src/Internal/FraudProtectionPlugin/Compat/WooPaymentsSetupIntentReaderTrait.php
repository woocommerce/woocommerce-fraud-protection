<?php
/**
 * WooPaymentsSetupIntentReaderTrait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Reads WooPayments setup intents for the WooPayments compat layers.
 *
 * On My Account > Add payment method, WooPayments saves the payment method of
 * the setup intent that the form posts, which the browser can change after
 * the setup intent request.
 */
trait WooPaymentsSetupIntentReaderTrait {

	/**
	 * Get the payment method ID that a WooPayments setup intent holds.
	 *
	 * @param string $setup_intent_id The setup intent ID.
	 * @return ?string The payment method ID, or null when the setup intent cannot be read or holds none.
	 * @throws \Throwable When the WooPayments request throws.
	 */
	private function get_setup_intent_payment_method_id( string $setup_intent_id ): ?string {
		if ( '' === $setup_intent_id || ! class_exists( '\WCPay\Core\Server\Request\Get_Setup_Intention' ) ) {
			return null;
		}

		$setup_intent = \WCPay\Core\Server\Request\Get_Setup_Intention::create( $setup_intent_id )->send();

		if ( ! is_object( $setup_intent ) || ! is_callable( array( $setup_intent, 'get_payment_method_id' ) ) ) {
			return null;
		}

		$payment_method_id = $setup_intent->get_payment_method_id();

		return is_string( $payment_method_id ) && '' !== $payment_method_id ? $payment_method_id : null;
	}
}
