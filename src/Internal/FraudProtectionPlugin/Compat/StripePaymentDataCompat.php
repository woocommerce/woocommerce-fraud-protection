<?php
/**
 * StripePaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\FraudProtectionFeature;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves Stripe gateway payment data into structured PaymentMethodData.
 *
 * Handles multiple Stripe gateway IDs (stripe, stripe_sepa, stripe_ideal, etc.)
 * and uses the Stripe API to retrieve payment method details. On an
 * add-payment-method form post, the details are those of the payment method
 * that the posted setup intent holds. Stands aside once the gateway declares
 * `fraud_protection_payment_data`.
 */
class StripePaymentDataCompat {

	use StripeSetupIntentReaderTrait;

	/**
	 * Stripe gateway ID prefix, which is also the main gateway ID.
	 *
	 * @var string
	 */
	private const GATEWAY_PREFIX = 'stripe';

	/**
	 * Add-payment-method form field with the setup intent ID.
	 *
	 * @var string
	 */
	private const SETUP_INTENT_FIELD = 'wc-stripe-setup-intent';

	/**
	 * Filter applied by WooCommerce before the gateway saves an add-payment-method form post.
	 *
	 * @var string
	 */
	private const ADD_PAYMENT_METHOD_FORM_FILTER = 'woocommerce_add_payment_method_form_is_valid';

	/**
	 * Native gateway support lookup.
	 *
	 * @var GatewayNativeSupport
	 */
	private GatewayNativeSupport $native_support;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param GatewayNativeSupport $native_support Native gateway support lookup.
	 */
	final public function init( GatewayNativeSupport $native_support ): void {
		$this->native_support = $native_support;
	}

	/**
	 * Wallet names accepted from Stripe request and provider data.
	 *
	 * @var array<string, string>
	 */
	private const WALLET_MAP = array(
		'apple_pay'  => 'apple_pay',
		'google_pay' => 'google_pay',
		'amazon_pay' => 'amazon_pay',
		'paypal'     => 'paypal',
		'link'       => 'link',
		'cashapp'    => 'cash_app_pay',
		'wechat_pay' => 'wechat_pay',
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

		if ( $this->native_support->declared( FraudProtectionFeature::PAYMENT_DATA, $resolved->get_gateway(), self::GATEWAY_PREFIX ) ) {
			return $resolved;
		}

		$transaction_mode    = $this->resolve_transaction_mode();
		$merchant_identifier = $this->resolve_merchant_identifier();
		$request_wallet      = $this->normalize_wallet( $checkout_payment_fields['express_payment_type'] ?? null );
		$resolved            = $resolved
			->with_transaction_mode( $transaction_mode )
			->with_merchant_identifier( $merchant_identifier, 'account' );

		$pm_id = $this->resolve_setup_intent_payment_method_id( $checkout_payment_fields )
			?? $checkout_payment_fields['wc-stripe-payment-method']
			?? ( $checkout_payment_fields['stripe_source'] ?? '' );
		if ( empty( $pm_id ) ) {
			return $resolved->with_instrument_wallet_if_empty( $request_wallet );
		}

		$token_value = $checkout_payment_fields['wc-stripe-payment-token'] ?? '';
		$is_saved    = ! empty( $token_value ) && 'new' !== $token_value;

		if ( ! class_exists( '\WC_Stripe_API' ) ) {
			return $resolved->with_instrument_wallet_if_empty( $request_wallet );
		}

		$pm_details = \WC_Stripe_API::get_payment_method( $pm_id );

		if ( is_wp_error( $pm_details ) || ! is_object( $pm_details ) || ! isset( $pm_details->type ) ) {
			return $resolved->with_instrument_wallet_if_empty( $request_wallet );
		}

		$existing_wallet = $resolved->get_instrument_wallet();
		$provider_wallet = $this->normalize_wallet( $pm_details->type );

		if ( 'card' !== $pm_details->type || ! isset( $pm_details->card ) ) {
			$result = new PaymentMethodData(
				$resolved->get_gateway(),
				$pm_details->type,
				$is_saved,
				null,
				$transaction_mode,
				$merchant_identifier,
				'account'
			);

			return $result->with_instrument_wallet_if_empty( $existing_wallet ?? $provider_wallet ?? $request_wallet );
		}

		$postcode        = $pm_details->billing_details->address->postal_code ?? null;
		$provider_wallet = $this->normalize_provider_wallet( $pm_details->card->wallet->type ?? null );

		$result = new PaymentMethodData(
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

		return $result->with_instrument_wallet_if_empty( $existing_wallet ?? $provider_wallet ?? $request_wallet );
	}

	/**
	 * Resolve the payment method ID of the posted Stripe setup intent.
	 *
	 * On My Account > Add payment method, Stripe saves the payment method of
	 * the posted setup intent instead of the posted payment method ID, and the
	 * browser can change it after the setup intent request.
	 *
	 * Stripe reads the setup intent only on that form post, right after
	 * WooCommerce applies `woocommerce_add_payment_method_form_is_valid`. In any
	 * other request, Stripe processes the posted payment method ID, so a posted
	 * setup intent is ignored.
	 *
	 * @param array $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return ?string The payment method ID, or null when no setup intent applies or it cannot be read.
	 */
	private function resolve_setup_intent_payment_method_id( array $checkout_payment_fields ): ?string {
		if ( ! doing_filter( self::ADD_PAYMENT_METHOD_FORM_FILTER ) ) {
			return null;
		}

		$setup_intent_id = $checkout_payment_fields[ self::SETUP_INTENT_FIELD ] ?? null;
		if ( ! is_string( $setup_intent_id ) ) {
			return null;
		}

		try {
			return $this->get_setup_intent_payment_method_id( $setup_intent_id );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Reading the Stripe setup intent failed; resolving the posted payment method',
				array(
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
				)
			);

			return null;
		}
	}

	/**
	 * Normalize a supported Stripe wallet value.
	 *
	 * @param mixed $wallet Raw wallet value.
	 * @return ?string Normalized wallet value.
	 */
	private function normalize_wallet( $wallet ): ?string {
		return is_string( $wallet ) ? ( self::WALLET_MAP[ strtolower( $wallet ) ] ?? null ) : null;
	}

	/**
	 * Normalize a Stripe provider wallet and preserve new provider values.
	 *
	 * @param mixed $wallet Raw provider wallet value.
	 * @return ?string Wallet value.
	 */
	private function normalize_provider_wallet( $wallet ): ?string {
		if ( ! is_string( $wallet ) || '' === $wallet ) {
			return null;
		}

		return $this->normalize_wallet( $wallet ) ?? $wallet;
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
