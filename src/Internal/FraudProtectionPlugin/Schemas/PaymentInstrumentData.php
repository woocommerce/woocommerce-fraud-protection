<?php
/**
 * PaymentInstrumentData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object for payment instrument details.
 *
 * Contains normalized information about the payment instrument (card, bank
 * account, etc.) resolved from gateway-specific payment data. Fields are
 * nullable — only those applicable to the instrument type are populated.
 */
class PaymentInstrumentData {

	use SanitizesScalarFields;

	/**
	 * Build an empty instrument (all nulls) for graceful degradation.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return self::from_array();
	}

	/**
	 * Create from an associative array.
	 *
	 * Keys correspond to property names. Missing keys default to null, and unrecognized
	 * keys are ignored. Each value is sanitized defensively so a malformed one never
	 * reaches the strict constructor: a wrongly-typed value is coerced where it can be
	 * (a scalar to string) or dropped to null, and either case is logged so a
	 * misbehaving integration surfaces instead of failing silently.
	 *
	 * @param array $data Instrument fields.
	 * @return self
	 */
	public static function from_array( array $data = array() ): self {
		return new self(
			self::sanitize_string_field( $data, 'brand' ),
			self::sanitize_string_field( $data, 'funding' ),
			self::sanitize_string_field( $data, 'last4' ),
			self::sanitize_string_field( $data, 'fingerprint' ),
			self::sanitize_string_field( $data, 'country' ),
			self::sanitize_int_field( $data, 'exp_month' ),
			self::sanitize_int_field( $data, 'exp_year' ),
			self::sanitize_string_field( $data, 'billing_postcode' ),
			self::sanitize_string_field( $data, 'wallet' ),
			self::sanitize_string_field( $data, 'bank_code' ),
			self::sanitize_string_field( $data, 'bin' ),
			self::sanitize_enum( $data, 'cvc_check', CheckResult::cases() )?->value,
			self::sanitize_enum( $data, 'avs_address_check', CheckResult::cases() )?->value,
			self::sanitize_enum( $data, 'avs_postcode_check', CheckResult::cases() )?->value
		);
	}

	/**
	 * Constructor.
	 *
	 * @param ?string $brand              Card brand (e.g. 'visa', 'mastercard', 'amex').
	 * @param ?string $funding            Card funding type (e.g. 'credit', 'debit', 'prepaid', 'unknown').
	 * @param ?string $last4              Last four digits of the card number, bank account, or IBAN.
	 * @param ?string $fingerprint        Unique fingerprint for cross-transaction matching.
	 * @param ?string $country            Two-letter country code (e.g. 'US', 'DE').
	 * @param ?int    $exp_month          Card expiration month (1-12).
	 * @param ?int    $exp_year           Card expiration year (4-digit).
	 * @param ?string $billing_postcode   Billing postcode associated with the payment.
	 * @param ?string $wallet             Digital wallet type for express checkout methods (e.g. 'apple_pay', 'google_pay', 'link').
	 * @param ?string $bank_code          Bank routing code (e.g. SEPA bank_code, BECS bsb_number, US routing_number, iDEAL bic).
	 * @param ?string $bin                Bank Identification Number (first 6 digits of card number, a.k.a. IIN).
	 * @param ?string $cvc_check          CVC verification result ('pass', 'fail', 'unavailable', 'unchecked').
	 * @param ?string $avs_address_check  AVS street address verification result.
	 * @param ?string $avs_postcode_check AVS postal code verification result.
	 */
	private function __construct(
		private readonly ?string $brand,
		private readonly ?string $funding,
		private readonly ?string $last4,
		private readonly ?string $fingerprint,
		private readonly ?string $country,
		private readonly ?int $exp_month,
		private readonly ?int $exp_year,
		private readonly ?string $billing_postcode,
		private readonly ?string $wallet,
		private readonly ?string $bank_code,
		private readonly ?string $bin,
		private readonly ?string $cvc_check,
		private readonly ?string $avs_address_check,
		private readonly ?string $avs_postcode_check
	) {}

	/**
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'brand'              => $this->brand,
			'funding'            => $this->funding,
			'last4'              => $this->last4,
			'fingerprint'        => $this->fingerprint,
			'country'            => $this->country,
			'exp_month'          => $this->exp_month,
			'exp_year'           => $this->exp_year,
			'billing_postcode'   => $this->billing_postcode,
			'wallet'             => $this->wallet,
			'bank_code'          => $this->bank_code,
			'bin'                => $this->bin,
			'cvc_check'          => $this->cvc_check,
			'avs_address_check'  => $this->avs_address_check,
			'avs_postcode_check' => $this->avs_postcode_check,
		);
	}
}
