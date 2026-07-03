<?php
/**
 * WooPaymentsPaymentDataCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\CheckResult;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentMode;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves WooPayments payment data into structured PaymentMethodData.
 *
 * Handles the main gateway ('woocommerce_payments') and APM gateways
 * ('woocommerce_payments_*'). When WooPay is disabled, resolves full
 * payment details (card data, bank data, payment type) via the
 * WooPayments API. When WooPay is enabled, only transaction mode is
 * resolved because pm_ IDs are platform-scoped and unresolvable.
 */
class WooPaymentsPaymentDataCompat {

	/**
	 * Main WooPayments gateway ID.
	 *
	 * @var string
	 */
	private const GATEWAY_ID = 'woocommerce_payments';

	/**
	 * Map Stripe verification check values to normalized CheckResult cases.
	 *
	 * @var array<string, CheckResult>
	 */
	private const CHECK_MAP = array(
		'pass'        => CheckResult::Pass,
		'fail'        => CheckResult::Fail,
		'unavailable' => CheckResult::Unavailable,
		'unchecked'   => CheckResult::Unchecked,
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
	 * Resolve WooPayments payment data.
	 *
	 * @internal
	 *
	 * @param PaymentMethodData $resolved               Previously resolved data.
	 * @param array             $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return PaymentMethodData Resolved data, or pass-through.
	 */
	public function resolve( PaymentMethodData $resolved, array $checkout_payment_fields ): PaymentMethodData {
		if ( ! $this->is_woopayments_gateway( $resolved->get_gateway() ) ) {
			return $resolved;
		}

		if ( ! class_exists( '\WC_Payments' ) ) {
			return $resolved;
		}

		$transaction_mode = $this->resolve_transaction_mode();

		// When WooPay is enabled, pm_ IDs are platform-scoped and cannot be
		// resolved through the connected account API. Only resolve mode.
		if ( $this->is_woopay_enabled() ) {
			return $resolved->with_transaction_mode( $transaction_mode );
		}

		$token_key   = 'wc-' . $resolved->get_gateway() . '-payment-token';
		$token_value = $checkout_payment_fields[ $token_key ] ?? '';
		$is_saved    = ! empty( $token_value ) && 'new' !== $token_value;

		$pm_id = $this->extract_payment_method_id( $checkout_payment_fields );

		// For saved payment methods the checkout sends a WC token ID but no
		// wcpay-payment-method key. Resolve the Stripe pm_ ID from the token
		// so we can call the API for full instrument data.
		if ( empty( $pm_id ) && $is_saved ) {
			$pm_id = $this->resolve_pm_id_from_token( (int) $token_value );
		}

		if ( empty( $pm_id ) ) {
			return $resolved->with_transaction_mode( $transaction_mode );
		}

		$api_client = \WC_Payments::get_payments_api_client();
		if ( null === $api_client ) {
			return $resolved->with_transaction_mode( $transaction_mode );
		}

		try {
			$pm_details = $api_client->get_payment_method( $pm_id );
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				sprintf(
					'WooPaymentsPaymentDataCompat: Failed to resolve payment method %s — %s',
					$pm_id,
					$e->getMessage()
				)
			);
			return $resolved->with_transaction_mode( $transaction_mode );
		}

		if ( ! isset( $pm_details['type'] ) ) {
			return $resolved->with_transaction_mode( $transaction_mode );
		}

		$type       = $pm_details['type'];
		$instrument = $this->build_instrument( $type, $pm_details );

		return new PaymentMethodData(
			$resolved->get_gateway(),
			$type,
			$is_saved,
			$instrument,
			$transaction_mode
		);
	}

