<?php
/**
 * Address schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object representing a billing or shipping address.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class Address {

	/**
	 * First name.
	 *
	 * @var ?string
	 */
	private ?string $first_name;

	/**
	 * Last name.
	 *
	 * @var ?string
	 */
	private ?string $last_name;

	/**
	 * Address line 1.
	 *
	 * @var ?string
	 */
	private ?string $address_1;

	/**
	 * Address line 2.
	 *
	 * @var ?string
	 */
	private ?string $address_2;

	/**
	 * City.
	 *
	 * @var ?string
	 */
	private ?string $city;

	/**
	 * State.
	 *
	 * @var ?string
	 */
	private ?string $state;

	/**
	 * Postcode.
	 *
	 * @var ?string
	 */
	private ?string $postcode;

	/**
	 * Country code.
	 *
	 * @var ?string
	 */
	private ?string $country;

	/**
	 * Phone number.
	 *
	 * @var ?string
	 */
	private ?string $phone;

	/**
	 * Private constructor — use factory methods.
	 *
	 * @param ?string $first_name First name.
	 * @param ?string $last_name  Last name.
	 * @param ?string $address_1  Address line 1.
	 * @param ?string $address_2  Address line 2.
	 * @param ?string $city       City.
	 * @param ?string $state      State.
	 * @param ?string $postcode   Postcode.
	 * @param ?string $country    Country.
	 * @param ?string $phone      Phone.
	 */
	private function __construct(
		?string $first_name = null,
		?string $last_name = null,
		?string $address_1 = null,
		?string $address_2 = null,
		?string $city = null,
		?string $state = null,
		?string $postcode = null,
		?string $country = null,
		?string $phone = null
	) {
		$this->first_name = $first_name;
		$this->last_name  = $last_name;
		$this->address_1  = $address_1;
		$this->address_2  = $address_2;
		$this->city       = $city;
		$this->state      = $state;
		$this->postcode   = $postcode;
		$this->country    = $country;
		$this->phone      = $phone;
	}

	/**
	 * Build from WC_Customer billing fields.
	 *
	 * @param \WC_Customer $customer WooCommerce customer object.
	 * @return self
	 */
	public static function from_wc_customer_billing( \WC_Customer $customer ): self {
		return new self(
			\sanitize_text_field( $customer->get_billing_first_name() ),
			\sanitize_text_field( $customer->get_billing_last_name() ),
			\sanitize_text_field( $customer->get_billing_address_1() ),
			\sanitize_text_field( $customer->get_billing_address_2() ),
			\sanitize_text_field( $customer->get_billing_city() ),
			\sanitize_text_field( $customer->get_billing_state() ),
			\sanitize_text_field( $customer->get_billing_postcode() ),
			\sanitize_text_field( $customer->get_billing_country() ),
			\sanitize_text_field( $customer->get_billing_phone() ),
		);
	}

	/**
	 * Build from WC_Customer shipping fields (phone is null).
	 *
	 * @param \WC_Customer $customer WooCommerce customer object.
	 * @return self
	 */
	public static function from_wc_customer_shipping( \WC_Customer $customer ): self {
		return new self(
			\sanitize_text_field( $customer->get_shipping_first_name() ),
			\sanitize_text_field( $customer->get_shipping_last_name() ),
			\sanitize_text_field( $customer->get_shipping_address_1() ),
			\sanitize_text_field( $customer->get_shipping_address_2() ),
			\sanitize_text_field( $customer->get_shipping_city() ),
			\sanitize_text_field( $customer->get_shipping_state() ),
			\sanitize_text_field( $customer->get_shipping_postcode() ),
			\sanitize_text_field( $customer->get_shipping_country() ),
			null,
		);
	}

	/**
	 * Build an empty address (all nulls) for graceful degradation.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self();
	}

	/**
	 * Get the country code.
	 *
	 * @return ?string Country code or null.
	 */
	public function get_country(): ?string {
		return ! empty( $this->country ) ? $this->country : null;
	}

	/**
	 * Serialize to array. Excludes the legacy `address` key.
	 *
	 * @return array<string, ?string>
	 */
	public function to_array(): array {
		return array(
			'first_name' => $this->first_name,
			'last_name'  => $this->last_name,
			'address_1'  => $this->address_1,
			'address_2'  => $this->address_2,
			'city'       => $this->city,
			'state'      => $this->state,
			'postcode'   => $this->postcode,
			'country'    => $this->country,
			'phone'      => $this->phone,
		);
	}
}
