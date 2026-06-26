<?php
/**
 * PaymentMethodData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

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
	public const MODE_UNKNOWN = 'unknown';

	/**
	 * Valid transaction mode values.
	 *
	 * @var array<int, string>
	 */
	public const VALID_MODES = array( self::MODE_TEST, self::MODE_LIVE, self::MODE_UNKNOWN );

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
	 * Payment instrument details (card data, bank data, etc.).
	 *
	 * @var PaymentInstrumentData
	 */
	private PaymentInstrumentData $instrument;

	/**
	 * Transaction mode: MODE_TEST, MODE_LIVE, or MODE_UNKNOWN.
	 *
	 * Resolved by gateway compat layers based on gateway-specific APIs
	 * (e.g. Stripe WC_Stripe_Mode, Square settings handler, PayPal ConnectionState).
	 *
	 * @var string
	 */
	private string $transaction_mode;

	/**
	 * Constructor.
	 *
	 * @param string                 $gateway                 Gateway ID.
	 * @param ?string                $payment_type            Payment type identifier, or null when unresolved.
	 * @param bool                   $is_saved_payment_method Whether saved/tokenized.
	 * @param ?PaymentInstrumentData $instrument              Instrument details, if applicable.
	 * @param string                 $transaction_mode        Transaction mode (MODE_TEST, MODE_LIVE, or MODE_UNKNOWN).
	 */
	public function __construct(
		string $gateway,
		?string $payment_type = null,
		bool $is_saved_payment_method = false,
		?PaymentInstrumentData $instrument = null,
		string $transaction_mode = self::MODE_UNKNOWN
	) {
		$this->gateway                 = $gateway;
		$this->payment_type            = $payment_type;
		$this->is_saved_payment_method = $is_saved_payment_method;
		$this->instrument              = $instrument ? $instrument : PaymentInstrumentData::empty();
		$this->transaction_mode        = self::sanitize_transaction_mode( $transaction_mode );
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
	 * @param string $transaction_mode Transaction mode (MODE_TEST, MODE_LIVE, or MODE_UNKNOWN).
	 * @return self
	 */
	public function with_transaction_mode( string $transaction_mode ): self {
		return new self(
			$this->gateway,
			$this->payment_type,
			$this->is_saved_payment_method,
			$this->instrument,
			$transaction_mode
		);
	}

	/**
	 * Sanitize a transaction mode value.
	 *
	 * Falls back to MODE_UNKNOWN and logs a warning for invalid values.
	 *
	 * @param string $mode The mode to sanitize.
	 * @return string A valid mode constant value.
	 */
	private static function sanitize_transaction_mode( string $mode ): string {
		if ( in_array( $mode, self::VALID_MODES, true ) ) {
			return $mode;
		}

		FraudProtectionController::log(
			'warning',
			sprintf( 'Invalid transaction_mode value: %s — falling back to MODE_UNKNOWN', $mode )
		);

		return self::MODE_UNKNOWN;
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
			'instrument'              => $this->instrument->to_array(),
			'transaction_mode'        => $this->transaction_mode,
		);
	}
}
