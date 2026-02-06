<?php
/**
 * StripePaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Compat;

use Automattic\WooCommerce\Internal\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Stripe gateway payment data into structured PaymentMethodData.
 *
 * Handles multiple Stripe gateway IDs (stripe, stripe_sepa, stripe_ideal, etc.)
 * and uses the Stripe API to retrieve payment method details.
 *
 * @internal
 */
class StripePaymentDataCompat {

	/**
	 * Stripe gateway ID prefixes.
	 *
	 * @var string
	 */
	private const GATEWAY_PREFIX = 'stripe';

	/**
	 * Register the filter callback.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve' ), 10, 3 );
	}

	/**
	 * Resolve Stripe payment data.
	 *
	 * @param ?PaymentMethodData $resolved       Previously resolved data.
	 * @param string             $payment_method The gateway ID.
	 * @param array              $payment_data   Normalized key-value payment data.
	 * @return ?PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( ?PaymentMethodData $resolved, string $payment_method, array $payment_data ): ?PaymentMethodData {
		if ( ! $this->is_stripe_gateway( $payment_method ) ) {
			return $resolved;
		}

		$pm_id = $payment_data['wc-stripe-payment-method'] ?? ( $payment_data['stripe_source'] ?? '' );
		if ( empty( $pm_id ) ) {
			return $resolved;
		}

		$is_saved = ! empty( $payment_data['wc-stripe-payment-token'] ?? '' );

		if ( ! class_exists( '\WC_Stripe_API' ) ) {
			return $resolved;
		}

		$pm_details = \WC_Stripe_API::get_payment_method( $pm_id );

		if ( ! is_object( $pm_details ) || ! isset( $pm_details->type ) ) {
			return $resolved;
		}

		if ( 'card' !== $pm_details->type || ! isset( $pm_details->card ) ) {
			return new PaymentMethodData(
				$payment_method,
				$pm_details->type,
				$is_saved
			);
		}

		$postcode = $pm_details->billing_details->address->postal_code ?? null;

		return new PaymentMethodData(
			$payment_method,
			'card',
			$is_saved,
			new CardPaymentMethodData(
				$pm_details->card->brand ?? null,
				$pm_details->card->funding ?? null,
				$pm_details->card->last4 ?? null,
				$pm_details->card->fingerprint ?? null,
				$pm_details->card->country ?? null,
				isset( $pm_details->card->exp_month ) ? (int) $pm_details->card->exp_month : null,
				isset( $pm_details->card->exp_year ) ? (int) $pm_details->card->exp_year : null,
				$postcode
			)
		);
	}

	/**
	 * Check if the payment method is a Stripe gateway.
	 *
	 * Matches 'stripe' and 'stripe_*' (e.g. stripe_sepa, stripe_ideal).
	 *
	 * @param string $payment_method The gateway ID.
	 * @return bool
	 */
	private function is_stripe_gateway( string $payment_method ): bool {
		return self::GATEWAY_PREFIX === $payment_method
			|| 0 === strpos( $payment_method, self::GATEWAY_PREFIX . '_' );
	}
}
