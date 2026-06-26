<?php
/**
 * PaymentInstrumentData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Schemas;

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
	 * Verification check passed — the value matches what the issuer has on file.
	 */
	public const CHECK_PASS = 'pass';

	/**
	 * Verification check failed — the value does not match.
	 */
	public const CHECK_FAIL = 'fail';

	/**
	 * Verification check unavailable — the issuer does not support this check.
	 */
	public const CHECK_UNAVAILABLE = 'unavailable';

	/**
	 * Verification check not performed — the check was not run for this transaction.
	 */
	public const CHECK_UNCHECKED = 'unchecked';

	/**
	 * Valid verification check result values.
	 *
	 * @var array<int, string>
	 */
	public const VALID_CHECK_RESULTS = array(
		self::CHECK_PASS,
		self::CHECK_FAIL,
		self::CHECK_UNAVAILABLE,
		self::CHECK_UNCHECKED,
	);

	/**
	 * Card brand (e.g. 'visa', 'mastercard', 'amex').
	 *
	 * @var ?string
	 */
	private ?string $brand;

	/**
	 * Card funding type (e.g. 'credit', 'debit', 'prepaid', 'unknown').
	 *
	 * @var ?string
	 */
	private ?string $funding;

	/**
	 * Last four digits of the card number, bank account, or IBAN.
	 *
	 * @var ?string
	 */
	private ?string $last4;

	/**
	 * Unique fingerprint for cross-transaction matching.
	 *
	 * @var ?string
	 */
	private ?string $fingerprint;

	/**
	 * Two-letter country code (e.g. 'US', 'DE').
	 *
	 * @var ?string
	 */
	private ?string $country;

	/**
	 * Card expiration month (1-12).
	 *
	 * @var ?int
	 */
	private ?int $exp_month;

	/**
	 * Card expiration year (4-digit).
	 *
	 * @var ?int
	 */
	private ?int $exp_year;

	/**
	 * Billing postcode associated with the payment.
	 *
	 * @var ?string
	 */
	private ?string $billing_postcode;

	/**
	 * Digital wallet type for express checkout methods (e.g. 'apple_pay', 'google_pay', 'link').
	 *
	 * @var ?string
	 */
	private ?string $wallet;

	/**
	 * Bank routing code (e.g. SEPA bank_code, BECS bsb_number, US routing_number, iDEAL bic).
	 *
	 * @var ?string
	 */
	private ?string $bank_code;

	/**
	 * Bank Identification Number (first 6 digits of card number, a.k.a. IIN).
	 *
	 * @var ?string
	 */
	private ?string $bin;

	/**
	 * CVC verification result ('pass', 'fail', 'unavailable', 'unchecked').
	 *
	 * @var ?string
	 */
	private ?string $cvc_check;

	/**
	 * AVS street address verification result.
	 *
	 * @var ?string
	 */
	private ?string $avs_address_check;

	/**
	 * AVS postal code verification result.
	 *
	 * @var ?string
	 */
	private ?string $avs_postcode_check;

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
			self::sanitize_enum( $data, 'cvc_check', self::VALID_CHECK_RESULTS ),
			self::sanitize_enum( $data, 'avs_address_check', self::VALID_CHECK_RESULTS ),
			self::sanitize_enum( $data, 'avs_postcode_check', self::VALID_CHECK_RESULTS )
		);
	}

	/**
	 * Constructor.
	 *
	 * @param ?string $brand              Card brand.
	 * @param ?string $funding            Card funding type.
	 * @param ?string $last4              Last four digits.
	 * @param ?string $fingerprint        Fingerprint.
	 * @param ?string $country            Country code.
	 * @param ?int    $exp_month          Expiration month.
	 * @param ?int    $exp_year           Expiration year.
	 * @param ?string $billing_postcode   Billing postcode.
	 * @param ?string $wallet             Digital wallet type.
	 * @param ?string $bank_code          Bank routing code.
	 * @param ?string $bin                Bank Identification Number.
	 * @param ?string $cvc_check          CVC verification result.
	 * @param ?string $avs_address_check  AVS street address result.
	 * @param ?string $avs_postcode_check AVS postal code result.
	 */
	private function __construct(
		?string $brand,
		?string $funding,
		?string $last4,
		?string $fingerprint,
		?string $country,
		?int $exp_month,
		?int $exp_year,
		?string $billing_postcode,
		?string $wallet,
		?string $bank_code,
		?string $bin,
		?string $cvc_check,
		?string $avs_address_check,
		?string $avs_postcode_check
	) {
		$this->brand              = $brand;
		$this->funding            = $funding;
		$this->last4              = $last4;
		$this->fingerprint        = $fingerprint;
		$this->country            = $country;
		$this->exp_month          = $exp_month;
		$this->exp_year           = $exp_year;
		$this->billing_postcode   = $billing_postcode;
		$this->wallet             = $wallet;
		$this->bank_code          = $bank_code;
		$this->bin                = $bin;
		$this->cvc_check          = $cvc_check;
		$this->avs_address_check  = $avs_address_check;
		$this->avs_postcode_check = $avs_postcode_check;
	}

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
