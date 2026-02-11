<?php
/**
 * PaymentDataResolver class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\Internal\FraudProtection\Schemas\PaymentMethodData;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves raw gateway-specific payment data into structured PaymentMethodData.
 *
 * Normalizes the raw `payment_data` array from the Store API checkout request
 * (which comes as `[{key, value}, ...]`) into a key-value map, then applies
 * the `woocommerce_fraud_protection_resolved_payment_data` filter to let
 * gateway compat layers resolve it into a PaymentMethodData record object.
 *
 * Implements a fail-open pattern: returns null if no gateway resolves the data
 * or if resolution fails for any reason.
 *
 * @internal
 */
class PaymentDataResolver {

	/**
	 * Resolve raw payment data into structured PaymentMethodData.
	 *
	 * @param string $payment_method The gateway ID (e.g. 'woocommerce_payments', 'stripe').
	 * @param array  $raw_payment_data Raw payment_data from the checkout request ([{key, value}, ...]).
	 * @return ?PaymentMethodData Resolved payment data, or null if unresolved.
	 */
	public function resolve( string $payment_method, array $raw_payment_data ): ?PaymentMethodData {
		$normalized_payment_data = $this->normalize_payment_data( $raw_payment_data );

		$pre_resolved_payment_data = $this->resolve_from_wc_token( $normalized_payment_data );

		try {
			/**
			 * Filters the resolved payment method data for fraud protection.
			 *
			 * Gateway compat layers hook into this filter to resolve raw payment data
			 * into a structured PaymentMethodData record object containing normalized
			 * payment instrument details (card brand, last4, funding type, etc.).
			 *
			 * When a saved WC payment token is present, the initial value will be
			 * pre-resolved from the token. Compat layers may override or pass through.
			 *
			 * @since 1.0.0
			 *
			 * @param ?PaymentMethodData $resolved               The resolved payment data (pre-resolved from WC token, or null).
			 * @param string             $payment_method          The gateway ID.
			 * @param array              $normalized_payment_data Normalized key-value map of payment data.
			 */
			$resolved_payment_data = apply_filters(
				'woocommerce_fraud_protection_resolved_payment_data',
				$pre_resolved_payment_data,
				$payment_method,
				$normalized_payment_data
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Filter `woocommerce_fraud_protection_resolved_payment_data` threw error: %s', $e->getMessage() ),
				array(
					'error'                     => $e,
					'payment_method'            => $payment_method,
					'normalized_payment_data'   => $normalized_payment_data,
					'pre_resolved_payment_data' => $pre_resolved_payment_data,
				)
			);
			return $pre_resolved_payment_data;
		}

		if ( ! $resolved_payment_data instanceof PaymentMethodData && null !== $resolved_payment_data ) {
			FraudProtectionController::log(
				'warning',
				sprintf(
					'Filter `woocommerce_fraud_protection_resolved_payment_data` returned unexpected type: %s',
					gettype( $resolved_payment_data )
				),
				array(
					'payment_method'            => $payment_method,
					'normalized_payment_data'   => $normalized_payment_data,
					'pre_resolved_payment_data' => $pre_resolved_payment_data,
					'resolved_payment_data'     => $resolved_payment_data,
				)
			);
		}

		return $resolved_payment_data instanceof PaymentMethodData ? $resolved_payment_data : $pre_resolved_payment_data;
	}

	/**
	 * Attempt to resolve payment data from a WC payment token.
	 *
	 * When a saved payment method is used, the checkout request includes a
	 * `token` key with the WC token ID. This method resolves card details
	 * from the stored token, providing a universal fallback for all gateways.
	 *
	 * @param array $normalized_payment_data Normalized key-value payment data.
	 * @return ?PaymentMethodData Resolved data from token, or null.
	 */
	private function resolve_from_wc_token( array $normalized_payment_data ): ?PaymentMethodData {
		$token_id = $normalized_payment_data['token'] ?? '';
		if ( empty( $token_id ) ) {
			return null;
		}

		$token = \WC_Payment_Tokens::get( (int) $token_id );
		if ( ! $token instanceof \WC_Payment_Token_CC ) {
			return null;
		}

		if ( $token->get_user_id() !== get_current_user_id() ) {
			return null;
		}

		return new PaymentMethodData(
			$token->get_gateway_id(),
			'card',
			true,
			new CardPaymentMethodData(
				$token->get_card_type() ? $token->get_card_type() : null,
				null,
				$token->get_last4() ? $token->get_last4() : null,
				null,
				null,
				$token->get_expiry_month() ? (int) $token->get_expiry_month() : null,
				$token->get_expiry_year() ? (int) $token->get_expiry_year() : null
			)
		);
	}

	/**
	 * Normalize raw payment_data from [{key, value}, ...] to key-value map.
	 *
	 * @param array $raw_payment_data Raw payment_data array.
	 * @return array Normalized key-value map.
	 */
	private function normalize_payment_data( array $raw_payment_data ): array {
		$normalized = array();

		foreach ( $raw_payment_data as $item ) {
			if ( is_array( $item ) && isset( $item['key'], $item['value'] ) ) {
				$normalized[ $item['key'] ] = $item['value'];
			}
		}

		return $normalized;
	}
}
