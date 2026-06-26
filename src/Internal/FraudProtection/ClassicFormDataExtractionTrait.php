<?php
/**
 * ClassicFormDataExtractionTrait file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Shared request data extraction for protectors and compat layers that read from $_POST.
 *
 * BlocksCheckoutProtector reads from WP_REST_Request and does not use this trait.
 */
trait ClassicFormDataExtractionTrait {

	/**
	 * Get the Blackbox session ID from the POST data.
	 *
	 * @return string The session ID, or empty string if not found.
	 */
	private function get_blackbox_session_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce before this hook fires.
		$session_id = isset( $_POST[ BlackboxScriptHandler::SESSION_ID_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ BlackboxScriptHandler::SESSION_ID_FIELD ] ) ) : '';
		return $session_id;
	}

	/**
	 * Build structured request data from form data.
	 *
	 * Extracts payment method, payment data, and (when present) billing/shipping
	 * addresses from the provided form data array. Address keys are only included
	 * when the form contains address fields (e.g. checkout but not add-payment-method).
	 *
	 * @param array $form_data Form data array — WC's $posted_data (shortcode checkout) or $_POST (add-payment-method).
	 * @return array Structured request data.
	 */
	private function build_request_data( array $form_data ): array {
		$request_data = array(
			'payment_method' => sanitize_text_field( wp_unslash( $form_data['payment_method'] ?? '' ) ),
			'payment_data'   => $this->extract_payment_data(),
		);

		$billing_address = $this->extract_address( $form_data, 'billing_' );
		if ( ! empty( $billing_address ) ) {
			$request_data['billing_address'] = $billing_address;
		}

		$shipping_address = $this->extract_address( $form_data, 'shipping_' );
		if ( ! empty( $shipping_address ) ) {
			$request_data['shipping_address'] = $shipping_address;
		}

		return $request_data;
	}

	/**
	 * Extract an address array from form data by prefix.
	 *
	 * @param array  $form_data Form data array.
	 * @param string $prefix    The address prefix ('billing_' or 'shipping_').
	 * @return array Address data with prefix stripped from keys.
	 */
	private function extract_address( array $form_data, string $prefix ): array {
		$address = array();
		$length  = strlen( $prefix );

		foreach ( $form_data as $key => $value ) {
			if ( 0 === strpos( $key, $prefix ) && is_string( $value ) ) {
				$address[ substr( $key, $length ) ] = $value;
			}
		}

		return $address;
	}

	/**
	 * Extract gateway-specific payment data from $_POST.
	 *
	 * Excludes known non-payment prefixes and exact keys to isolate
	 * gateway-specific fields. Returns a flat key-value map — the same
	 * format compat layers receive from the blocks checkout.
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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce before this hook fires.
		foreach ( $_POST as $key => $value ) {
			if ( in_array( $key, $excluded_keys, true ) ) {
				continue;
			}

			$skip = false;
			foreach ( $excluded_prefixes as $prefix ) {
				if ( 0 === strpos( $key, $prefix ) ) {
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