	/**
	 * Build a PaymentInstrumentData from the API response.
	 *
	 * Indexes into the Stripe PaymentMethod response (as array, via the
	 * WooPayments API proxy) using $pm_details[$type] — the Stripe API always
	 * includes a key matching the type value containing the type-specific hash.
	 * Extracts all applicable fields and returns null if none are populated
	 * (e.g. BNPL types with empty hashes).
	 *
	 * @param string $type       Payment type from the API response.
	 * @param array  $pm_details Full API response.
	 * @return PaymentInstrumentData Instrument data (empty when no fields populated).
	 */
	private function build_instrument( string $type, array $pm_details ): PaymentInstrumentData {
		$type_data = $pm_details[ $type ] ?? array();

		if ( ! is_array( $type_data ) ) {
			return PaymentInstrumentData::empty();
		}

		$brand       = $type_data['brand'] ?? null;
		$funding     = $type_data['funding'] ?? null;
		$last4       = $type_data['last4'] ?? null;
		$fingerprint = $type_data['fingerprint'] ?? null;
		$country     = $type_data['country'] ?? null;
		$exp_month   = isset( $type_data['exp_month'] ) ? (int) $type_data['exp_month'] : null;
		$exp_year    = isset( $type_data['exp_year'] ) ? (int) $type_data['exp_year'] : null;
		$postcode    = $pm_details['billing_details']['address']['postal_code'] ?? null;
		$wallet      = is_array( $type_data['wallet'] ?? null ) ? ( $type_data['wallet']['type'] ?? null ) : null;

		// Stripe uses 'bank_code' for SEPA, 'bsb_number' for BECS,
		// 'routing_number' for US bank, and 'bic' for iDEAL/Bancontact.
		$bank_code = $type_data['bank_code']
			?? $type_data['bsb_number']
			?? $type_data['routing_number']
			?? $type_data['bic']
			?? null;

		// 'iin' (BIN) is undocumented but present in Stripe PaymentMethod responses.
		$bin                = $type_data['iin'] ?? null;
		$checks             = is_array( $type_data['checks'] ?? null ) ? $type_data['checks'] : array();
		$cvc_check          = self::CHECK_MAP[ $checks['cvc_check'] ?? '' ] ?? null;
		$avs_address_check  = self::CHECK_MAP[ $checks['address_line1_check'] ?? '' ] ?? null;
		$avs_postcode_check = self::CHECK_MAP[ $checks['address_postal_code_check'] ?? '' ] ?? null;

		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand'              => $brand,
				'funding'            => $funding,
				'last4'              => $last4,
				'fingerprint'        => $fingerprint,
				'country'            => $country,
				'exp_month'          => $exp_month,
				'exp_year'           => $exp_year,
				'billing_postcode'   => $postcode,
				'wallet'             => $wallet,
				'bank_code'          => $bank_code,
				'bin'                => $bin,
				'cvc_check'          => $cvc_check,
				'avs_address_check'  => $avs_address_check,
				'avs_postcode_check' => $avs_postcode_check,
			)
		);

		return $instrument;
	}

	/**
	 * Resolve the WooPayments transaction mode.
	 *
	 * Uses the WooPayments Mode API when available, which is the same method
	 * WooPayments uses to select API keys and store mode in order metadata.
	 * This also covers dev mode, onboarding test mode, and filter overrides.
	 *
	 * @return PaymentMode The transaction mode (Unknown if the gateway is unavailable).
	 */
	private function resolve_transaction_mode(): PaymentMode {
		if ( ! class_exists( '\WC_Payments' ) ) {
			return PaymentMode::Unknown;
		}

		try {
			$mode = \WC_Payments::mode();

			if ( null === $mode ) {
				return PaymentMode::Unknown;
			}

			return $mode->is_live() ? PaymentMode::Live : PaymentMode::Test;
		} catch ( \Throwable $e ) {
			return PaymentMode::Unknown;
		}
	}

	/**
	 * Resolve a Stripe pm_ ID from a WC payment token.
	 *
	 * For saved payment methods, WooPayments sends only a WC token ID in the
	 * checkout payload with no wcpay-payment-method key.
	 *
	 * @param int $token_id WC payment token ID.
	 * @return string Stripe payment method ID, or empty string.
	 */
	private function resolve_pm_id_from_token( int $token_id ): string {
		$wc_token = \WC_Payment_Tokens::get( $token_id );

		if ( ! $wc_token ) {
			return '';
		}

		if ( $wc_token->get_user_id() !== get_current_user_id() ) {
			return '';
		}

		if ( ! $this->is_woopayments_gateway( $wc_token->get_gateway_id() ) ) {
			return '';
		}

		$token = $wc_token->get_token();

		return $token ? $token : '';
	}

	/**
	 * Check if WooPay is enabled via the WooPayments Features API.
	 *
	 * When WooPay is enabled, Stripe.js uses the platform Stripe account and
	 * pm_ IDs are platform-scoped, making them unresolvable through the
	 * connected account API.
	 *
	 * @return bool
	 */
	private function is_woopay_enabled(): bool {
		if ( ! class_exists( '\WC_Payments_Features' ) ) {
			return false;
		}

		try {
			return \WC_Payments_Features::is_woopay_enabled();
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Extract the payment method ID from checkout payment fields.
	 *
	 * Checks the standard WooPayments key and the SEPA-specific key.
	 * Confirmation tokens (wcpay-confirmation-token) are intentionally not
	 * extracted — they cannot be resolved as payment methods.
	 *
	 * @param array $checkout_payment_fields Flat key-value map of checkout payment fields.
	 * @return string Payment method ID, or empty string if not found.
	 */
	private function extract_payment_method_id( array $checkout_payment_fields ): string {
		return $checkout_payment_fields['wcpay-payment-method'] ?? ( $checkout_payment_fields['wcpay-payment-method-sepa'] ?? '' );
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
			|| str_starts_with( $payment_method, self::GATEWAY_ID . '_' );
	}
}
