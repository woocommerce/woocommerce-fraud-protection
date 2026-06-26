<?php
/**
 * CustomerData schema class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable record object representing customer identification data.
 *
 * Customer name is available via billing_address.first_name / last_name
 * and is not duplicated here.
 */
class CustomerData {

	/**
	 * Billing email.
	 *
	 * @var ?string
	 */
	private ?string $billing_email;

	/**
	 * Lifetime order count.
	 *
	 * @var int
	 */
	private int $lifetime_order_count;

	/**
	 * Billing address.
	 *
	 * @var Address
	 */
	private Address $billing_address;

	/**
	 * Shipping address.
	 *
	 * @var Address
	 */
	private Address $shipping_address;

	/**
	 * Private constructor — use factory methods.
	 *
	 * @param ?string  $billing_email        Billing email.
	 * @param int      $lifetime_order_count Lifetime order count.
	 * @param ?Address $billing_address      Billing address (defaults to empty).
	 * @param ?Address $shipping_address     Shipping address (defaults to empty).
	 */
	private function __construct(
		?string $billing_email = null,
		int $lifetime_order_count = 0,
		?Address $billing_address = null,
		?Address $shipping_address = null
	) {
		$this->billing_email        = $billing_email;
		$this->lifetime_order_count = $lifetime_order_count;
		$this->billing_address      = $billing_address ?? Address::empty();
		$this->shipping_address     = $shipping_address ?? Address::empty();
	}

	/**
	 * Build from a WC_Customer and pre-built Address objects.
	 *
	 * @param \WC_Customer $customer WooCommerce customer.
	 * @param Address      $billing  Billing address.
	 * @param Address      $shipping Shipping address.
	 * @return self
	 */
	public static function from_wc_customer( \WC_Customer $customer, Address $billing, Address $shipping ): self {
		$lifetime_order_count = 0;
		if ( $customer->get_id() > 0 ) {
			// Reload so the correct data store counts orders.
			$reloaded             = new \WC_Customer( $customer->get_id() );
			$lifetime_order_count = $reloaded->get_order_count();
		}

		return new self(
			\sanitize_email( $customer->get_billing_email() ),
			$lifetime_order_count,
			$billing,
			$shipping,
		);
	}

	/**
	 * Build an empty CustomerData for graceful degradation.
	 *
	 * @return self
	 */
	public static function empty(): self {
		return new self();
	}

	/**
	 * Serialize to array, nesting addresses as sub-arrays.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return array(
			'billing_email'        => $this->billing_email,
			'lifetime_order_count' => $this->lifetime_order_count,
			'billing_address'      => $this->billing_address->to_array(),
			'shipping_address'     => $this->shipping_address->to_array(),
		);
	}
}
