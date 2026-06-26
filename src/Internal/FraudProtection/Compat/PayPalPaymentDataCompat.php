<?php
/**
 * PayPalPaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Compat;

use Automattic\WooCommerce\Internal\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\PaymentMethodData;

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
		if ( ! FraudProtectionController::feature_is_enabled() ) {
			return;
		}

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

		return $resolved->with_transaction_mode( $this->resolve_transaction_mode() );
	}

	/**
	 * Resolve the PayPal transaction mode.
	 *
	 * Uses the PayPal Payments ConnectionState API when available, which is the
	 * same method PayPal uses internally to select API endpoints (sandbox vs
	 * production). Falls back to MODE_UNKNOWN when the gateway is unavailable
	 * or the merchant is not connected.
	 *
	 * @return string MODE_TEST, MODE_LIVE, or MODE_UNKNOWN if the gateway is unavailable.
	 */
	private function resolve_transaction_mode(): string {
		if ( ! class_exists( '\WooCommerce\PayPalCommerce\PPCP' ) ) {
			return PaymentMethodData::MODE_UNKNOWN;
		}

		try {
			$connection_state = \WooCommerce\PayPalCommerce\PPCP::container()->get( 'settings.connection-state' );

			if ( $connection_state->is_production() ) {
				return PaymentMethodData::MODE_LIVE;
			}

			// Not production: either sandbox (test) or not connected (unknown).
			return $connection_state->is_sandbox() ? PaymentMethodData::MODE_TEST : PaymentMethodData::MODE_UNKNOWN;
		} catch ( \Throwable $e ) {
			return PaymentMethodData::MODE_UNKNOWN;
		}
	}

	/**
	 * Check if the payment method belongs to PayPal Payments.
	 *
	 * @param string $payment_method The gateway ID.
	 * @return bool
	 */
	private function is_paypal_gateway( string $payment_method ): bool {
		return 0 === strpos( $payment_method, self::GATEWAY_PREFIX );
	}
}
