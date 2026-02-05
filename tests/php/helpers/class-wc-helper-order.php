<?php
/**
 * Helper class for creating test orders.
 *
 * @package WooCommerce_Fraud_Protection\Tests
 */

/**
 * WC_Helper_Order class for creating test orders.
 */
class WC_Helper_Order {

	/**
	 * Create a test order.
	 *
	 * @param int   $customer_id Customer ID.
	 * @param array $product_props Product properties for the order item.
	 * @return WC_Order
	 */
	public static function create_order( $customer_id = 0, $product_props = array() ) {
		// Create a product for the order.
		$product = WC_Helper_Product::create_simple_product( true, $product_props );

		$order = wc_create_order(
			array(
				'customer_id' => $customer_id,
				'status'      => 'pending',
			)
		);

		$order->add_product( $product, 1 );

		$order->set_billing_first_name( 'Test' );
		$order->set_billing_last_name( 'Customer' );
		$order->set_billing_email( 'test@example.com' );
		$order->set_billing_address_1( '123 Test St' );
		$order->set_billing_city( 'Test City' );
		$order->set_billing_state( 'CA' );
		$order->set_billing_postcode( '12345' );
		$order->set_billing_country( 'US' );

		$order->calculate_totals();
		$order->save();

		return $order;
	}
}
