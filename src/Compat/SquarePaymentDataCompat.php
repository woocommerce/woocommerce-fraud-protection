<?php
/**
 * SquarePaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Square payment data into structured PaymentMethodData.
 *
 * Extracts card details directly from the checkout payment field keys sent by the
 * Square gateway (no API call needed).
 *
 * @internal
 */
class SquarePaymentDataCompat {

	/**
	 * Register the filter callback.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! FraudProtectionController::feature_is_enabled() ) {
			return;
		}

		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve' ), 10, 2 );
	}

	/**
	 * Resolve Square payment data.
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

		$transaction_mode = $this->resolve_transaction_mode();

		// Saved cards have empty card keys — pass through the token-based data.
		if ( empty( $brand ) && empty( $last4 ) ) {
			return $resolved->with_transaction_mode( $transaction_mode );
		}

		return new PaymentMethodData(
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
		);
	}

	/**
	 * Resolve the Square transaction mode.
	 *
	 * Uses the Square gateway's own settings handler when available, which is
	 * the same method Square uses internally to select the API environment.
	 *
	 * @return string MODE_TEST, MODE_LIVE, or MODE_UNKNOWN if the gateway is unavailable.
	 */
	private function resolve_transaction_mode(): string {
		if ( ! function_exists( 'wc_square' ) ) {
			return PaymentMethodData::MODE_UNKNOWN;
		}

		try {
			return wc_square()->get_settings_handler()->is_sandbox() ? PaymentMethodData::MODE_TEST : PaymentMethodData::MODE_LIVE;
		} catch ( \Throwable $e ) {
			return PaymentMethodData::MODE_UNKNOWN;
		}
	}
}
