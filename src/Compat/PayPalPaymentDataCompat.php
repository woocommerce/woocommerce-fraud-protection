<?php
/**
 * PayPalPaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves PayPal Payments transaction mode into PaymentMethodData.
 *
 * PayPal does not expose structured card/instrument data, so this compat
 * only resolves the test/live transaction mode based on the sandbox_on
 * setting.
 *
 * @internal
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
	 * Resolve the PayPal transaction mode from settings.
	 *
	 * PayPal Payments has two settings formats:
	 * - New (v3+): woocommerce-ppcp-data-common['use_sandbox'] (boolean).
	 * - Legacy: woocommerce-ppcp-settings['sandbox_on'] ('1'/'' truthy/falsy).
	 *
	 * @return string MODE_TEST, MODE_LIVE, or MODE_UNKNOWN if settings are unavailable.
	 */
	private function resolve_transaction_mode(): string {
		// New settings format (PayPal Payments v3+).
		$common = get_option( 'woocommerce-ppcp-data-common' );
		if ( is_array( $common ) && array_key_exists( 'use_sandbox', $common ) ) {
			return ! empty( $common['use_sandbox'] ) ? PaymentMethodData::MODE_TEST : PaymentMethodData::MODE_LIVE;
		}

		// Legacy settings format — sandbox_on stored as '1'/'' (truthy/falsy), not 'yes'/'no'.
		$settings = get_option( 'woocommerce-ppcp-settings' );
		if ( is_array( $settings ) && array_key_exists( 'sandbox_on', $settings ) ) {
			return ! empty( $settings['sandbox_on'] ) ? PaymentMethodData::MODE_TEST : PaymentMethodData::MODE_LIVE;
		}

		return PaymentMethodData::MODE_UNKNOWN;
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
