<?php
/**
 * CheckoutEventTrackerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\CheckoutEventTracker;
use Automattic\WooCommerce\FraudProtection\SessionDataCollector;

/**
 * Tests for CheckoutEventTracker.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\CheckoutEventTracker
 */
class CheckoutEventTrackerTest extends \WC_Unit_Test_Case {

	/**
	 * The system under test.
	 *
	 * @var CheckoutEventTracker
	 */
	private $sut;

	/**
	 * Mock session data collector.
	 *
	 * @var SessionDataCollector|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mock_collector;

	/**
	 * Runs before each test.
	 */
	public function setUp(): void {
		parent::setUp();

		// Ensure WooCommerce cart and session are available.
		if ( ! did_action( 'woocommerce_load_cart_from_session' ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		// Create mock.
		$this->mock_collector = $this->createMock( SessionDataCollector::class );

		// Create system under test.
		$this->sut = new CheckoutEventTracker();
		$this->sut->init( $this->mock_collector );
	}

	// ========================================
	// Hook Registration Tests
	// ========================================

	/**
	 * @testdox register() registers all checkout event tracking hooks.
	 */
	public function test_register_registers_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_order_processed', array( $this->sut, 'track_order_placed_from_shortcode' ) ),
			'woocommerce_checkout_order_processed hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_store_api_checkout_order_processed', array( $this->sut, 'track_order_placed_from_store_api' ) ),
			'woocommerce_store_api_checkout_order_processed hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_update_order_review', array( $this->sut, 'track_shortcode_checkout_field_update' ) ),
			'woocommerce_checkout_update_order_review hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this->sut, 'track_blocks_checkout_update' ) ),
			'woocommerce_store_api_checkout_update_customer_from_request hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'template_redirect', array( $this->sut, 'track_checkout_page_loaded' ) ),
			'template_redirect hook should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_before_pay_action', array( $this->sut, 'track_order_paid_via_pay_for_order' ) ),
			'woocommerce_before_pay_action hook should be registered'
		);
	}

	// ========================================
	// Checkout Page Load Tests
	// ========================================

	/**
	 * @testdox track_checkout_page_loaded() collects session data when on checkout page.
	 */
	public function test_track_checkout_page_loaded_collects_data_on_checkout(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'checkout_page_loaded' ),
				$this->equalTo( array() )
			);

		$this->sut->track_checkout_page_loaded();

		remove_filter( 'woocommerce_is_checkout', '__return_true' );
	}

	/**
	 * @testdox track_checkout_page_loaded() does not collect session data when not on checkout page.
	 */
	public function test_track_checkout_page_loaded_does_not_collect_when_not_checkout(): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_checkout_page_loaded();
	}

	/**
	 * @testdox track_checkout_page_loaded() collects pay_for_order_page_loaded (not checkout_page_loaded) on the pay-for-order page.
	 */
	public function test_track_checkout_page_loaded_collects_pay_for_order_event(): void {
		global $wp;
		$wp->query_vars['order-pay'] = 123;
		add_filter( 'woocommerce_is_checkout', '__return_true' );

		$collected_events = array();
		$this->mock_collector
			->expects( $this->atLeast( 1 ) )
			->method( 'collect' )
			->willReturnCallback(
				function ( $event_type, $event_data ) use ( &$collected_events ) {
					$collected_events[] = $event_type;
					return array();
				}
			);

		$this->sut->track_checkout_page_loaded();

		$this->assertContains( 'pay_for_order_page_loaded', $collected_events, 'pay_for_order_page_loaded event should be collected on the pay-for-order page' );
		$this->assertNotContains( 'checkout_page_loaded', $collected_events, 'checkout_page_loaded event should NOT be collected on the pay-for-order page' );

		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		unset( $wp->query_vars['order-pay'] );
	}

	// ========================================
	// Pay-for-Order Event Tests
	// ========================================

	/**
	 * @testdox track_order_paid_via_pay_for_order() registers the woocommerce_payment_complete listener.
	 */
	public function test_track_order_paid_via_pay_for_order_registers_payment_complete_hook(): void {
		$order = \WC_Helper_Order::create_order();

		$this->sut->track_order_paid_via_pay_for_order( $order );

		$this->assertNotFalse(
			has_action( 'woocommerce_payment_complete', array( $this->sut, 'track_payment_complete' ) ),
			'woocommerce_payment_complete hook should be registered after track_order_paid_via_pay_for_order'
		);

		remove_action( 'woocommerce_payment_complete', array( $this->sut, 'track_payment_complete' ), 10 );
		$order->delete( true );
	}

	/**
	 * @testdox track_payment_complete() collects order_placed event with order details.
	 */
	public function test_track_payment_complete_collects_order_placed_event(): void {
		$order = \WC_Helper_Order::create_order();

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'order_placed' ),
				$this->callback(
					function ( $event_data ) use ( $order ) {
						return $event_data['order_id'] === $order->get_id()
							&& isset( $event_data['payment_method'] )
							&& isset( $event_data['total'] )
							&& isset( $event_data['currency'] )
							&& isset( $event_data['customer_id'] )
							&& isset( $event_data['status'] );
					}
				)
			);

		$this->sut->track_payment_complete( $order->get_id() );

		$order->delete( true );
	}

	/**
	 * @testdox track_payment_complete() does nothing when order ID is invalid.
	 */
	public function test_track_payment_complete_bails_on_invalid_order(): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_payment_complete( 999999 );
	}

	// ========================================
	// Blocks Checkout Tests
	// ========================================

	/**
	 * Test blocks checkout update collects data.
	 *
	 * @testdox track_blocks_checkout_update() collects session data with empty event data.
	 */
	public function test_track_blocks_checkout_update_collects_data(): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'checkout_update' ),
				$this->equalTo( array() )
			);

		$this->sut->track_blocks_checkout_update();
	}

	// ========================================
	// Shortcode Checkout Tests
	// ========================================

	/**
	 * Test shortcode checkout field update collects data on billing country change.
	 *
	 * @testdox track_shortcode_checkout_field_update() collects data when billing country changes.
	 */
	public function test_track_shortcode_checkout_field_update_collects_data_on_billing_country_change(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( 'CA' );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( null );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'checkout_update' ),
				$this->callback(
					function ( $event_data ) {
						return isset( $event_data['action'] )
							&& 'field_update' === $event_data['action']
							&& isset( $event_data['billing_email'] )
							&& 'test@example.com' === $event_data['billing_email'];
					}
				)
			);

		$posted_data = 'billing_email=test@example.com&billing_first_name=John&billing_last_name=Doe&billing_country=US';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );
	}

	/**
	 * Test shortcode checkout field update extracts billing fields.
	 *
	 * @testdox track_shortcode_checkout_field_update() extracts billing fields correctly.
	 */
	public function test_track_shortcode_checkout_field_update_extracts_billing_fields(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( 'CA' );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( null );

		$captured_event_data = null;
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->willReturnCallback(
				function ( $event_type, $event_data ) use ( &$captured_event_data ) {
					$captured_event_data = $event_data;
					return array();
				}
			);

		$posted_data = 'billing_email=test@example.com&billing_first_name=John&billing_last_name=Doe&billing_country=US&billing_city=New+York';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );

		$this->assertNotNull( $captured_event_data );
		$this->assertEquals( 'field_update', $captured_event_data['action'] );
		$this->assertEquals( 'test@example.com', $captured_event_data['billing_email'] );
		$this->assertEquals( 'John', $captured_event_data['billing_first_name'] );
		$this->assertEquals( 'Doe', $captured_event_data['billing_last_name'] );
		$this->assertEquals( 'US', $captured_event_data['billing_country'] );
		$this->assertEquals( 'New York', $captured_event_data['billing_city'] );
	}

	/**
	 * Test shortcode checkout field update extracts shipping fields.
	 *
	 * @testdox track_shortcode_checkout_field_update() extracts shipping fields when ship_to_different_address is set.
	 */
	public function test_track_shortcode_checkout_field_update_extracts_shipping_fields(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( null );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( 'CA' );

		$captured_event_data = null;
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->willReturnCallback(
				function ( $event_type, $event_data ) use ( &$captured_event_data ) {
					$captured_event_data = $event_data;
					return array();
				}
			);

		$posted_data = 'billing_email=test@example.com&ship_to_different_address=1&shipping_first_name=Jane&shipping_last_name=Smith&shipping_city=Los+Angeles&shipping_country=US';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );

		$this->assertNotNull( $captured_event_data );
		$this->assertEquals( 'Jane', $captured_event_data['shipping_first_name'] );
		$this->assertEquals( 'Smith', $captured_event_data['shipping_last_name'] );
		$this->assertEquals( 'Los Angeles', $captured_event_data['shipping_city'] );
	}

	/**
	 * Test shortcode checkout field update skips shipping fields when not different address.
	 *
	 * @testdox track_shortcode_checkout_field_update() skips shipping fields when not shipping to different address.
	 */
	public function test_track_shortcode_checkout_field_update_skips_shipping_fields_when_not_different_address(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( 'CA' );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( null );

		$captured_event_data = null;
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->willReturnCallback(
				function ( $event_type, $event_data ) use ( &$captured_event_data ) {
					$captured_event_data = $event_data;
					return array();
				}
			);

		$posted_data = 'billing_email=test@example.com&billing_country=US&shipping_first_name=Jane&shipping_last_name=Smith';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );

		$this->assertNotNull( $captured_event_data );
		$this->assertArrayNotHasKey( 'shipping_first_name', $captured_event_data );
		$this->assertArrayNotHasKey( 'shipping_last_name', $captured_event_data );
	}

	// ========================================
	// Country Change Detection Tests
	// ========================================

	/**
	 * Test no collection when no country changes.
	 *
	 * @testdox Event is NOT collected when neither country changes.
	 */
	public function test_no_collection_when_no_country_changes(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( 'US' );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( 'US' );

		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$posted_data = 'billing_email=test@example.com&billing_country=US&shipping_country=US';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );
	}

	/**
	 * Test no collection when only non-country fields change.
	 *
	 * @testdox Event is NOT collected when only non-country fields change.
	 */
	public function test_no_collection_when_only_non_country_fields_change(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( 'US' );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( null );

		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$posted_data = 'billing_email=test@example.com&billing_first_name=John&billing_phone=1234567890';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );
	}

	/**
	 * Test collection when billing country changes from null.
	 *
	 * @testdox Event is collected when billing country changes from null.
	 */
	public function test_collection_when_billing_country_changes_from_null(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( null );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( null );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' );

		$posted_data = 'billing_email=test@example.com&billing_country=US';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );
	}

	/**
	 * Test collection when ship_to_different_address unchecked with different countries.
	 *
	 * @testdox Event is collected when ship_to_different_address unchecked with different countries.
	 */
	public function test_collection_when_ship_to_different_address_unchecked_with_different_countries(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willReturn( 'US' );

		$this->mock_collector
			->method( 'get_current_shipping_country' )
			->willReturn( 'CA' );

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'checkout_update' ),
				$this->anything()
			);

		$posted_data = 'billing_country=US&billing_email=test@example.com';
		$this->sut->track_shortcode_checkout_field_update( $posted_data );
	}

	// ========================================
	// Order Placed Tests
	// ========================================

	/**
	 * Test track order placed collects data.
	 *
	 * @testdox track_order_placed() collects session data with order details.
	 */
	public function test_track_order_placed_collects_data(): void {
		$order = \WC_Helper_Order::create_order();

		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->with(
				$this->equalTo( 'order_placed' ),
				$this->callback(
					function ( $event_data ) use ( $order ) {
						$this->assertArrayHasKey( 'order_id', $event_data );
						$this->assertEquals( $order->get_id(), $event_data['order_id'] );
						$this->assertArrayHasKey( 'payment_method', $event_data );
						$this->assertArrayHasKey( 'total', $event_data );
						$this->assertArrayHasKey( 'currency', $event_data );
						$this->assertArrayHasKey( 'customer_id', $event_data );
						$this->assertArrayHasKey( 'status', $event_data );
						return true;
					}
				)
			);

		$this->sut->track_order_placed( $order->get_id(), $order );

		$order->delete( true );
	}
}
