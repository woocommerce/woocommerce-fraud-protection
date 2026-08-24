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
	 * @testdox from_order() observes view filters for the order total.
	 */
	public function test_from_order_uses_filtered_view_total(): void {
		$order = wc_create_order();
		$order->set_total( '10.00' );
		$order->save();
		$filter = static function ( $total ) {
			return '0.00';
		};
		add_filter( 'woocommerce_order_get_total', $filter );

		try {
			$arr = OrderData::from_order( $order )->to_array();
		} finally {
			remove_filter( 'woocommerce_order_get_total', $filter );
		}

		$this->assertSame( 0.0, $arr['total'] );
	}

	/**
	 * @testdox from_order() keeps usable items when another item fails.
	 */
	public function test_from_order_drops_failing_item_and_keeps_usable_item(): void {
		$product = \WC_Helper_Product::create_simple_product();
		$usable_item = new \WC_Order_Item_Product();
		$usable_item->set_product_id( $product->get_id() );
		$usable_item->set_name( 'Deleted usable item' );
		$usable_item->set_quantity( 1 );
		$usable_item->set_subtotal( '10.00' );
		$usable_item->set_total( '10.00' );
		$usable_item->set_total_tax( '0.00' );
		$product_id = $product->get_id();
		wp_delete_post( $product_id, true );

		$failing_item = $this->createMock( \WC_Order_Item_Product::class );
		$failing_item->method( 'get_quantity' )->willReturn( 1 );
		$failing_item->method( 'get_subtotal' )->willReturn( '10.00' );
		$failing_item->method( 'get_total' )->willReturn( '10.00' );
		$failing_item->method( 'get_total_tax' )->willReturn( '0.00' );
		$failing_item->method( 'get_product' )->willThrowException( new \RuntimeException( 'item failure' ) );

		$order = $this->createMock( \WC_Order::class );
		$order->method( 'get_customer_id' )->willReturn( 0 );
		$order->method( 'get_subtotal' )->willReturn( '20.00' );
		$order->method( 'get_shipping_total' )->willReturn( '0.00' );
		$order->method( 'get_cart_tax' )->willReturn( '0.00' );
		$order->method( 'get_discount_total' )->willReturn( '0.00' );
		$order->method( 'get_total' )->willReturn( '20.00' );
		$order->method( 'get_shipping_tax' )->willReturn( '0.00' );
		$order->method( 'get_items' )->willReturn( array( $failing_item, $usable_item ) );
		$order->method( 'get_id' )->willReturn( 123 );
		$order->method( 'get_currency' )->willReturn( 'USD' );
		$order->method( 'get_cart_hash' )->willReturn( 'order-hash' );

		$arr = OrderData::from_order( $order )->to_array();

		$this->assertCount( 1, $arr['items'] );
		$this->assertSame( $product_id, $arr['items'][0]['product_id'] );
		$this->assertSame( 'Deleted usable item', $arr['items'][0]['name'] );
		$this->assertSame( 10.0, $arr['items'][0]['unit_price'] );
		$this->assertNull( $arr['items'][0]['category'] );
		$this->assertNull( $arr['items'][0]['sku'] );
		$this->assertNull( $arr['items'][0]['product_type'] );
		$this->assertFalse( $arr['items'][0]['is_virtual'] );
		$this->assertFalse( $arr['items'][0]['is_downloadable'] );
		$this->assertSame( array(), $arr['items'][0]['attributes'] );
		$this->assertLogged(
			'warning',
			'Failed to build order item for order data; item dropped',
			array( 'event_source' => 'order_data_from_order' ),
			false
		);
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
	 * @testdox from_cart() keeps items with string quantities, recorded as supplied.
	 */
	public function test_from_cart_keeps_string_quantity_item(): void {
		$product = \WC_Helper_Product::create_simple_product();

		$cart_item_key = WC()->cart->add_to_cart( $product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );

		WC()->cart->calculate_totals();
		WC()->cart->cart_contents[ $cart_item_key ]['quantity'] = '2';

		$arr = OrderData::from_cart( 0, WC()->cart, WC()->customer )->to_array();

		$this->assertCount( 1, $arr['items'] );
		$this->assertSame( '2', $arr['items'][0]['quantity'] );
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
			),
			true
		);
	}

	/**
	 * @testdox from_cart() omits a total WooCommerce cannot state as a finite number.
	 *
	 * Every total is derived from cart contents, so none is guaranteed finite — and the string
	 * form matters as much as the float, because wc_format_decimal() renders a non-finite total
	 * as 'INF' or 'inf'. A string encodes perfectly well, so the encoding boundary keeps it;
	 * only this schema knows the field is meant to be a number. Each total is pinned separately
	 * so removing any single guard fails a named case.
	 *
	 * @dataProvider provide_unrepresentable_totals
	 *
	 * @param string $getter The WC_Cart getter to override.
	 * @param mixed  $raw    The value it returns.
	 * @param string $field  The payload field that must be omitted.
	 */
	public function test_from_cart_omits_an_unrepresentable_total( string $getter, mixed $raw, string $field ): void {
		$arr = $this->order_data_from_cart_returning( array( $getter => $raw ) );

		$this->assertNull( $arr[ $field ], sprintf( '%s must be omitted, not asserted as a number', $field ) );
	}

	/**
	 * Data provider for {@see test_from_cart_omits_an_unrepresentable_total()}.
	 *
	 * @return array<string, array{0: string, 1: mixed, 2: string}>
	 */
	public function provide_unrepresentable_totals(): array {
		return array(
			'items_total INF'           => array( 'get_subtotal', INF, 'items_total' ),
			'items_total "INF" string'  => array( 'get_subtotal', 'INF', 'items_total' ),
			'shipping_total INF'        => array( 'get_shipping_total', INF, 'shipping_total' ),
			'tax_total INF'             => array( 'get_cart_contents_tax', INF, 'tax_total' ),
			'tax_total NAN'             => array( 'get_cart_contents_tax', NAN, 'tax_total' ),
			'discount_total INF'        => array( 'get_discount_total', INF, 'discount_total' ),
			'total INF'                 => array( 'get_total', INF, 'total' ),
			'total "inf" string'        => array( 'get_total', 'inf', 'total' ),
			'total overflowing string'  => array( 'get_total', '1e400', 'total' ),
		);
	}

	/**
	 * @testdox from_cart() omits shipping_tax_rate when either side of the quotient is unusable.
	 *
	 * @dataProvider provide_unusable_shipping_operands
	 *
	 * @param array<string, mixed> $overrides Cart getter overrides.
	 */
	public function test_from_cart_omits_shipping_tax_rate_for_an_unusable_operand( array $overrides ): void {
		$arr = $this->order_data_from_cart_returning( $overrides );

		$this->assertNull( $arr['shipping_tax_rate'], 'shipping_tax_rate must be omitted rather than derived from a non-number' );
	}

	/**
	 * Data provider for {@see test_from_cart_omits_shipping_tax_rate_for_an_unusable_operand()}.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public function provide_unusable_shipping_operands(): array {
		return array(
			'non-finite shipping total' => array( array( 'get_shipping_total' => INF, 'get_shipping_tax' => 2.0 ) ),
			// The string sentinel rather than float INF, because only the operand guard can
			// catch it: were it removed, 'INF' > 0 is true and 'INF' / 10.0 is a TypeError, so
			// the quotient guard never runs. Float INF would be caught by the quotient guard
			// either way and so would pin nothing here.
			'shipping tax "INF" string' => array( array( 'get_shipping_total' => 10.0, 'get_shipping_tax' => 'INF' ) ),
			'quotient overflows'        => array( array( 'get_shipping_total' => 1e-320, 'get_shipping_tax' => 1e300 ) ),
		);
	}

	/**
	 * @testdox from_cart() still derives shipping_tax_rate from two usable operands.
	 *
	 * The positive control for the guards above. Without it, replacing the whole
	 * shipping_tax_rate derivation with null would leave every assertion in the suite green.
	 */
	public function test_from_cart_derives_shipping_tax_rate_from_usable_operands(): void {
		$arr = $this->order_data_from_cart_returning(
			array(
				'get_shipping_total' => '10',
				'get_shipping_tax'   => '2',
			)
		);

		$this->assertSame( 0.2, $arr['shipping_tax_rate'], 'A derivable rate must still be derived' );
	}

	/**
	 * Build OrderData from a cart double whose getters return the given values.
	 *
	 * A double rather than a real cart: the point is the schema's own handling of what
	 * WooCommerce hands over, and a real cart cannot be driven to return every shape it might.
	 *
	 * @param array<string, mixed> $overrides Getter name => return value.
	 * @return array<string, mixed> The serialized OrderData.
	 */
	private function order_data_from_cart_returning( array $overrides ): array {
		$returns = array_merge(
			array(
				'get_subtotal'          => '10',
				'get_shipping_total'    => '0',
				'get_cart_contents_tax' => '0',
				'get_discount_total'    => '0',
				'get_total'             => '10',
				'get_shipping_tax'      => '0',
				'get_cart_hash'         => 'test-hash',
				'get_cart'              => array(),
			),
			$overrides
		);

		$cart = $this->createMock( \WC_Cart::class );
		foreach ( $returns as $method => $value ) {
			$cart->method( $method )->willReturn( $value );
		}

		$customer = $this->createMock( \WC_Customer::class );
		$customer->method( 'get_id' )->willReturn( 0 );

		return OrderData::from_cart( 0, $cart, $customer )->to_array();
	}

}
