<?php
/**
 * PayPalPaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves PayPal Payments transaction mode into PaymentMethodData.
 *
 * PayPal does not expose structured card/instrument data, so this compat
 * only resolves the test/live transaction mode based on the gateway's
 * ConnectionState API.
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
		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve' ), 10 );
	}

	/**
	 * Resolve PayPal payment data.
	 *
	 * @internal
	 *
	 * @param PaymentMethodData $resolved Previously resolved data.
	 * @return PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( PaymentMethodData $resolved ): PaymentMethodData {
		if ( ! $this->is_paypal_gateway( $resolved->get_gateway() ) ) {
			return $resolved;
		}

		$merchant_identifier = $this->resolve_merchant_identifier();
		$result              = $resolved->with_transaction_mode( $this->resolve_transaction_mode() );

		return null !== $merchant_identifier
			? $result->with_merchant_identifier( $merchant_identifier, 'account' )
			: $result;
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
