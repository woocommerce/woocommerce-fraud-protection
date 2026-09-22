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
	 * Cash App Pay gateway ID.
	 *
	 * @var string
	 */
	private const CASH_APP_PAY_GATEWAY_ID = 'square_cash_app_pay';

	/**
	 * Wallet names accepted from Square payment data.
	 *
	 * @var array<string, string>
	 */
	private const WALLET_MAP = array(
		'apple pay'  => 'apple_pay',
		'google pay' => 'google_pay',
	);

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
		$gateway = $resolved->get_gateway();

		if ( 'square_credit_card' !== $gateway && self::CASH_APP_PAY_GATEWAY_ID !== $gateway ) {
			return $resolved;
		}

		$transaction_mode    = $this->resolve_transaction_mode();
		$merchant_identifier = $this->resolve_merchant_identifier();

		if ( self::CASH_APP_PAY_GATEWAY_ID === $gateway ) {
			$result = $resolved
				->with_transaction_mode( $transaction_mode )
				->with_merchant_identifier( $merchant_identifier, 'location' );

			return $result->with_instrument_wallet_if_empty( 'cash_app_pay' );
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
		$wallet      = $this->normalize_wallet( $checkout_payment_fields['wc-square-digital-wallet-type'] ?? null );

		// Saved cards have empty card keys — pass through the token-based data.
		if ( empty( $brand ) && empty( $last4 ) ) {
			$result = $resolved
				->with_transaction_mode( $transaction_mode )
				->with_merchant_identifier( $merchant_identifier, 'location' );

			return $result->with_instrument_wallet_if_empty( $wallet );
		}

		$result = new PaymentMethodData(
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
			$transaction_mode,
			$merchant_identifier,
			'location'
		);

		return $result->with_instrument_wallet_if_empty( $resolved->get_instrument_wallet() ?? $wallet );
	}

	/**
	 * Normalize a supported Square wallet value.
	 *
	 * @param mixed $wallet Raw wallet value.
	 * @return ?string Normalized wallet value.
	 */
	private function normalize_wallet( $wallet ): ?string {
		return is_string( $wallet ) ? ( self::WALLET_MAP[ strtolower( $wallet ) ] ?? null ) : null;
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
