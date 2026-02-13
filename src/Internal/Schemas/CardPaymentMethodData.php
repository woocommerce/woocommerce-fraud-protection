<?php
/**
 * CardPaymentMethodData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object for card-specific payment method details.
 *
 * Contains normalized card information (brand, last4, funding, etc.)
 * resolved from gateway-specific payment data.
 *
 * @internal
 */
class CardPaymentMethodData {

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
	 * Last four digits of the card number.
	 *
	 * @var ?string
	 */
	private ?string $last4;

	/**
	 * Unique card fingerprint for cross-transaction matching.
	 *
	 * @var ?string
	 */
	private ?string $fingerprint;

	/**
	 * Two-letter country code where the card was issued (e.g. 'US', 'GB').
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
	 * Billing postcode associated with the card payment.
	 *
	 * @var ?string
	 */
	private ?string $billing_postcode;

	/**
	 * Constructor.
	 *
	 * @param ?string $brand            Card brand.
	 * @param ?string $funding          Card funding type.
	 * @param ?string $last4            Last four digits.
	 * @param ?string $fingerprint      Card fingerprint.
	 * @param ?string $country          Issuing country code.
	 * @param ?int    $exp_month        Expiration month.
	 * @param ?int    $exp_year         Expiration year.
	 * @param ?string $billing_postcode Billing postcode.
	 */
	public function __construct(
		?string $brand = null,
		?string $funding = null,
		?string $last4 = null,
		?string $fingerprint = null,
		?string $country = null,
		?int $exp_month = null,
		?int $exp_year = null,
		?string $billing_postcode = null
	) {
		$this->brand            = $brand;
		$this->funding          = $funding;
		$this->last4            = $last4;
		$this->fingerprint      = $fingerprint;
		$this->country          = $country;
		$this->exp_month        = $exp_month;
		$this->exp_year         = $exp_year;
		$this->billing_postcode = $billing_postcode;
	}

	/**
	 * Serialize to array.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'brand'            => $this->brand,
			'funding'          => $this->funding,
			'last4'            => $this->last4,
			'fingerprint'      => $this->fingerprint,
			'country'          => $this->country,
			'exp_month'        => $this->exp_month,
			'exp_year'         => $this->exp_year,
			'billing_postcode' => $this->billing_postcode,
		);
	}
}
