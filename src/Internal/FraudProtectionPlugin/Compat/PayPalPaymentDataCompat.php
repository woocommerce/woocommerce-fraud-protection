<?php
/**
 * PayPalPaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the PayPal Payments merchant identifier and transaction mode into PaymentMethodData.
 *
 * This compat resolves saved wallet data and the PayPal merchant identifier and transaction mode.
 */
class PayPalPaymentDataCompat {

	/**
	 * Gateway ID prefix shared by all PayPal Payments gateways.
	 *
	 * @var string
	 */
	private const GATEWAY_PREFIX = 'ppcp-';

	/**
	 * Wallet names resolved from dedicated PayPal Payments gateways.
	 *
	 * @var array<string, string>
	 */
	private const GATEWAY_WALLET_MAP = array(
		'ppcp-applepay'  => 'apple_pay',
		'ppcp-googlepay' => 'google_pay',
	);

	/**
	 * Wallet names accepted from PayPal create-order data.
	 *
	 * @var array<string, string>
	 */
	private const FUNDING_SOURCE_WALLET_MAP = array(
		'paypal'    => 'paypal',
		'venmo'     => 'venmo',
		'apple_pay' => 'apple_pay',
		'googlepay' => 'google_pay',
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
	 * Resolve PayPal payment data.
	 *
	 * @internal
	 *
	 * @param PaymentMethodData $resolved               Previously resolved data.
	 * @param array             $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( PaymentMethodData $resolved, array $checkout_payment_fields = array() ): PaymentMethodData {
		if ( ! $this->is_paypal_gateway( $resolved->get_gateway() ) ) {
			return $resolved;
		}

		$transaction_mode    = $this->resolve_transaction_mode();
		$merchant_identifier = $this->resolve_merchant_identifier();
		$token               = $this->resolve_saved_token( $resolved->get_gateway(), $checkout_payment_fields );
		$existing_wallet     = $this->get_wallet( $resolved );
		$token_wallet        = null;

		if ( null !== $token ) {
			$payment_type = null;
			$instrument   = null;
			$token_type   = $token->get_type();

			// Card tokens are resolved by PaymentDataResolver; this switch handles PayPal wallet tokens.
			switch ( is_string( $token_type ) ? strtolower( $token_type ) : '' ) {
				case 'paypal':
					$payment_type = 'paypal';
					$instrument   = $this->resolve_payer_email( $token );
					$token_wallet = 'paypal';
					break;
				case 'venmo':
					$payment_type = 'venmo';
					$instrument   = $this->resolve_payer_email( $token );
					$token_wallet = 'venmo';
					break;
				case 'applepay':
					$payment_type = 'card';
					$instrument   = PaymentInstrumentData::from_array( array( 'wallet' => 'apple_pay' ) );
					$token_wallet = 'apple_pay';
					break;
			}

			if ( null !== $payment_type ) {
				$resolved = new PaymentMethodData(
					$resolved->get_gateway(),
					$payment_type,
					true,
					$instrument
				);
			}
		}

		$gateway_wallet = self::GATEWAY_WALLET_MAP[ $resolved->get_gateway() ] ?? null;
		$funding_source = $checkout_payment_fields['funding_source'] ?? null;
		$request_wallet = is_string( $funding_source ) ? ( self::FUNDING_SOURCE_WALLET_MAP[ strtolower( $funding_source ) ] ?? null ) : null;
		$resolved       = $this->with_wallet_if_empty( $resolved, $existing_wallet ?? $token_wallet ?? $gateway_wallet ?? $request_wallet );

		return $resolved
			->with_transaction_mode( $transaction_mode )
			->with_merchant_identifier( $merchant_identifier, 'account' );
	}

	/**
	 * Add a wallet when the current payment data has none.
	 *
	 * @param PaymentMethodData $resolved Resolved payment data.
	 * @param ?string           $wallet   Normalized wallet value.
	 * @return PaymentMethodData
	 */
	private function with_wallet_if_empty( PaymentMethodData $resolved, ?string $wallet ): PaymentMethodData {
		return null !== $wallet && null === $this->get_wallet( $resolved )
			? $resolved->with_instrument_wallet( $wallet )
			: $resolved;
	}

	/**
	 * Read a non-empty wallet from resolved payment data.
	 *
	 * @param PaymentMethodData $resolved Resolved payment data.
	 * @return ?string Current wallet value.
	 */
	private function get_wallet( PaymentMethodData $resolved ): ?string {
		$wallet = $resolved->to_array()['instrument']['wallet'] ?? null;

		return is_string( $wallet ) && '' !== $wallet ? $wallet : null;
	}

	/**
	 * Resolve a valid saved PayPal token selected for the active gateway.
	 *
	 * @param string               $gateway                  Active gateway ID.
	 * @param array<string, mixed> $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return ?\WC_Payment_Token The token, if valid and owned by the current customer.
	 */
	private function resolve_saved_token( string $gateway, array $checkout_payment_fields ): ?\WC_Payment_Token {
		$token_value = $checkout_payment_fields[ 'wc-' . $gateway . '-payment-token' ] ?? null;

		if ( is_int( $token_value ) ) {
			$token_id = $token_value;
		} elseif ( is_string( $token_value ) && preg_match( '/^[1-9][0-9]*$/D', $token_value ) ) {
			$token_id = (int) $token_value;
		} else {
			return null;
		}

		if ( $token_id < 1 ) {
			return null;
		}

		try {
			$token = \WC_Payment_Tokens::get( $token_id );

			if ( ! $token instanceof \WC_Payment_Token
				|| $gateway !== $token->get_gateway_id()
				|| get_current_user_id() !== $token->get_user_id() ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return $token;
	}

	/**
	 * Resolve the payer email from a PayPal Payments wallet token.
	 *
	 * @param \WC_Payment_Token $token PayPal Payments wallet token.
	 * @return PaymentInstrumentData Instrument data, empty when the email is unavailable.
	 */
	private function resolve_payer_email( \WC_Payment_Token $token ): PaymentInstrumentData {
		if ( ! method_exists( $token, 'get_email' ) ) {
			return PaymentInstrumentData::empty();
		}

		try {
			$email = $token->get_email();

			return is_string( $email ) && is_email( $email )
				? PaymentInstrumentData::from_array( array( 'payer_email' => $email ) )
				: PaymentInstrumentData::empty();
		} catch ( \Throwable $e ) {
			return PaymentInstrumentData::empty();
		}
	}

	/**
	 * Resolve the PayPal merchant identifier.
	 *
	 * @return ?string The merchant identifier, if available.
	 */
	private function resolve_merchant_identifier(): ?string {
		if ( ! class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) ) {
			return null;
		}

		try {
			$merchant_identifier = \WooCommerce\PayPalCommerce\PPCP::container()->get( 'api.merchant_id' );

			return is_string( $merchant_identifier ) && '' !== trim( $merchant_identifier )
				? trim( $merchant_identifier )
				: null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Resolve the PayPal transaction mode.
	 *
	 * Uses the PayPal Payments ConnectionState API when available, which is the
	 * same method PayPal uses internally to select API endpoints (sandbox vs
	 * production). Falls back to PaymentMode::Unknown when the gateway is unavailable
	 * or the merchant is not connected.
	 *
	 * @return PaymentMode The transaction mode (Unknown if the gateway is unavailable).
	 */
	private function resolve_transaction_mode(): PaymentMode {
		if ( ! class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) ) {
			return PaymentMode::Unknown;
		}

		try {
			$connection_state = \WooCommerce\PayPalCommerce\PPCP::container()->get( 'settings.connection-state' );

			if ( $connection_state->is_production() ) {
				return PaymentMode::Live;
			}

			// Not production: either sandbox (test) or not connected (unknown).
			return $connection_state->is_sandbox() ? PaymentMode::Test : PaymentMode::Unknown;
		} catch ( \Throwable $e ) {
			return PaymentMode::Unknown;
		}
	}

	/**
	 * Check if the payment method belongs to PayPal Payments.
	 *
	 * @param string $payment_method The gateway ID.
	 * @return bool
	 */
	private function is_paypal_gateway( string $payment_method ): bool {
		return str_starts_with( $payment_method, self::GATEWAY_PREFIX );
	}
}
