<?php
/**
 * ClassicFormRequestData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the session ID and the verification request data from a classic form submission.
 *
 * For an extension that handles its own classic (non-Store API) form POST, such as a
 * pay-for-order or change-payment-method form protected by the
 * `wc-fraud-protection-pay-for-order` script. Build the request data with
 * {@see build_request_data()}, read the session ID with {@see get_submitted_session_id()},
 * and pass both to {@see SessionVerifier::verify_session()}. Nonce verification is the
 * caller's responsibility.
 *
 * The class is stateless; create it with `new` or resolve it from the WooCommerce container.
 *
 * @since 0.3.1
 */
class ClassicFormRequestData {

	/**
	 * Get the submitted session ID from the current request's POST body.
	 *
	 * The value is unslashed but not sanitized: {@see SessionVerifier::verify_session()}
	 * normalizes it and treats a non-string as missing.
	 *
	 * @return mixed The submitted session ID, or an empty string when the field is absent.
	 *
	 * @since 0.3.1
	 */
	public function get_submitted_session_id(): mixed {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is the caller's responsibility.
		if ( ! array_key_exists( SessionVerifier::SESSION_ID_FIELD, $_POST ) ) {
			return '';
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification is the caller's responsibility. Strings are unslashed below; SessionVerifier normalizes the value.
		$value = $_POST[ SessionVerifier::SESSION_ID_FIELD ];

		return is_string( $value ) ? wp_unslash( $value ) : $value;
	}

	/**
	 * Build the verification request data from submitted form data.
	 *
	 * The payment method and the billing and shipping addresses come from `$form_data`;
	 * address keys are only included when the form contains address fields. The
	 * gateway-specific payment fields are always read from the current request's POST
	 * body, with the known WooCommerce checkout and account fields removed. The result can
	 * also carry internal diagnostic keys; pass it to the verifier unchanged.
	 *
	 * @param array $form_data Submitted form data: WooCommerce's posted checkout data, or `$_POST` for a form the caller handles itself.
	 * @return array Request data with `payment_method`, `payment_data`, and when present `billing_address` and `shipping_address`.
	 *
	 * @since 0.3.1
	 */
	public function build_request_data( array $form_data ): array {
		$request_data = array(
			'payment_method' => sanitize_text_field( wp_unslash( $form_data['payment_method'] ?? '' ) ),
			'payment_data'   => $this->extract_payment_data(),
		);

		$billing_address = self::extract_address( $form_data, 'billing_' );
		if ( ! empty( $billing_address ) ) {
			$request_data['billing_address'] = $billing_address;
		}

		$shipping_address = self::extract_address( $form_data, 'shipping_' );
		if ( ! empty( $shipping_address ) ) {
			$request_data['shipping_address'] = $shipping_address;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verification is the caller's responsibility. Strings are unslashed and sanitized below.
		$session_id_error = $_POST[ SessionIdError::FIELD ] ?? null;
		if ( is_string( $session_id_error ) ) {
			$request_data[ SessionIdError::FIELD ] = sanitize_key( wp_unslash( $session_id_error ) );
		} elseif ( ! array_key_exists( SessionVerifier::SESSION_ID_FIELD, $_POST ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is the caller's responsibility.
			// The plugin's scripts always send the field, so they did not handle this request.
			$request_data[ SessionIdError::FIELD ] = SessionIdError::NOT_SUBMITTED;
		}

		return $request_data;
	}

	/**
	 * Extract an address array from form data by prefix.
	 *
	 * @param array  $form_data Form data array.
	 * @param string $prefix    The address prefix ('billing_' or 'shipping_').
	 * @return array Address data with the prefix stripped from the keys.
	 */
	private static function extract_address( array $form_data, string $prefix ): array {
		$address = array();
		$length  = strlen( $prefix );

		foreach ( $form_data as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( str_starts_with( $key, $prefix ) && is_string( $value ) ) {
				$address[ substr( $key, $length ) ] = $value;
			}
		}

		return $address;
	}

	/**
	 * Extract the gateway-specific payment fields from the current request's POST body.
	 *
	 * Removes the known non-payment prefixes and keys so only gateway-specific fields
	 * remain, as a flat key-value map: the same format the blocks checkout passes to
	 * the resolved-payment-data filter.
	 *
	 * @return array Flat key-value map of payment-related POST fields.
	 */
	private function extract_payment_data(): array {
		$excluded_prefixes = array(
			'billing_',
			'shipping_',
			'order_',
			'account_',
			'woocommerce',
			'_wp',
			'wc_fraud_protection_',
			'wc_order_attribution_',
		);

		// WooCommerce core checkout form fields that are not gateway-specific.
		$excluded_keys = array(
			'terms',
			'terms-field',
			'ship_to_different_address',
		);

		$payment_data = array();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verification is the caller's responsibility.
		foreach ( $_POST as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			if ( in_array( $key, $excluded_keys, true ) ) {
				continue;
			}

			$skip = false;
			foreach ( $excluded_prefixes as $prefix ) {
				if ( str_starts_with( $key, $prefix ) ) {
					$skip = true;
					break;
				}
			}

			if ( ! $skip ) {
				$payment_data[ sanitize_text_field( $key ) ] = wc_clean( wp_unslash( $value ) );
			}
		}

		return $payment_data;
	}
}
