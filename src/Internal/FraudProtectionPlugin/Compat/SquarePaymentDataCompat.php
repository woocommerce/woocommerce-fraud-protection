<?php
/**
 * SquarePaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Square payment data into structured PaymentMethodData.
 *
 * Extracts card details directly from the checkout payment field keys sent by the
 * Square gateway (no API call needed).
 */
class SquarePaymentDataCompat {

	/**
	 * Register the filter callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve' ), 10, 2 );
	}

	/**
	 * Resolve Square payment data.
	 *
	 * @internal
	 *
	 * @param PaymentMethodData $resolved               Previously resolved data.
	 * @param array             $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( PaymentMethodData $resolved, array $checkout_payment_fields ): PaymentMethodData {
		if ( 'square_credit_card' !== $resolved->get_gateway() ) {
			return $resolved;
		}

		$token_value = $checkout_payment_fields['wc-square-credit-card-payment-token'] ?? '';
		$is_saved    = ! empty( $token_value ) && 'new' !== $token_value;
		$brand       = $checkout_payment_fields['wc-square-credit-card-card-type'] ?? null;
		$last4       = $checkout_payment_fields['wc-square-credit-card-last-four'] ?? null;
		$exp_month   = isset( $checkout_payment_fields['wc-square-credit-card-exp-month'] )
			? (int) $checkout_payment_fields['wc-square-credit-card-exp-month']
			: null;
		$exp_year    = isset( $checkout_payment_fields['wc-square-credit-card-exp-year'] )
			? (int) $checkout_payment_fields['wc-square-credit-card-exp-year']
			: null;
		$postcode    = $checkout_payment_fields['wc-square-credit-card-payment-postcode'] ?? null;

		$transaction_mode    = $this->resolve_transaction_mode();
		$merchant_identifier = $this->resolve_merchant_identifier();

		// Saved cards have empty card keys — pass through the token-based data.
		if ( empty( $brand ) && empty( $last4 ) ) {
			return $this->add_merchant_identifier( $resolved->with_transaction_mode( $transaction_mode ), $merchant_identifier );
		}

		return $this->add_merchant_identifier(
			new PaymentMethodData(
				'square_credit_card',
				'card',
				$is_saved,
				PaymentInstrumentData::from_array(
					array(
						'brand'            => $brand,
						'last4'            => $last4,
						'exp_month'        => $exp_month,
						'exp_year'         => $exp_year,
						'billing_postcode' => $postcode,
					)
				),
				$transaction_mode
			),
			$merchant_identifier
		);
	}

	/**
	 * Resolve the configured Square location identifier.
	 *
	 * @return ?string The location identifier, if available.
	 */
	private function resolve_merchant_identifier(): ?string {
		if ( ! function_exists( 'wc_square' ) ) {
			return null;
		}

		try {
			$identifier = wc_square()->get_settings_handler()->get_location_id();

			return is_string( $identifier ) && '' !== trim( $identifier ) ? trim( $identifier ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Add the merchant identifier when it was resolved.
	 *
	 * @param PaymentMethodData $data Payment data.
	 * @param ?string           $merchant_identifier Merchant location identifier.
	 * @return PaymentMethodData Payment data.
	 */
	private function add_merchant_identifier( PaymentMethodData $data, ?string $merchant_identifier ): PaymentMethodData {
		return null !== $merchant_identifier ? $data->with_merchant_identifier( $merchant_identifier, 'location' ) : $data;
	}

	/**
	 * Resolve the Square transaction mode.
	 *
	 * Uses the Square gateway's own settings handler when available, which is
	 * the same method Square uses internally to select the API environment.
	 *
	 * @return PaymentMode The transaction mode (Unknown if the gateway is unavailable).
	 */
	private function resolve_transaction_mode(): PaymentMode {
		if ( ! function_exists( 'wc_square' ) ) {
			return PaymentMode::Unknown;
		}

		try {
			return wc_square()->get_settings_handler()->is_sandbox() ? PaymentMode::Test : PaymentMode::Live;
		} catch ( \Throwable $e ) {
			return PaymentMode::Unknown;
		}
	}
}
