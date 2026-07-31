<?php
/**
 * FakePayPalOrder file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

/**
 * Serializable stand-in for PayPal's order entity: the one method read from it.
 */
class FakePayPalOrder {

	/**
	 * @param string $order_id The PayPal order ID.
	 */
	public function __construct( private string $order_id ) {}

	/**
	 * The PayPal order ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->order_id;
	}
}
