<?php
/**
 * ThrowingPayPalOrder file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

/**
 * Stand-in for a PayPal order entity whose id() throws.
 *
 * Passes the `is_object()` + `method_exists()` guards the compat layer applies
 * to foreign order objects, then throws when its ID is read — the one failure
 * mode those guards do not cover. Used to prove the compat layer fails open
 * instead of letting the throw escape into ppcp's create-order request.
 */
class ThrowingPayPalOrder {

	/**
	 * Throw when the order ID is read.
	 *
	 * @return string
	 * @throws \RuntimeException Always.
	 */
	public function id(): string {
		throw new \RuntimeException( 'id() is unavailable' );
	}
}
