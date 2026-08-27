<?php
/**
 * PaymentMethodData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object for resolved payment method information.
 *
 * Contains structured, normalized payment instrument data resolved from
 * gateway-specific raw payment data. Used in the fraud protection verify
 * payload for better risk assessment.
 */
class PaymentMethodData {

	/**
	 * Payment instrument details (card data, bank data, etc.).
	 *
	 * @var PaymentInstrumentData
	 */
	private readonly PaymentInstrumentData $instrument;

	/**
	 * Constructor.
	 *
	 * @param string                 $gateway                 Gateway ID that originated this payment method (e.g. 'stripe', 'square_credit_card').
	 * @param ?string                $payment_type            Payment type (e.g. 'card', 'sepa_debit', 'ideal', 'link'), or null when unresolved by a compat layer.
	 * @param bool                   $is_saved_payment_method Whether this is a saved/tokenized payment method.
	 * @param ?PaymentInstrumentData $instrument              Instrument details, if applicable.
	 * @param PaymentMode            $transaction_mode        Transaction mode, resolved by gateway compat layers (Stripe WC_Stripe_Mode, Square settings handler, PayPal ConnectionState).
	 * @param ?string                $merchant_identifier     Merchant account or location identifier, if available.
	 * @param ?string                $merchant_identifier_type Merchant identifier type, if available.
	 */
	public function __construct(
		private readonly string $gateway,
		private readonly ?string $payment_type = null,
		private readonly bool $is_saved_payment_method = false,
		?PaymentInstrumentData $instrument = null,
		private readonly PaymentMode $transaction_mode = PaymentMode::Unknown,
		private readonly ?string $merchant_identifier = null,
		private readonly ?string $merchant_identifier_type = null
	) {
		$this->instrument = $instrument ? $instrument : PaymentInstrumentData::empty();
	}

	/**
	 * Get the gateway ID.
	 *
	 * @return string
	 */
	public function get_gateway(): string {
		return $this->gateway;
	}

	/**
	 * Return a copy with the given transaction mode.
	 *
	 * Used by gateway compat layers to augment pre-resolved payment data
	 * (e.g. from WC token) with the gateway's test/live mode.
	 *
	 * @param PaymentMode $transaction_mode Transaction mode.
	 * @return self
	 */
	public function with_transaction_mode( PaymentMode $transaction_mode ): self {
		return new self(
			$this->gateway,
			$this->payment_type,
			$this->is_saved_payment_method,
			$this->instrument,
			$transaction_mode,
			$this->merchant_identifier,
			$this->merchant_identifier_type
		);
	}

	/**
	 * Return a copy with the merchant identifier pair.
	 *
	 * @param ?string $merchant_identifier      Merchant account or location identifier.
	 * @param ?string $merchant_identifier_type Merchant identifier type.
	 * @return self
	 */
	public function with_merchant_identifier( ?string $merchant_identifier, ?string $merchant_identifier_type ): self {
		return new self(
			$this->gateway,
			$this->payment_type,
			$this->is_saved_payment_method,
			$this->instrument,
			$this->transaction_mode,
			$merchant_identifier,
			$merchant_identifier_type
		);
	}

	/**
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'gateway'                  => $this->gateway,
			'payment_type'             => $this->payment_type,
			'is_saved_payment_method'  => $this->is_saved_payment_method,
			'instrument'               => $this->instrument->to_array(),
			'transaction_mode'         => $this->transaction_mode->value,
			'merchant_identifier'      => $this->merchant_identifier,
			'merchant_identifier_type' => $this->merchant_identifier_type,
		);
	}
}
