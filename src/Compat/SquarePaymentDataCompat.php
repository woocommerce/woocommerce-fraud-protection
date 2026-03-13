<?php
/**
 * SquarePaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Square payment data into structured PaymentMethodData.
 *
 * Extracts card details directly from the payment_data keys sent by the
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
		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve' ), 10, 3 );
	}

	/**
	 * Resolve Square payment data.
	 *
	 * @param ?PaymentMethodData $resolved       Previously resolved data.
	 * @param string             $payment_method The gateway ID.
	 * @param array              $payment_data   Normalized key-value payment data.
	 * @return ?PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( ?PaymentMethodData $resolved, string $payment_method, array $payment_data ): ?PaymentMethodData {
		if ( 'square_credit_card' !== $payment_method ) {
			return $resolved;
		}

		$token_value = $payment_data['wc-square-credit-card-payment-token'] ?? '';
		$is_saved    = ! empty( $token_value ) && 'new' !== $token_value;
		$brand       = $payment_data['wc-square-credit-card-card-type'] ?? null;
		$last4       = $payment_data['wc-square-credit-card-last-four'] ?? null;
		$exp_month   = isset( $payment_data['wc-square-credit-card-exp-month'] )
			? (int) $payment_data['wc-square-credit-card-exp-month']
			: null;
		$exp_year    = isset( $payment_data['wc-square-credit-card-exp-year'] )
			? (int) $payment_data['wc-square-credit-card-exp-year']
			: null;
		$postcode    = $payment_data['wc-square-credit-card-payment-postcode'] ?? null;

		// Saved cards have empty card keys — pass through the token-based data.
		if ( empty( $brand ) && empty( $last4 ) && $resolved instanceof PaymentMethodData ) {
			return $resolved;
		}

		return new PaymentMethodData(
			'square_credit_card',
			'card',
			$is_saved,
			new CardPaymentMethodData(
				$brand,
				null,
				$last4,
				null,
				null,
				$exp_month,
				$exp_year,
				$postcode
			)
		);
	}
}
