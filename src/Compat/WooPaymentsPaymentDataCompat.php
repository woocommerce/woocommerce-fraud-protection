<?php
/**
 * WooPaymentsPaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves WooPayments transaction mode into PaymentMethodData.
 *
 * WooPayments does not expose structured card data via its payment_data
 * keys (the pm_ ID is platform-scoped), so this compat only resolves
 * the test/live transaction mode based on gateway settings.
 *
 * @internal
 */
class WooPaymentsPaymentDataCompat {

	/**
	 * Main WooPayments gateway ID.
	 *
	 * @var string
	 */
	private const GATEWAY_ID = 'woocommerce_payments';

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
	 * Resolve WooPayments payment data.
	 *
	 * @param PaymentMethodData $resolved Previously resolved data.
	 * @return PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( PaymentMethodData $resolved ): PaymentMethodData {
		if ( ! $this->is_woopayments_gateway( $resolved->get_gateway() ) ) {
			return $resolved;
		}

		return $resolved->with_transaction_mode( $this->resolve_transaction_mode() );
	}

	/**
	 * Resolve the WooPayments transaction mode from settings.
	 *
	 * @return string MODE_TEST, MODE_LIVE, or MODE_UNKNOWN if settings are unavailable.
	 */
	private function resolve_transaction_mode(): string {
		if ( defined( 'WCPAY_DEV_MODE' ) && WCPAY_DEV_MODE ) {
			return PaymentMethodData::MODE_TEST;
		}

		$settings = get_option( 'woocommerce_woocommerce_payments_settings' );

		if ( ! is_array( $settings ) || ! isset( $settings['test_mode'] ) ) {
			return PaymentMethodData::MODE_UNKNOWN;
		}

		return 'yes' === $settings['test_mode'] ? PaymentMethodData::MODE_TEST : PaymentMethodData::MODE_LIVE;
	}

	/**
	 * Check if the payment method belongs to WooPayments.
	 *
	 * Matches 'woocommerce_payments' and 'woocommerce_payments_*'
	 * (e.g. woocommerce_payments_bancontact).
	 *
	 * @param string $payment_method The gateway ID.
	 * @return bool
	 */
	private function is_woopayments_gateway( string $payment_method ): bool {
		return self::GATEWAY_ID === $payment_method
			|| 0 === strpos( $payment_method, self::GATEWAY_ID . '_' );
	}
}
