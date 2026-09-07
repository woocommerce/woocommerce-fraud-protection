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
 * PayPal does not expose structured card/instrument data, so this compat
 * resolves the merchant identifier, test/live transaction mode, and saved payer email.
 */
class PayPalPaymentDataCompat {

	/**
	 * Gateway ID prefix shared by all PayPal Payments gateways.
	 *
	 * @var string
	 */
	private const GATEWAY_PREFIX = 'ppcp-';

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

		if ( null !== $token ) {
			$instrument = PaymentInstrumentData::empty();

			if ( method_exists( $token, 'get_email' ) ) {
				try {
					$email = $token->get_email();

					if ( is_string( $email ) && is_email( $email ) ) {
						$instrument = PaymentInstrumentData::from_array( array( 'payer_email' => $email ) );
					}
				} catch ( \Throwable $e ) {
					// Saved state remains valid when the optional email is unavailable.
					$instrument = PaymentInstrumentData::empty();
				}
			}

			return new PaymentMethodData(
				$resolved->get_gateway(),
				'paypal',
				true,
				$instrument,
				$transaction_mode,
				$merchant_identifier,
				'account'
			);
		}

		return $resolved
			->with_transaction_mode( $transaction_mode )
			->with_merchant_identifier( $merchant_identifier, 'account' );
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
				|| 'PayPal' !== $token->get_type()
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
