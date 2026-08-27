<?php
/**
 * StripePaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Stripe gateway payment data into structured PaymentMethodData.
 *
 * Handles multiple Stripe gateway IDs (stripe, stripe_sepa, stripe_ideal, etc.)
 * and uses the Stripe API to retrieve payment method details.
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
		add_filter( 'woocommerce_fraud_protection_resolved_payment_data', array( $this, 'resolve' ), 10, 2 );
	}

	/**
	 * Resolve Stripe payment data.
	 *
	 * @internal
	 *
	 * @param PaymentMethodData $resolved               Previously resolved data.
	 * @param array             $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( PaymentMethodData $resolved, array $checkout_payment_fields ): PaymentMethodData {
		if ( ! $this->is_stripe_gateway( $resolved->get_gateway() ) ) {
			return $resolved;
		}

		$transaction_mode    = $this->resolve_transaction_mode();
		$merchant_identifier = $this->resolve_merchant_identifier();

		$pm_id = $checkout_payment_fields['wc-stripe-payment-method'] ?? ( $checkout_payment_fields['stripe_source'] ?? '' );
		if ( empty( $pm_id ) ) {
			return $resolved
				->with_transaction_mode( $transaction_mode )
				->with_merchant_identifier( $merchant_identifier, 'account' );
		}

		$token_value = $checkout_payment_fields['wc-stripe-payment-token'] ?? '';
		$is_saved    = ! empty( $token_value ) && 'new' !== $token_value;

		if ( ! class_exists( '\WC_Stripe_API' ) ) {
			return $resolved
				->with_transaction_mode( $transaction_mode )
				->with_merchant_identifier( $merchant_identifier, 'account' );
		}

		$pm_details = \WC_Stripe_API::get_payment_method( $pm_id );

		if ( is_wp_error( $pm_details ) || ! is_object( $pm_details ) || ! isset( $pm_details->type ) ) {
			return $resolved
				->with_transaction_mode( $transaction_mode )
				->with_merchant_identifier( $merchant_identifier, 'account' );
		}

		if ( 'card' !== $pm_details->type || ! isset( $pm_details->card ) ) {
			return new PaymentMethodData(
				$resolved->get_gateway(),
				$pm_details->type,
				$is_saved,
				null,
				$transaction_mode,
				$merchant_identifier,
				'account'
			);
		}

		$postcode = $pm_details->billing_details->address->postal_code ?? null;

		return new PaymentMethodData(
			$resolved->get_gateway(),
			'card',
			$is_saved,
			PaymentInstrumentData::from_array(
				array(
					'brand'            => $pm_details->card->brand ?? null,
					'funding'          => $pm_details->card->funding ?? null,
					'last4'            => $pm_details->card->last4 ?? null,
					'fingerprint'      => $pm_details->card->fingerprint ?? null,
					'country'          => $pm_details->card->country ?? null,
					'exp_month'        => $pm_details->card->exp_month ?? null,
					'exp_year'         => $pm_details->card->exp_year ?? null,
					'billing_postcode' => $postcode,
				)
			),
			$transaction_mode,
			$merchant_identifier,
			'account'
		);
	}

	/**
	 * Resolve the Stripe account identifier.
	 *
	 * @return ?string The account identifier, if available.
	 */
	private function resolve_merchant_identifier(): ?string {
		if ( ! class_exists( '\WC_Stripe' ) ) {
			return null;
		}

		try {
			$account_data = \WC_Stripe::get_instance()->account->get_cached_account_data();
			$identifier   = is_array( $account_data ) ? ( $account_data['id'] ?? null ) : null;

			return is_string( $identifier ) && '' !== trim( $identifier ) ? trim( $identifier ) : null;
		} catch ( \Throwable $e ) {
			return null;
		}
	}

	/**
	 * Resolve the Stripe transaction mode.
	 *
	 * Uses the Stripe gateway's own mode API when available, which is the same
	 * method Stripe uses internally to select API keys during payment processing.
	 *
	 * @return PaymentMode The transaction mode (Unknown if the gateway is unavailable).
	 */
	private function resolve_transaction_mode(): PaymentMode {
		if ( ! class_exists( '\WC_Stripe_Mode' ) ) {
			return PaymentMode::Unknown;
		}

		try {
			return \WC_Stripe_Mode::is_live() ? PaymentMode::Live : PaymentMode::Test;
		} catch ( \Throwable $e ) {
			return PaymentMode::Unknown;
		}
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
			|| str_starts_with( $payment_method, self::GATEWAY_PREFIX . '_' );
	}
}
