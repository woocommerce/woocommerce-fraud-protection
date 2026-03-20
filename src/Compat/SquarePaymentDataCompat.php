<?php
/**
 * SquarePaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
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
		if ( ! FraudProtectionController::feature_is_enabled() ) {
			return;
		}

		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve' ), 10, 2 );
	}

	/**
	 * Resolve Square payment data.
	 *
	 * @param PaymentMethodData $resolved     Previously resolved data.
	 * @param array             $payment_data Normalized key-value payment data.
	 * @return PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( PaymentMethodData $resolved, array $payment_data ): PaymentMethodData {
		if ( 'square_credit_card' !== $resolved->get_gateway() ) {
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

		$transaction_mode = $this->resolve_transaction_mode();

		// Saved cards have empty card keys — pass through the token-based data.
		if ( empty( $brand ) && empty( $last4 ) ) {
			return $resolved->with_transaction_mode( $transaction_mode );
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
			),
			$transaction_mode
		);
	}

	/**
	 * Resolve the Square transaction mode from settings.
	 *
	 * Checks the WC_SQUARE_SANDBOX constant first (development override),
	 * then falls back to the enable_sandbox setting.
	 *
	 * @return ?string MODE_TEST, MODE_LIVE, or MODE_UNKNOWN if settings are unavailable.
	 */
	private function resolve_transaction_mode(): ?string {
		if ( defined( 'WC_SQUARE_SANDBOX' ) && WC_SQUARE_SANDBOX ) {
			return PaymentMethodData::MODE_TEST;
		}

		$settings = get_option( 'wc_square_settings' );

		if ( ! is_array( $settings ) || ! isset( $settings['enable_sandbox'] ) ) {
			return PaymentMethodData::MODE_UNKNOWN;
		}

		return 'yes' === $settings['enable_sandbox'] ? PaymentMethodData::MODE_TEST : PaymentMethodData::MODE_LIVE;
	}
}
