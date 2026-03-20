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
 *
 * @internal
 */
class PaymentMethodData {

	/**
	 * Transaction mode value for test/sandbox transactions.
	 */
	public const MODE_TEST = 'test';

	/**
	 * Transaction mode value for live/production transactions.
	 */
	public const MODE_LIVE = 'live';

	/**
	 * Transaction mode value when no gateway-specific resolver is available.
	 */
	public const MODE_UNKNOWN = null;

	/**
	 * Gateway ID that originated this payment method (e.g. 'stripe', 'square_credit_card').
	 *
	 * @var string
	 */
	private string $gateway;

	/**
	 * Payment type (e.g. 'card', 'sepa_debit', 'ideal', 'link').
	 *
	 * Null when the payment type has not been resolved by a compat layer.
	 *
	 * @var ?string
	 */
	private ?string $payment_type;

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
	 * Transaction mode: MODE_TEST, MODE_LIVE, or MODE_UNKNOWN.
	 *
	 * Resolved by gateway compat layers based on gateway-specific settings
	 * (e.g. Stripe testmode, Square sandbox, PayPal sandbox_on).
	 *
	 * @var ?string
	 */
	private ?string $transaction_mode;

	/**
	 * Constructor.
	 *
	 * @param string                 $gateway                 Gateway ID.
	 * @param ?string                $payment_type            Payment type identifier, or null when unresolved.
	 * @param bool                   $is_saved_payment_method Whether saved/tokenized.
	 * @param ?CardPaymentMethodData $card                    Card details, if applicable.
	 * @param ?string                $transaction_mode        Transaction mode: 'test', 'live', or null.
	 */
	public function __construct(
		string $gateway,
		?string $payment_type = null,
		bool $is_saved_payment_method = false,
		?CardPaymentMethodData $card = null,
		?string $transaction_mode = null
	) {
		$this->gateway                 = $gateway;
		$this->payment_type            = $payment_type;
		$this->is_saved_payment_method = $is_saved_payment_method;
		$this->card                    = 'card' === $payment_type ? $card ?? new CardPaymentMethodData() : null;
		$this->transaction_mode        = $transaction_mode;
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
	 * @param ?string $transaction_mode Transaction mode: 'test', 'live', or null.
	 * @return self
	 */
	public function with_transaction_mode( ?string $transaction_mode ): self {
		return new self(
			$this->gateway,
			$this->payment_type,
			$this->is_saved_payment_method,
			$this->card,
			$transaction_mode
		);
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
			'transaction_mode'        => $this->transaction_mode,
		);
	}
}
