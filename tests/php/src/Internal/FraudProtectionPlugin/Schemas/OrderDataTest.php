<?php
/**
 * OrderDataTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\OrderData;

/**
 * Tests for OrderData schema.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\OrderData
 */
class OrderDataTest extends FraudProtectionUnitTestCase {

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		update_option( 'woocommerce_calc_taxes', 'no' );
		WC()->cart->empty_cart();
	}

	/**
	 * @testdox from_cart() builds OrderData with all 11 keys.
	 */
	public function test_from_cart_builds_order_data(): void {
		wp_set_current_user( 0 );
		WC()->customer = new \WC_Customer( 0, true );

		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '50.00' );
		$product->save();

		WC()->cart->add_to_cart( $product->get_id(), 2 );
		WC()->cart->calculate_totals();

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$data = OrderData::from_cart( $order->get_id(), WC()->cart, WC()->customer );
		$arr  = $data->to_array();

		$this->assertEquals( $order->get_id(), $arr['order_id'] );
		$this->assertEquals( 'guest', $arr['customer_id'] );
		$this->assertEquals( 100.00, $arr['total'] );
		$this->assertEquals( 100.00, $arr['items_total'] );
		$this->assertEquals( 0.0, $arr['shipping_total'] );
		$this->assertEquals( 0.0, $arr['tax_total'] );
		$this->assertNull( $arr['shipping_tax_rate'] );
		$this->assertEquals( 0.0, $arr['discount_total'] );
		$this->assertIsString( $arr['currency'] );
		$this->assertIsString( $arr['cart_hash'] );
		$this->assertIsArray( $arr['items'] );
		$this->assertCount( 1, $arr['items'] );
	}

	/**
	 * @testdox from_cart() sets customer_id to 'guest' for guest users.
	 */
	public function test_guest_customer_id(): void {
		wp_set_current_user( 0 );
		WC()->customer = new \WC_Customer( 0, true );

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$data = OrderData::from_cart( 0, WC()->cart, WC()->customer );
		$arr  = $data->to_array();

		$this->assertEquals( 'guest', $arr['customer_id'] );
	}

	/**
	 * @testdox from_cart() sets customer_id for logged-in users.
	 */
	public function test_logged_in_customer_id(): void {
		$user_id = $this->factory->user->create();
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );
		WC()->customer = new \WC_Customer( $user_id, true );

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$data = OrderData::from_cart( 0, WC()->cart, WC()->customer );
		$arr  = $data->to_array();

		$this->assertEquals( $user_id, $arr['customer_id'] );
	}

	/**
	 * @testdox empty() returns defaults.
	 */
	public function test_empty_returns_defaults(): void {
		$data = OrderData::empty( 42 );
		$arr  = $data->to_array();

		$this->assertEquals( 42, $arr['order_id'] );
		$this->assertEquals( 'guest', $arr['customer_id'] );
		$this->assertEquals( 0, $arr['total'] );
		$this->assertEmpty( $arr['items'] );
		$this->assertNull( $arr['shipping_tax_rate'] );
	}

	/**
	 * @testdox to_array() serializes cart items.
	 */
	public function test_to_array_serializes_cart_items(): void {
		$product1 = \WC_Helper_Product::create_simple_product();
		$product1->set_regular_price( '10.00' );
		$product1->save();

		$product2 = \WC_Helper_Product::create_simple_product();
		$product2->set_regular_price( '20.00' );
		$product2->save();

		WC()->cart->add_to_cart( $product1->get_id(), 1 );
		WC()->cart->add_to_cart( $product2->get_id(), 1 );
		WC()->cart->calculate_totals();

		$data = OrderData::from_cart( 0, WC()->cart, WC()->customer );
		$arr  = $data->to_array();

		$this->assertCount( 2, $arr['items'] );
		$this->assertArrayHasKey( 'name', $arr['items'][0] );
		$this->assertArrayHasKey( 'quantity', $arr['items'][0] );
	}

	/**
	 * @testdox from_cart() keeps items with float quantities.
	 */
	public function test_from_cart_keeps_float_quantity_item(): void {
		$product = \WC_Helper_Product::create_simple_product();

		$cart_item_key = WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		WC()->cart->cart_contents[ $cart_item_key ]['quantity'] = 2.5;
		WC()->cart->calculate_totals();

		$arr = OrderData::from_cart( 0, WC()->cart, WC()->customer )->to_array();

		$this->assertCount( 1, $arr['items'] );
		$this->assertSame( 2.5, $arr['items'][0]['quantity'] );
	}

	/**
	 * @testdox from_cart() drops a throwing item, keeps the rest, and logs a warning.
	 */
	public function test_from_cart_drops_and_logs_throwing_item(): void {
		$product = \WC_Helper_Product::create_simple_product();

		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();

		$bad_product = $this->createMock( \WC_Product::class );
		$bad_product->method( 'get_price' )->willThrowException( new \RuntimeException( 'boom' ) );

		WC()->cart->cart_contents['bad_item_key'] = array(
			'data'          => $bad_product,
			'quantity'      => 1,
			'line_tax'      => 0,
			'line_subtotal' => 0,
			'line_total'    => 0,
		);

		$arr = OrderData::from_cart( 0, WC()->cart, WC()->customer )->to_array();

		$this->assertCount( 1, $arr['items'] );
		$this->assertLogged(
			'warning',
			'Failed to build cart item',
			array(
				'event_source'    => 'order_data_from_cart',
				'exception_class' => \RuntimeException::class,
			)
		);
	}

}
