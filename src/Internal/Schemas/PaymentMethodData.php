<?php
/**
 * PaymentMethodData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object for resolved payment method information.
 *
 * Contains structured, normalized payment instrument data resolved from
 * gateway-specific raw payment data. Used in the fraud protection verify
 * payload for better risk assessment.
 *
 * @internal
 */
class PaymentMethodData {

	/**
	 * Gateway ID that originated this payment method (e.g. 'stripe', 'square_credit_card').
	 *
	 * @var string
	 */
	private string $gateway;

	/**
	 * Payment type (e.g. 'card', 'sepa_debit', 'ideal', 'link').
	 *
	 * @var string
	 */
	private string $payment_type;

	/**
	 * Whether this is a saved/tokenized payment method.
	 *
	 * @var bool
	 */
	private bool $is_saved_payment_method;

	/**
	 * Card-specific payment details (non-null when payment_type === 'card').
	 *
	 * @var ?CardPaymentMethodData
	 */
	private ?CardPaymentMethodData $card;

	/**
	 * Constructor.
	 *
	 * @param string                 $gateway                 Gateway ID.
	 * @param string                 $payment_type            Payment type identifier.
	 * @param bool                   $is_saved_payment_method Whether saved/tokenized.
	 * @param ?CardPaymentMethodData $card                    Card details, if applicable.
	 */
	public function __construct(
		string $gateway,
		string $payment_type,
		bool $is_saved_payment_method = false,
		?CardPaymentMethodData $card = null
	) {
		$this->gateway                 = $gateway;
		$this->payment_type            = $payment_type;
		$this->is_saved_payment_method = $is_saved_payment_method;
		$this->card                    = 'card' === $payment_type ? $card ?? new CardPaymentMethodData() : null;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'gateway'                 => $this->gateway,
			'payment_type'            => $this->payment_type,
			'is_saved_payment_method' => $this->is_saved_payment_method,
			'card'                    => $this->card ? $this->card->to_array() : null,
		);
	}
}
