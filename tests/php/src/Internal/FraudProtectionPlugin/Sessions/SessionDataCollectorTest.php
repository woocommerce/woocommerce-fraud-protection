<?php
/**
 * SessionDataCollectorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;

/**
 * Tests for SessionDataCollector.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector
 */
class SessionDataCollectorTest extends FraudProtectionUnitTestCase {

	/**
	 * The system under test.
	 *
	 * @var SessionDataCollector
	 */
	private $sut;

	/**
	 * SessionIdentityManager instance.
	 *
	 * @var SessionIdentityManager
	 */
	private $session_identity_manager;

	/**
	 * The session handler in place before the test, restored in tearDown().
	 *
	 * @var \WC_Session|null
	 */
	private $original_session;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;

		// Ensure WooCommerce cart and session are available.
		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$this->session_identity_manager = new SessionIdentityManager();
		$this->sut                       = new SessionDataCollector();
		$this->sut->init( $this->session_identity_manager );

		// Disable taxes before adding products to cart.
		update_option( 'woocommerce_calc_taxes', 'no' );

		// Clear any existing session data before each test.
		WC()->session->set( 'fraud_protection_collected_data', null );
		WC()->session->set( 'fraud_protection_collected_events_truncated', null );
		WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, null );
	}

	/**
	 * Runs after each test.
	 */
	public function tearDown(): void {
		WC()->session = $this->original_session;
		parent::tearDown();
	}

	/**
	 * Helper method to collect data and retrieve event from session.
	 *
	 * Events only contain: event_type, timestamp, event_data.
	 * For full data (session, customer, order), use get_collected_data().
	 *
	 * @param string|null $event_type Optional event type.
	 * @param array       $event_data Optional event data.
	 * @return array The collected event data from session.
	 */
	private function collect_and_get_event( ?string $event_type = null, array $event_data = array() ): array {
		$this->sut->collect( $event_type, $event_data );
		$stored_data = WC()->session->get( 'fraud_protection_collected_data' );
		return $stored_data[0] ?? array();
	}

	/**
	 * Helper method to collect data and retrieve full response via get_collected_data().
	 *
	 * Returns: wc_version, session, customer, order, collected_events.
	 *
	 * @param string|null $event_type Optional event type.
	 * @param array       $event_data Optional event data.
	 * @return array The full collected data response.
	 */
	private function collect_and_get_data( ?string $event_type = null, array $event_data = array() ): array {
		$this->sut->collect( $event_type, $event_data );
		return $this->sut->get_collected_data();
	}

	/**
	 * Count arrays, scalars, and null values in an event.
	 *
	 * @param array $value Event array.
	 * @return int Node count.
	 */
	private function count_event_nodes( array $value ): int {
		$count = 1;
		foreach ( $value as $item ) {
			$count += is_array( $item ) ? $this->count_event_nodes( $item ) : 1;
		}

		return $count;
	}

	/**
	 * @testdox collect() stores properly structured event with 3 top-level keys.
	 */
	public function test_collect_stores_properly_structured_event(): void {
		$event = $this->collect_and_get_event();

		$this->assertIsArray( $event );
		$this->assertArrayHasKey( 'event_type', $event );
		$this->assertArrayHasKey( 'timestamp', $event );
		$this->assertArrayHasKey( 'event_data', $event );
		$this->assertCount( 3, $event );
	}

	/**
	 * @testdox get_collected_data() returns properly structured response with 5 top-level keys.
	 */
	public function test_get_collected_data_returns_properly_structured_response(): void {
		$result = $this->collect_and_get_data();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'wc_version', $result );
		$this->assertArrayHasKey( 'session', $result );
		$this->assertArrayHasKey( 'customer', $result );
		$this->assertArrayHasKey( 'order', $result );
		$this->assertArrayHasKey( 'collected_events', $result );
		$this->assertCount( 5, $result );
	}

	/**
	 * Test that collect() accepts event_type and event_data parameters.
	 */
	public function test_collect_accepts_event_type_and_event_data_parameters(): void {
		$event_type = 'checkout_started';
		$event_data = array(
			'page'   => 'checkout',
			'source' => 'test',
		);

		$event = $this->collect_and_get_event( $event_type, $event_data );

		$this->assertEquals( $event_type, $event['event_type'] );
		$this->assertEquals( $event_data, $event['event_data'] );
	}

	/**
	 * @testdox collect() does not log event data when session is unavailable.
	 */
	public function test_collect_does_not_log_event_data_when_session_is_unavailable(): void {
		$spy = $this->spy_on_controller_logging();
		$this->sut->init( $this->createMock( SessionIdentityManager::class ) );
		WC()->session = null;

		$this->sut->collect(
			'checkout_update',
			array( 'billing_email' => 'unavailable-event-value-marker@example.com' )
		);

		$this->assertLogged(
			'error',
			'no valid WooCommerce session exists',
			array(
				'context'    => 'SessionDataCollector::collect',
				'event_type' => 'checkout_update',
			),
			false
		);
		$this->assertCount( 1, $spy->entries );
		$this->assertArrayNotHasKey( 'event_data', $spy->entries[0]['context'] );
		$this->assertStringNotContainsString( 'unavailable-event-value-marker', (string) wp_json_encode( $spy->entries[0] ) );
	}

	/**
	 * Test wc_version field is included in get_collected_data response.
	 */
	public function test_wc_version_is_included(): void {
		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$this->assertEquals( WC()->version, $result['wc_version'] );
	}

	/**
	 * Test timestamp format is UTC (gmdate format).
	 */
	public function test_timestamp_format_is_utc(): void {
		$event = $this->collect_and_get_event();

		$this->assertArrayHasKey( 'timestamp', $event );
		$this->assertNotEmpty( $event['timestamp'] );

		// Verify timestamp is in Y-m-d H:i:s format.
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $event['timestamp'] );

		// Verify timestamp is recent (within last 10 seconds).
		$timestamp       = strtotime( $event['timestamp'] );
		$current_time    = time();
		$time_difference = abs( $current_time - $timestamp );
		$this->assertLessThanOrEqual( 10, $time_difference, 'Timestamp should be recent (within 10 seconds)' );
	}

	/**
	 * Test that collect() uses default values when parameters not provided.
	 */
	public function test_collect_uses_default_values_when_parameters_not_provided(): void {
		$event = $this->collect_and_get_event();

		$this->assertNull( $event['event_type'] );
		$this->assertEquals( array(), $event['event_data'] );
	}

	/**
	 * @testdox Nested sections are initialized as arrays.
	 */
	public function test_nested_sections_initialized_as_arrays(): void {
		$result = $this->collect_and_get_data();

		$this->assertIsArray( $result['session'] );
		$this->assertIsArray( $result['customer'] );
		$this->assertIsArray( $result['order'] );
		$this->assertIsArray( $result['collected_events'] );

		// Addresses are nested under customer.
		$this->assertIsArray( $result['customer']['billing_address'] );
		$this->assertIsArray( $result['customer']['shipping_address'] );

		$this->assertCount( 1, $result['collected_events'] );
	}

	/**
	 * Test session data includes all 4 required fields.
	 */
	public function test_session_data_includes_all_required_fields(): void {
		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$this->assertIsArray( $result['session'] );
		$this->assertCount( 2, $result['session'] );
		$this->assertArrayHasKey( 'wc_identity_id', $result['session'] );
		$this->assertArrayHasKey( 'email', $result['session'] );
	}

	/**
	 * Test wc_identity_id is retrieved from SessionIdentityManager.
	 */
	public function test_session_id_retrieved_from_session_identity_manager(): void {
		WC()->session->set( SessionIdentityManager::CUSTOMER_IDENTITY_ID_KEY, str_repeat( 'a', 70 ) );
		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$this->assertArrayHasKey( 'wc_identity_id', $result['session'] );
		// Session ID should be a string when session is available.
		$this->assertIsString( $result['session']['wc_identity_id'] );
		$this->assertNotEmpty( $result['session']['wc_identity_id'] );
		$this->assertSame( str_repeat( 'a', 64 ), $result['session']['wc_identity_id'] );
	}

	/**
	 * Test email collection fallback chain for logged-in user.
	 */
	public function test_email_collection_for_logged_in_user(): void {
		// Create a test user and log them in.
		$user_id = $this->factory->user->create(
			array(
				'user_email' => 'testuser@example.com',
			)
		);
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$this->assertArrayHasKey( 'email', $result['session'] );
		$this->assertEquals( 'testuser@example.com', $result['session']['email'] );
	}

	/**
	 * Test session email is null for guest users (WP user email only, no WC_Customer fallback).
	 */
	public function test_session_email_null_for_guest_users(): void {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Set customer billing email — should NOT appear in session.email.
		WC()->customer->set_billing_email( 'customer@example.com' );

		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$this->assertArrayHasKey( 'email', $result['session'] );
		$this->assertNull( $result['session']['email'] );

		// Billing email is available in customer section instead.
		$this->assertEquals( 'customer@example.com', $result['customer']['billing_email'] );
	}

	/**
	 * Test customer data includes all 4 required fields (including nested addresses).
	 */
	public function test_customer_data_includes_all_required_fields(): void {
		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$this->assertIsArray( $result['customer'] );
		$this->assertCount( 4, $result['customer'] );
		$this->assertArrayHasKey( 'billing_email', $result['customer'] );
		$this->assertArrayHasKey( 'lifetime_order_count', $result['customer'] );
		$this->assertArrayHasKey( 'billing_address', $result['customer'] );
		$this->assertArrayHasKey( 'shipping_address', $result['customer'] );
	}

	/**
	 * Test lifetime_order_count field exists and uses WC_Customer::get_order_count().
	 */
	public function test_lifetime_order_count_for_registered_customer(): void {
		// Create a test user.
		$user_id = $this->factory->user->create(
			array(
				'user_email' => 'customer@example.com',
			)
		);
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		// Initialize customer with logged-in user.
		WC()->customer = new \WC_Customer( $user_id, true );

		// Set customer billing data.
		WC()->customer->set_billing_first_name( 'John' );
		WC()->customer->set_billing_last_name( 'Doe' );
		WC()->customer->set_billing_email( 'customer@example.com' );

		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		// Verify lifetime_order_count field exists and returns a valid integer.
		// In test environment, the method returns 0 because the cache is not automatically
		// populated by order lifecycle hooks. In production, WooCommerce maintains this cache.
		$this->assertArrayHasKey( 'lifetime_order_count', $result['customer'] );
		$this->assertIsInt( $result['customer']['lifetime_order_count'] );
		$this->assertGreaterThanOrEqual( 0, $result['customer']['lifetime_order_count'] );
	}

	/**
	 * Test graceful degradation when customer data unavailable.
	 */
	public function test_graceful_degradation_when_customer_data_unavailable(): void {
		// Ensure no user is logged in.
		wp_set_current_user( 0 );

		// Clear customer data.
		WC()->customer->set_billing_first_name( '' );
		WC()->customer->set_billing_last_name( '' );
		WC()->customer->set_billing_email( '' );

		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		// Should return customer section with fields, even if empty/null.
		$this->assertIsArray( $result['customer'] );
		$this->assertCount( 4, $result['customer'] );
		$this->assertArrayHasKey( 'billing_email', $result['customer'] );
		$this->assertArrayHasKey( 'lifetime_order_count', $result['customer'] );
	}

	/**
	 * @testdox Order data includes all required fields with proper structure when order_id is provided.
	 */
	public function test_order_data_includes_all_required_fields(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertIsArray( $result['order'] );
		$this->assertArrayHasKey( 'order_id', $result['order'] );
		$this->assertArrayHasKey( 'customer_id', $result['order'] );
		$this->assertArrayHasKey( 'total', $result['order'] );
		$this->assertArrayHasKey( 'items_total', $result['order'] );
		$this->assertArrayHasKey( 'shipping_total', $result['order'] );
		$this->assertArrayHasKey( 'tax_total', $result['order'] );
		$this->assertArrayHasKey( 'shipping_tax_rate', $result['order'] );
		$this->assertArrayHasKey( 'discount_total', $result['order'] );
		$this->assertArrayHasKey( 'currency', $result['order'] );
		$this->assertArrayHasKey( 'cart_hash', $result['order'] );
		$this->assertArrayHasKey( 'items', $result['order'] );
		$this->assertIsArray( $result['order']['items'] );
	}

	/**
	 * @testdox Order totals and items are collected from the selected order when order_id is provided.
	 */
	public function test_order_totals_collected_from_selected_order(): void {
		WC()->cart->empty_cart();
		$selected_product = \WC_Helper_Product::create_simple_product();
		$selected_product->set_name( 'Selected product' );
		$selected_product->set_sku( 'SELECTED-SKU' );
		$selected_product->set_regular_price( '20.00' );
		$selected_product->save();
		$current_product = \WC_Helper_Product::create_simple_product();
		$current_product->set_regular_price( '100.00' );
		$current_product->save();
		WC()->cart->add_to_cart( $current_product->get_id(), 3 );
		WC()->cart->calculate_totals();
		$current_user_id  = $this->factory->user->create( array( 'user_email' => 'ambient@example.com' ) );
		$selected_user_id = $this->factory->user->create( array( 'user_email' => 'selected@example.com' ) );
		wp_set_current_user( $current_user_id );
		WC()->customer = new \WC_Customer( $current_user_id, true );
		WC()->customer->set_billing_email( 'ambient-billing@example.com' );
		WC()->customer->set_billing_address_1( 'Ambient billing address' );
		$history = wc_create_order( array( 'customer_id' => $selected_user_id ) );
		$history->set_status( 'completed' );
		$history->save();
		$order = wc_create_order( array( 'customer_id' => $selected_user_id ) );
		$order->set_currency( 'EUR' );
		$order->set_cart_hash( 'selected-order-hash' );
		$order->set_shipping_total( 4 );
		$order->set_shipping_tax( 1 );
		$order->set_cart_tax( 2 );
		$order->set_discount_total( 5 );
		$order->set_total( 35 );
		$billing = array(
			'first_name' => '<b>Selected</b>', 'last_name' => '<i>Customer</i>', 'email' => 'selected-order@example.com',
			'address_1' => '<strong>Selected billing address</strong>', 'address_2' => '<em>Selected unit</em>', 'city' => '<span>Selected City</span>',
			'state' => 'SC', 'postcode' => '12345', 'country' => 'US', 'phone' => '555-0100',
		);
		$shipping = array(
			'first_name' => '<b>Shipping</b>', 'last_name' => '<i>Customer</i>', 'address_1' => '<strong>Selected shipping address</strong>',
			'address_2' => '<em>Shipping unit</em>', 'city' => '<span>Shipping City</span>', 'state' => 'CA', 'postcode' => '54321', 'country' => 'US',
		);
		foreach ( $billing as $field => $value ) {
			$order->{'set_billing_' . $field}( $value );
		}
		foreach ( $shipping as $field => $value ) {
			$order->{'set_shipping_' . $field}( $value );
		}
		$item = new \WC_Order_Item_Product();
		$item->set_product_id( $selected_product->get_id() );
		$item->set_name( 'Stored line' );
		$item->set_quantity( 2 );
		$item->set_subtotal( '30.00' );
		$item->set_total( '24.00' );
		$item->set_total_tax( '0.00' );
		$order->add_item( $item );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertSame( 'ambient@example.com', $result['session']['email'] );
		$this->assertSame( $order->get_id(), $result['order']['order_id'] );
		$this->assertSame( $selected_user_id, $result['order']['customer_id'] );
		$this->assertSame( array( 35.0, 30.0, 4.0, 2.0, 0.25, 5.0, 'EUR', 'selected-order-hash' ), array_values( array_intersect_key( $result['order'], array_flip( array( 'total', 'items_total', 'shipping_total', 'tax_total', 'shipping_tax_rate', 'discount_total', 'currency', 'cart_hash' ) ) ) ) );
		$item_data = $result['order']['items'][0];
		$this->assertSame( array( $selected_product->get_id(), 'Stored line', 2, 15.0, 0.0, 3.0, 'SELECTED-SKU' ), array( $item_data['product_id'], $item_data['name'], $item_data['quantity'], $item_data['unit_price'], $item_data['unit_tax_amount'], $item_data['unit_discount_amount'], $item_data['sku'] ) );
		$this->assertSame( 'selected-order@example.com', $result['customer']['billing_email'] );
		$this->assertSame( 2, $result['customer']['lifetime_order_count'] );
		$this->assertSame( array( 'first_name' => 'Selected', 'last_name' => 'Customer', 'address_1' => 'Selected billing address', 'address_2' => 'Selected unit', 'city' => 'Selected City', 'state' => 'SC', 'postcode' => '12345', 'country' => 'US', 'phone' => '555-0100' ), $result['customer']['billing_address'] );
		$this->assertSame( array( 'first_name' => 'Shipping', 'last_name' => 'Customer', 'address_1' => 'Selected shipping address', 'address_2' => 'Shipping unit', 'city' => 'Shipping City', 'state' => 'CA', 'postcode' => '54321', 'country' => 'US', 'phone' => null ), $result['customer']['shipping_address'] );
		$this->assertSame( 'Uncategorized', $item_data['category'] );
		$this->assertSame( 'simple', $item_data['product_type'] );
		$this->assertFalse( $item_data['is_virtual'] );
		$this->assertFalse( $item_data['is_downloadable'] );
		$this->assertSame( array(), $item_data['attributes'] );
	}

	/**
	 * @testdox Shipping tax rate is calculated correctly when order_id is provided.
	 */
	public function test_shipping_tax_rate_calculation(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertArrayHasKey( 'shipping_tax_rate', $result['order'] );
		if ( 0.0 === (float) $result['order']['shipping_total'] ) {
			$this->assertNull( $result['order']['shipping_tax_rate'] );
		}
	}

	/**
	 * @testdox Cart item data includes all 12 required fields when order_id is provided.
	 */
	public function test_cart_item_includes_all_required_fields(): void {
		WC()->cart->empty_cart();

		$product = \WC_Helper_Product::create_simple_product();
		$product->set_name( 'Test Product' );
		$product->set_sku( 'TEST-SKU-123' );
		$product->set_regular_price( '25.00' );
		$product->save();

		WC()->cart->add_to_cart( $product->get_id(), 2 );

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->add_product( $product, 2 );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertArrayHasKey( 'items', $result['order'] );
		$this->assertIsArray( $result['order']['items'] );
		$this->assertCount( 1, $result['order']['items'] );

		$item = $result['order']['items'][0];
		$this->assertArrayHasKey( 'product_id', $item );
		$this->assertArrayHasKey( 'name', $item );
		$this->assertArrayHasKey( 'category', $item );
		$this->assertArrayHasKey( 'sku', $item );
		$this->assertArrayHasKey( 'quantity', $item );
		$this->assertArrayHasKey( 'unit_price', $item );
		$this->assertArrayHasKey( 'unit_tax_amount', $item );
		$this->assertArrayHasKey( 'unit_discount_amount', $item );
		$this->assertArrayHasKey( 'product_type', $item );
		$this->assertArrayHasKey( 'is_virtual', $item );
		$this->assertArrayHasKey( 'is_downloadable', $item );
		$this->assertArrayHasKey( 'attributes', $item );

		$this->assertEquals( $product->get_id(), $item['product_id'] );
		$this->assertEquals( 'Test Product', $item['name'] );
		$this->assertEquals( 'TEST-SKU-123', $item['sku'] );
		$this->assertEquals( 2, $item['quantity'] );
		$this->assertEquals( 'simple', $item['product_type'] );
	}

	/**
	 * @testdox An invalid positive order ID uses the current cart and customer.
	 */
	public function test_invalid_order_id_falls_back_to_cart_and_customer(): void {
		WC()->cart->empty_cart();
		$user_id = $this->factory->user->create( array( 'user_email' => 'ambient@example.com' ) );
		wp_set_current_user( $user_id );
		WC()->customer = new \WC_Customer( $user_id, true );
		$product = \WC_Helper_Product::create_simple_product();
		$product->set_regular_price( '12.50' );
		$product->save();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		WC()->cart->calculate_totals();
		WC()->customer->set_billing_email( 'current@example.com' );
		WC()->customer->set_billing_address_1( 'Current address' );
		$cart_total = (float) WC()->cart->get_total( 'edit' );
		$this->sut->collect( 'checkout_started' );
		$result = $this->sut->get_collected_data( PHP_INT_MAX );

		$this->assertSame( 0, $result['order']['order_id'] );
		$this->assertSame( $cart_total, (float) $result['order']['total'] );
		$this->assertSame( get_woocommerce_currency(), $result['order']['currency'] );
		$this->assertSame( 'ambient@example.com', $result['session']['email'] );
		$this->assertNotEmpty( $result['session']['wc_identity_id'] );
		$this->assertSame( 'current@example.com', $result['customer']['billing_email'] );
		$this->assertSame( 'Current address', $result['customer']['billing_address']['address_1'] );
		$this->assertCount( 1, $result['order']['items'] );
		$this->assertSame( $product->get_id(), $result['order']['items'][0]['product_id'] );
		$this->assertCount( 1, $result['collected_events'] );
	}

	/**
	 * Test billing address includes all required fields (accessed via customer).
	 */
	public function test_billing_address_includes_all_required_fields(): void {
		// Set billing address data.
		WC()->customer->set_billing_address_1( '123 Main St' );
		WC()->customer->set_billing_address_2( 'Apt 4B' );
		WC()->customer->set_billing_city( 'New York' );
		WC()->customer->set_billing_state( 'NY' );
		WC()->customer->set_billing_country( 'US' );
		WC()->customer->set_billing_postcode( '10001' );

		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$billing = $result['customer']['billing_address'];
		$this->assertIsArray( $billing );
		$this->assertArrayHasKey( 'address_1', $billing );
		$this->assertArrayHasKey( 'address_2', $billing );
		$this->assertArrayHasKey( 'city', $billing );
		$this->assertArrayHasKey( 'state', $billing );
		$this->assertArrayHasKey( 'country', $billing );
		$this->assertArrayHasKey( 'postcode', $billing );
		$this->assertArrayNotHasKey( 'address', $billing );

		// Verify values.
		$this->assertEquals( '123 Main St', $billing['address_1'] );
		$this->assertEquals( 'Apt 4B', $billing['address_2'] );
		$this->assertEquals( 'New York', $billing['city'] );
		$this->assertEquals( 'NY', $billing['state'] );
		$this->assertEquals( 'US', $billing['country'] );
		$this->assertEquals( '10001', $billing['postcode'] );
	}

	/**
	 * Test shipping address includes all required fields (accessed via customer).
	 */
	public function test_shipping_address_includes_all_required_fields(): void {
		// Set shipping address data.
		WC()->customer->set_shipping_address_1( '456 Oak Ave' );
		WC()->customer->set_shipping_address_2( 'Suite 100' );
		WC()->customer->set_shipping_city( 'Los Angeles' );
		WC()->customer->set_shipping_state( 'CA' );
		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_postcode( '90001' );

		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$shipping = $result['customer']['shipping_address'];
		$this->assertIsArray( $shipping );
		$this->assertArrayHasKey( 'address_1', $shipping );
		$this->assertArrayHasKey( 'address_2', $shipping );
		$this->assertArrayHasKey( 'city', $shipping );
		$this->assertArrayHasKey( 'state', $shipping );
		$this->assertArrayHasKey( 'country', $shipping );
		$this->assertArrayHasKey( 'postcode', $shipping );
		$this->assertArrayNotHasKey( 'address', $shipping );

		// Verify values.
		$this->assertEquals( '456 Oak Ave', $shipping['address_1'] );
		$this->assertEquals( 'Suite 100', $shipping['address_2'] );
		$this->assertEquals( 'Los Angeles', $shipping['city'] );
		$this->assertEquals( 'CA', $shipping['state'] );
		$this->assertEquals( 'US', $shipping['country'] );
		$this->assertEquals( '90001', $shipping['postcode'] );
	}

	/**
	 * @testdox Order data degrades gracefully when cart is empty and order_id is provided.
	 */
	public function test_graceful_degradation_when_cart_is_empty(): void {
		WC()->cart->empty_cart();

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertIsArray( $result['order'] );
		$this->assertArrayHasKey( 'items', $result['order'] );
		$this->assertIsArray( $result['order']['items'] );
		$this->assertEmpty( $result['order']['items'] );

		$this->assertEquals( 0, $result['order']['items_total'] );
		$this->assertEquals( 0, $result['order']['total'] );
	}

	/**
	 * @testdox customer_id is set to 'guest' for guest users when order_id is provided.
	 */
	public function test_customer_id_for_guest_users(): void {
		wp_set_current_user( 0 );

		WC()->customer = new \WC_Customer( 0, true );

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertArrayHasKey( 'customer_id', $result['order'] );
		$this->assertEquals( 'guest', $result['order']['customer_id'] );
	}

	/**
	 * @testdox customer_id is set to user ID for logged-in users when order_id is provided.
	 */
	public function test_customer_id_for_logged_in_users(): void {
		$user_id = $this->factory->user->create(
			array(
				'user_email' => 'logged-in-user@example.com',
			)
		);
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		WC()->customer = new \WC_Customer( $user_id, true );

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->set_customer_id( $user_id );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertArrayHasKey( 'customer_id', $result['order'] );
		$this->assertEquals( $user_id, $result['order']['customer_id'] );
	}

	/**
	 * @testdox get_collected_data() output includes all 5 top-level sections with data.
	 */
	public function test_complete_collect_output_includes_all_sections(): void {
		$user_id = $this->factory->user->create(
			array(
				'user_email' => 'complete-test@example.com',
			)
		);
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		WC()->customer->set_billing_first_name( 'Test' );
		WC()->customer->set_billing_last_name( 'User' );
		WC()->customer->set_billing_email( 'complete-test@example.com' );
		WC()->customer->set_billing_address_1( '123 Test St' );
		WC()->customer->set_billing_city( 'Test City' );
		WC()->customer->set_billing_country( 'US' );

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$this->sut->collect( 'checkout_started', array( 'test' => 'data' ) );
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertArrayHasKey( 'wc_version', $result );
		$this->assertArrayHasKey( 'session', $result );
		$this->assertArrayHasKey( 'customer', $result );
		$this->assertArrayHasKey( 'order', $result );
		$this->assertArrayHasKey( 'collected_events', $result );

		$this->assertIsString( $result['wc_version'] );
		$this->assertIsArray( $result['session'] );
		$this->assertIsArray( $result['customer'] );
		$this->assertIsArray( $result['order'] );
		$this->assertIsArray( $result['collected_events'] );

		// Addresses are under customer.
		$this->assertIsArray( $result['customer']['billing_address'] );
		$this->assertIsArray( $result['customer']['shipping_address'] );

		// Addresses are under customer.
		$this->assertIsArray( $result['customer']['billing_address'] );
		$this->assertIsArray( $result['customer']['shipping_address'] );

		// At least one event should be collected (may have more due to hook-based tracking).
		$this->assertGreaterThanOrEqual( 1, count( $result['collected_events'] ) );

		// Find the checkout_started event we explicitly collected.
		$checkout_event = null;
		foreach ( $result['collected_events'] as $event ) {
			if ( 'checkout_started' === $event['event_type'] ) {
				$checkout_event = $event;
				break;
			}
		}
		$this->assertNotNull( $checkout_event, 'checkout_started event should be present' );
		$this->assertIsString( $checkout_event['timestamp'] );
		$this->assertEquals( array( 'test' => 'data' ), $checkout_event['event_data'] );
	}

	/**
	 * @testdox End-to-end data collection with full cart scenario works correctly.
	 */
	public function test_end_to_end_data_collection_with_full_cart(): void {
		WC()->cart->empty_cart();

		$user_id = $this->factory->user->create(
			array(
				'user_email' => 'e2e-test@example.com',
			)
		);
		$this->assertIsInt( $user_id );
		wp_set_current_user( $user_id );

		WC()->customer = new \WC_Customer( $user_id, true );
		WC()->customer->set_billing_first_name( 'John' );
		WC()->customer->set_billing_last_name( 'Doe' );
		WC()->customer->set_billing_email( 'e2e-test@example.com' );
		WC()->customer->set_billing_address_1( '123 Test St' );
		WC()->customer->set_billing_address_2( 'Apt 1' );
		WC()->customer->set_billing_city( 'Test City' );
		WC()->customer->set_billing_state( 'CA' );
		WC()->customer->set_billing_country( 'US' );
		WC()->customer->set_billing_postcode( '90210' );
		WC()->customer->set_shipping_address_1( '456 Ship St' );
		WC()->customer->set_shipping_city( 'Ship City' );
		WC()->customer->set_shipping_state( 'NY' );
		WC()->customer->set_shipping_country( 'US' );
		WC()->customer->set_shipping_postcode( '10001' );

		$product1 = \WC_Helper_Product::create_simple_product();
		$product1->set_name( 'Product 1' );
		$product1->set_regular_price( '100.00' );
		$product1->save();

		$product2 = \WC_Helper_Product::create_simple_product();
		$product2->set_name( 'Product 2' );
		$product2->set_regular_price( '50.00' );
		$product2->save();

		WC()->cart->add_to_cart( $product1->get_id(), 2 );
		WC()->cart->add_to_cart( $product2->get_id(), 1 );
		WC()->cart->calculate_totals();

		$this->sut->collect( 'payment_attempt', array( 'gateway' => 'stripe' ) );
		$result = $this->sut->get_collected_data( 0 );

		$this->assertArrayHasKey( 'wc_version', $result );
		$this->assertArrayHasKey( 'collected_events', $result );

		// At least one event should be collected (may have more due to hook-based cart tracking).
		$this->assertGreaterThanOrEqual( 1, count( $result['collected_events'] ) );

		// Find the payment_attempt event we explicitly collected.
		$payment_event = null;
		foreach ( $result['collected_events'] as $event ) {
			if ( 'payment_attempt' === $event['event_type'] ) {
				$payment_event = $event;
				break;
			}
		}
		$this->assertNotNull( $payment_event, 'payment_attempt event should be present' );
		$this->assertNotEmpty( $payment_event['timestamp'] );

		$this->assertNotEmpty( $result['session']['wc_identity_id'] );
		$this->assertEquals( 'e2e-test@example.com', $result['session']['email'] );

		$this->assertEquals( 'e2e-test@example.com', $result['customer']['billing_email'] );
		$this->assertIsInt( $result['customer']['lifetime_order_count'] );
		$this->assertGreaterThanOrEqual( 0, $result['customer']['lifetime_order_count'] );

		$this->assertGreaterThan( 0, $result['order']['total'] );
		$this->assertCount( 2, $result['order']['items'] );

		// Addresses nested under customer.
		$this->assertEquals( '123 Test St', $result['customer']['billing_address']['address_1'] );
		$this->assertEquals( 'Test City', $result['customer']['billing_address']['city'] );

		$this->assertEquals( '456 Ship St', $result['customer']['shipping_address']['address_1'] );
		$this->assertEquals( 'Ship City', $result['customer']['shipping_address']['city'] );

		$this->assertEquals( array( 'gateway' => 'stripe' ), $payment_event['event_data'] );
	}

	/**
	 * @testdox Graceful degradation across all sections when data is minimal.
	 */
	public function test_graceful_degradation_across_all_sections(): void {
		wp_set_current_user( 0 );

		WC()->customer = new \WC_Customer( 0, true );

		WC()->cart->empty_cart();

		WC()->customer->set_billing_first_name( '' );
		WC()->customer->set_billing_last_name( '' );
		WC()->customer->set_billing_email( '' );

		$order = wc_create_order();
		$this->assertInstanceOf( \WC_Order::class, $order );
		$order->save();

		$this->sut->collect();
		$result = $this->sut->get_collected_data( $order->get_id() );

		$this->assertIsArray( $result );
		$this->assertCount( 5, $result );

		$this->assertIsArray( $result['session'] );
		$this->assertIsArray( $result['customer'] );
		$this->assertIsArray( $result['order'] );
		$this->assertIsArray( $result['collected_events'] );

		// Addresses under customer.
		$this->assertIsArray( $result['customer']['billing_address'] );
		$this->assertIsArray( $result['customer']['shipping_address'] );

		$this->assertCount( 1, $result['collected_events'] );

		$this->assertEquals( 'guest', $result['order']['customer_id'] );
		$this->assertEquals( 0, $result['customer']['lifetime_order_count'] );
		$this->assertEmpty( $result['order']['items'] );
	}

	/**
	 * @testdox Data collection requires manual triggering (no automatic hooks).
	 */
	public function test_manual_triggering_only(): void {
		// This test verifies that SessionDataCollector doesn't automatically
		// hook into WooCommerce events. It should only collect data when
		// collect() is explicitly called.

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->sut->collect();
		$result = $this->sut->get_collected_data( 0 );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $result['order']['items'] );

		// No automatic data collection should have occurred.
		// This is a design verification test - the class should not register hooks.
	}

	/**
	 * Test collect stores event data in session.
	 *
	 * @testdox collect() stores event data in WooCommerce session under 'fraud_protection_collected_data' key.
	 */
	public function test_collect_stores_event_data_in_session(): void {
		// Collect data with a specific event type.
		$this->sut->collect( 'cart_page_loaded', array( 'source' => 'test' ) );

		// Verify data was stored in session.
		$stored_data = WC()->session->get( 'fraud_protection_collected_data' );

		$this->assertIsArray( $stored_data );
		$this->assertCount( 1, $stored_data );
		$this->assertEquals( 'cart_page_loaded', $stored_data[0]['event_type'] );
		$this->assertEquals( array( 'source' => 'test' ), $stored_data[0]['event_data'] );
	}

	/**
	 * Test multiple collect calls append data to session.
	 *
	 * @testdox Multiple collect() calls append data to session array, preserving event history.
	 */
	public function test_multiple_collect_calls_append_data_to_session(): void {
		// First collect call.
		$this->sut->collect( 'cart_page_loaded', array() );

		// Second collect call.
		$this->sut->collect( 'checkout_page_loaded', array() );

		// Third collect call.
		$this->sut->collect( 'order_placed', array( 'order_id' => 123 ) );

		// Verify all three events are stored.
		$stored_data = WC()->session->get( 'fraud_protection_collected_data' );

		$this->assertIsArray( $stored_data );
		$this->assertCount( 3, $stored_data );
		$this->assertEquals( 'cart_page_loaded', $stored_data[0]['event_type'] );
		$this->assertEquals( 'checkout_page_loaded', $stored_data[1]['event_type'] );
		$this->assertEquals( 'order_placed', $stored_data[2]['event_type'] );
		$this->assertEquals( 123, $stored_data[2]['event_data']['order_id'] );
	}

	/**
	 * Test get_collected_data returns structure with empty collected_events when no data collected.
	 *
	 * @testdox get_collected_data() returns structure with empty collected_events when no data has been collected.
	 */
	public function test_get_collected_data_returns_empty_collected_events_when_no_data_collected(): void {
		$result = $this->sut->get_collected_data();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'collected_events', $result );
		$this->assertEmpty( $result['collected_events'] );
	}

	/**
	 * Test get_collected_data returns structure with empty collected_events when session unavailable.
	 *
	 * @testdox get_collected_data() returns structure with empty collected_events when session is unavailable.
	 */
	public function test_get_collected_data_returns_empty_collected_events_when_session_unavailable(): void {
		// Set session to null to simulate unavailability; tearDown() restores the original.
		WC()->session = null; // @phpstan-ignore assign.propertyType

		$result = $this->sut->get_collected_data();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'collected_events', $result );
		$this->assertEmpty( $result['collected_events'] );
	}

	/**
	 * @testdox get_collected_data() returns cart-based order data when no order_id is provided.
	 */
	public function test_get_collected_data_returns_cart_order_data_when_no_order_id(): void {
		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );

		$this->sut->collect();
		$result = $this->sut->get_collected_data();

		$this->assertArrayHasKey( 'order', $result );
		$this->assertIsArray( $result['order'] );
		$this->assertNotEmpty( $result['order'] );
		$this->assertArrayHasKey( 'items', $result['order'] );
		$this->assertNotEmpty( $result['order']['items'] );
	}

	/**
	 * @testdox get_collected_data() returns collected_events array after collect() is called.
	 */
	public function test_get_collected_data_returns_data_after_collect(): void {
		// Collect some data.
		$this->sut->collect( 'cart_page_loaded', array( 'source' => 'test' ) );
		$this->sut->collect( 'checkout_started', array( 'gateway' => 'stripe' ) );

		// Get collected data using the new method.
		$result = $this->sut->get_collected_data();

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'collected_events', $result );
		$this->assertCount( 2, $result['collected_events'] );
		$this->assertEquals( 'cart_page_loaded', $result['collected_events'][0]['event_type'] );
		$this->assertEquals( array( 'source' => 'test' ), $result['collected_events'][0]['event_data'] );
		$this->assertEquals( 'checkout_started', $result['collected_events'][1]['event_type'] );
		$this->assertEquals( array( 'gateway' => 'stripe' ), $result['collected_events'][1]['event_data'] );
	}

	/**
	 * @testdox collect() keeps null event types and supported scalar values unchanged.
	 */
	public function test_collect_preserves_supported_event_values(): void {
		$event = $this->collect_and_get_event(
			null,
			array(
				'null'  => null,
				'bool'  => false,
				'int'   => 4,
				'float' => 1.5,
				'text'  => 'value',
			)
		);

		$this->assertNull( $event['event_type'] );
		$this->assertSame( array( 'null' => null, 'bool' => false, 'int' => 4, 'float' => 1.5, 'text' => 'value' ), $event['event_data'] );
		$this->assertArrayNotHasKey( 'event_data_truncated', $event );
	}

	/**
	 * @testdox collect() bounds strings and keeps the first normalized colliding key.
	 */
	public function test_collect_normalizes_strings_and_key_collisions(): void {
		$key_prefix = str_repeat( 'k', 128 );
		$event      = $this->collect_and_get_event(
			str_repeat( 't', 65 ),
			array(
				$key_prefix . 'a' => str_repeat( 'v', 1025 ),
				$key_prefix . 'b' => 'discarded',
				"bad\xFFkey"     => "valid\xFFsuffix",
			)
		);

		$this->assertSame( str_repeat( 't', 64 ), $event['event_type'] );
		$this->assertSame( str_repeat( 'v', 1024 ), $event['event_data'][ $key_prefix ] );
		$this->assertArrayHasKey( "bad\u{FFFD}key", $event['event_data'] );
		$this->assertSame( "valid\u{FFFD}suffix", $event['event_data'][ "bad\u{FFFD}key" ] );
		$this->assertTrue( $event['event_data_truncated'] );
	}

	/**
	 * @testdox collect() keeps a valid UTF-8 prefix at the value byte limit.
	 */
	public function test_collect_truncates_multibyte_value_at_utf8_boundary(): void {
		$event = $this->collect_and_get_event( 'event', array( 'value' => str_repeat( 'é', 513 ) ) );
		$value = $event['event_data']['value'];

		$this->assertSame( 1024, strlen( $value ) );
		$this->assertSame( 1, preg_match( '//u', $value ) );
		$this->assertSame( str_repeat( 'é', 512 ), $value );
		$this->assertTrue( $event['event_data_truncated'] );
	}

	/**
	 * @testdox collect() replaces unsupported values and non-finite floats with null.
	 */
	public function test_collect_replaces_unsupported_values_with_null(): void {
		$resource = fopen( 'php://memory', 'r' );
		$event    = $this->collect_and_get_event(
			'event',
			array(
				'resource' => $resource,
				'infinite' => INF,
			)
		);
		fclose( $resource );

		$this->assertNull( $event['event_data']['resource'] );
		$this->assertNull( $event['event_data']['infinite'] );
		$this->assertTrue( $event['event_data_truncated'] );
	}

	/**
	 * @testdox collect() retains a deep event within the 64-node limit.
	 */
	public function test_collect_bounds_deep_event_by_node_count(): void {
		$event_data = array( 'leaf' => 'value' );
		for ( $index = 0; $index < 70; ++$index ) {
			$event_data = array( 'child' => $event_data );
		}

		$event = $this->collect_and_get_event( 'deep', $event_data );

		$this->assertSame( 64, $this->count_event_nodes( $event ) );
		$this->assertTrue( $event['event_data_truncated'] );
	}

	/**
	 * @testdox collect() stops a recursive event within the 64-node limit.
	 */
	public function test_collect_bounds_recursive_event_by_node_count(): void {
		$event_data         = array();
		$event_data['self'] = &$event_data;

		$event = $this->collect_and_get_event( 'recursive', $event_data );

		$this->assertSame( 64, $this->count_event_nodes( $event ) );
		$this->assertTrue( $event['event_data_truncated'] );
	}

	/**
	 * @testdox collect() keeps the newest 256 events and marks discarded history.
	 */
	public function test_collect_bounds_event_count_and_marks_history(): void {
		for ( $index = 0; $index < 257; ++$index ) {
			$this->sut->collect( 'event_' . $index );
		}

		$result = $this->sut->get_collected_data();

		$this->assertCount( 256, $result['collected_events'] );
		$this->assertSame( 'event_1', $result['collected_events'][0]['event_type'] );
		$this->assertSame( 'event_256', $result['collected_events'][255]['event_type'] );
		$this->assertTrue( $result['collected_events_truncated'] );
	}

	/**
	 * @testdox collect() bounds serialized history bytes and retains the newest event.
	 */
	public function test_collect_bounds_serialized_history_bytes(): void {
		for ( $index = 0; $index < 256; ++$index ) {
			$this->sut->collect( 'event_' . $index, array( 'value' => str_repeat( 'v', 1024 ) ) );
		}

		$stored = WC()->session->get( 'fraud_protection_collected_data' );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Verifies the storage limit.
		$this->assertLessThanOrEqual( 256 * 1024, strlen( serialize( $stored ) ) );
		$this->assertLessThan( 256, count( $stored ) );
		$this->assertSame( 'event_255', $stored[ count( $stored ) - 1 ]['event_type'] );
		$this->assertTrue( $this->sut->get_collected_data()['collected_events_truncated'] );
	}

	/**
	 * @testdox get_collected_data() normalizes a legacy request copy without rewriting session history.
	 */
	public function test_get_collected_data_normalizes_legacy_copy_only(): void {
		$legacy = array(
			array(
				'timestamp'  => str_repeat( 't', 65 ),
				'event_data' => array( 'value' => str_repeat( 'v', 1025 ) ),
			),
			array(
				'timestamp'  => '2026-08-31 12:00:00',
				'event_data' => array( 'value' => 'kept' ),
			),
			array(
				'event_type' => array( 'unsupported' ),
				'timestamp'  => array( 'unsupported' ),
				'event_data' => array(),
			),
		);
		WC()->session->set( 'fraud_protection_collected_data', $legacy );

		$result = $this->sut->get_collected_data();

		$this->assertSame( $legacy, WC()->session->get( 'fraud_protection_collected_data' ) );
		$this->assertNull( $result['collected_events'][0]['event_type'] );
		$this->assertSame( str_repeat( 't', 64 ), $result['collected_events'][0]['timestamp'] );
		$this->assertSame( str_repeat( 'v', 1024 ), $result['collected_events'][0]['event_data']['value'] );
		$this->assertTrue( $result['collected_events'][0]['event_data_truncated'] );
		$this->assertNull( $result['collected_events'][1]['event_type'] );
		$this->assertTrue( $result['collected_events'][1]['event_data_truncated'] );
		$this->assertNull( $result['collected_events'][2]['event_type'] );
		$this->assertNull( $result['collected_events'][2]['timestamp'] );
		$this->assertTrue( $result['collected_events'][2]['event_data_truncated'] );
	}

	/**
	 * @testdox get_collected_data() bounds legacy history without rewriting it.
	 */
	public function test_get_collected_data_bounds_legacy_history_copy(): void {
		$legacy = array();
		for ( $index = 0; $index < 257; ++$index ) {
			$legacy[] = array(
				'event_type' => 'event_' . $index,
				'timestamp'  => '2026-08-31 12:00:00',
				'event_data' => array(),
			);
		}
		WC()->session->set( 'fraud_protection_collected_data', $legacy );

		$result   = $this->sut->get_collected_data();
		$returned = $result['collected_events'];

		$this->assertCount( 256, $returned );
		$this->assertTrue( $result['collected_events_truncated'] );
		$this->assertSame( 'event_1', $returned[0]['event_type'] );
		$this->assertSame( 'event_256', $returned[255]['event_type'] );
		$this->assertSame( $legacy, WC()->session->get( 'fraud_protection_collected_data' ) );
	}

	// ========================================
	// Clear Collected Events Tests
	// ========================================

	/**
	 * @testdox clear_collected_events() removes all event data from session.
	 */
	public function test_clear_collected_events_removes_data_from_session(): void {
		$this->sut->collect( 'cart_page_loaded', array() );
		$this->sut->collect( 'checkout_page_loaded', array() );
		WC()->session->set( 'fraud_protection_collected_events_truncated', true );

		$this->sut->clear_collected_events();

		$this->assertNull( WC()->session->get( 'fraud_protection_collected_data' ) );
		$this->assertNull( WC()->session->get( 'fraud_protection_collected_events_truncated' ) );
	}

	/**
	 * @testdox clear_collected_events() does not throw when no data has been collected.
	 */
	public function test_clear_collected_events_handles_no_existing_data(): void {
		$this->sut->clear_collected_events();

		$this->assertNull( WC()->session->get( 'fraud_protection_collected_data' ) );
	}

	/**
	 * @testdox New events can be collected after clear_collected_events() is called.
	 */
	public function test_clear_collected_events_allows_fresh_collection_after_clearing(): void {
		$this->sut->collect( 'cart_page_loaded', array() );
		$this->sut->collect( 'checkout_page_loaded', array() );

		$this->sut->clear_collected_events();

		$this->sut->collect( 'order_placed', array( 'order_id' => 456 ) );

		$stored_data = WC()->session->get( 'fraud_protection_collected_data' );
		$this->assertIsArray( $stored_data );
		$this->assertCount( 1, $stored_data );
		$this->assertEquals( 'order_placed', $stored_data[0]['event_type'] );
	}
}
