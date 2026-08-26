<?php
/**
 * CheckoutEventTrackerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionLoggerForTests;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\CheckoutEventTracker;

/**
 * Tests for CheckoutEventTracker.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\CheckoutEventTracker
 */
class CheckoutEventTrackerTest extends FraudProtectionUnitTestCase {

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
	 * In-memory logger injected into the system under test.
	 *
	 * @var FraudProtectionLoggerForTests
	 */
	private FraudProtectionLoggerForTests $logger;

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
		$this->logger         = new FraudProtectionLoggerForTests();

		// Create system under test.
		$this->sut = new CheckoutEventTracker();
		$this->sut->init( $this->mock_collector, $this->logger );
	}

	/**
	 * Track a shortcode checkout update and return its event data.
	 *
	 * @param string $posted_data Serialized checkout form data.
	 * @return array Collected event data.
	 */
	private function capture_shortcode_update_event( string $posted_data ): array {
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
			->with( 'checkout_update', $this->isType( 'array' ) )
			->willReturnCallback(
				function ( $event_type, $event_data ) use ( &$captured_event_data ): void {
					$captured_event_data = $event_data;
				}
			);

		$this->sut->track_shortcode_checkout_field_update( $posted_data );

		$this->assertIsArray( $captured_event_data );

		return $captured_event_data;
	}

	/**
	 * Make event collection fail.
	 */
	private function make_collection_fail(): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'collect' )
			->willThrowException( new \RuntimeException( 'collector failed' ) );
	}

	/**
	 * Assert the tracker logged a contained callback failure.
	 *
	 * @param string $hook Registered hook name.
	 */
	private function assert_tracker_failure_logged( string $hook, string $exception_class = \RuntimeException::class ): void {
		$this->assertCount( 1, $this->logger->entries );
		$entry = $this->logger->entries[0];
		$this->assertSame( 'error', $entry['level'] );
		$this->assertTrue( $entry['forwarded'] );
		$this->assertSame( 'checkout_event_tracker', $entry['context']['event_source'] );
		$this->assertSame( $hook, $entry['context']['hook'] );
		$this->assertSame( $exception_class, $entry['context']['exception_class'] );
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
			has_action( 'woocommerce_order_status_changed', array( $this->sut, 'clear_events_on_successful_payment' ) ),
			'woocommerce_order_status_changed hook should be registered'
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

	/**
	 * @testdox The checkout page callback contains a collector failure.
	 */
	public function test_checkout_page_callback_contains_collector_failure(): void {
		add_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->make_collection_fail();

		$this->sut->track_checkout_page_loaded();

		remove_filter( 'woocommerce_is_checkout', '__return_true' );
		$this->assert_tracker_failure_logged( 'template_redirect' );
	}

	/**
	 * @testdox The Blocks checkout update callback contains a collector failure.
	 */
	public function test_blocks_checkout_update_callback_contains_collector_failure(): void {
		$this->make_collection_fail();

		$this->sut->track_blocks_checkout_update();

		$this->assert_tracker_failure_logged( 'woocommerce_store_api_checkout_update_customer_from_request' );
	}

	/**
	 * @testdox The shortcode checkout update callback contains a collector failure.
	 */
	public function test_shortcode_checkout_update_callback_contains_collector_failure(): void {
		$this->make_collection_fail();

		$this->sut->track_shortcode_checkout_field_update( 'billing_country=US' );

		$this->assert_tracker_failure_logged( 'woocommerce_checkout_update_order_review' );
	}

	/**
	 * @testdox The shortcode order callback contains a collector failure.
	 */
	public function test_shortcode_order_callback_contains_collector_failure(): void {
		$order = wc_create_order();
		$this->make_collection_fail();

		$this->sut->track_order_placed_from_shortcode( $order->get_id(), array(), $order );

		$this->assert_tracker_failure_logged( 'woocommerce_checkout_order_processed' );
	}

	/**
	 * @testdox The Store API order callback contains a collector failure.
	 */
	public function test_store_api_order_callback_contains_collector_failure(): void {
		$order = wc_create_order();
		$this->make_collection_fail();

		$this->sut->track_order_placed_from_store_api( $order );

		$this->assert_tracker_failure_logged( 'woocommerce_store_api_checkout_order_processed' );
	}

	/**
	 * @testdox The successful payment callback contains a collector failure.
	 */
	public function test_successful_payment_callback_contains_collector_failure(): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'clear_collected_events' )
			->willThrowException( new \RuntimeException( 'collector failed' ) );

		$this->sut->clear_events_on_successful_payment( 1, 'pending', 'processing' );

		$this->assert_tracker_failure_logged( 'woocommerce_order_status_changed' );
	}

	/**
	 * @testdox The shortcode order callback contains a malformed order ID.
	 */
	public function test_shortcode_order_hook_contains_malformed_order_id(): void {
		$order = $this->createMock( \WC_Order::class );

		$this->sut->track_order_placed_from_shortcode( 'invalid-order-id', array(), $order );

		$this->assert_tracker_failure_logged( 'woocommerce_checkout_order_processed', \TypeError::class );
	}

	/**
	 * @testdox The Store API order callback contains a malformed order.
	 */
	public function test_store_api_order_hook_contains_malformed_order(): void {
		$this->sut->track_order_placed_from_store_api( array() );

		$this->assert_tracker_failure_logged( 'woocommerce_store_api_checkout_order_processed', \Error::class );
	}

	/**
	 * @testdox The successful payment callback contains a malformed status.
	 */
	public function test_successful_payment_hook_contains_malformed_status(): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'clear_collected_events' );

		$this->sut->clear_events_on_successful_payment( 1, array(), 'processing' );

		$this->assertSame( array(), $this->logger->entries );
	}

	/**
	 * @testdox A checkout tracker failure before collection is contained and logged.
	 */
	public function test_tracker_pre_collection_failure_is_contained(): void {
		$this->mock_collector
			->method( 'get_current_billing_country' )
			->willThrowException( new \RuntimeException( 'country lookup failed' ) );

		$this->sut->track_shortcode_checkout_field_update( 'billing_country=US' );

		$this->assertCount( 1, $this->logger->entries );
		$this->assertSame( 'woocommerce_checkout_update_order_review', $this->logger->entries[0]['context']['hook'] );
		$this->assertSame( \RuntimeException::class, $this->logger->entries[0]['context']['exception_class'] );
		$this->assertTrue( $this->logger->entries[0]['forwarded'] );
	}

	// ========================================
	// Shortcode Checkout Tests
	// ========================================

	/**
	 * @testdox The shortcode update callback ignores a non-string post_data value.
	 */
	public function test_shortcode_update_callback_ignores_non_string_post_data(): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'collect' );

		$this->sut->track_shortcode_checkout_field_update( array( 'billing_country' => 'US' ) );

		$this->assertSame( array(), $this->logger->entries );
	}

	/**
	 * @testdox The shortcode update callback omits an array-valued field and keeps valid fields.
	 *
	 * @dataProvider malformed_shortcode_field_provider
	 *
	 * @param string $posted_data   Serialized checkout form data.
	 * @param array  $expected_event Expected collected event data.
	 */
	public function test_shortcode_update_callback_omits_malformed_field_and_keeps_valid_fields( string $posted_data, array $expected_event ): void {
		if ( isset( $expected_event['payment'] ) ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			$this->assertArrayHasKey( 'cod', $gateways );
			$expected_event['payment']['payment_gateway_name'] = $gateways['cod']->get_title();
		}

		$this->assertSame( $expected_event, $this->capture_shortcode_update_event( $posted_data ) );
		$this->assertSame( array(), $this->logger->entries );
	}

	/**
	 * Malformed shortcode checkout fields and their expected events.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public function malformed_shortcode_field_provider(): array {
		$event_with_payment = array(
			'action'          => 'field_update',
			'billing_country' => 'US',
			'billing_city'    => 'Paris',
			'payment'         => array( 'payment_gateway_type' => 'cod' ),
		);

		return array(
			'array billing email' => array(
				'billing_email[]=bad&billing_country=US&billing_city=Paris&payment_method=cod',
				$event_with_payment,
			),
			'array fallback email' => array(
				'email[]=bad&billing_country=US&billing_city=Paris&payment_method=cod',
				$event_with_payment,
			),
			'array payment method' => array(
				'billing_email=test%40example.com&billing_country=US&billing_city=Paris&payment_method[]=cod',
				array(
					'action'          => 'field_update',
					'billing_email'   => 'test@example.com',
					'billing_country' => 'US',
					'billing_city'    => 'Paris',
				),
			),
			'array mapped text field' => array(
				'billing_first_name[]=bad&billing_country=US&billing_city=Paris&payment_method=cod',
				$event_with_payment,
			),
		);
	}

	/**
	 * Test shortcode checkout field update collects data on billing country change.
	 *
	 * @testdox track_shortcode_checkout_field_update() collects data when billing country changes.
	 */
	public function test_track_shortcode_checkout_field_update_collects_data_on_billing_country_change(): void {
		$gateways = WC()->payment_gateways()->payment_gateways();
		$this->assertArrayHasKey( 'cod', $gateways );

		$this->assertSame(
			array(
				'action'          => 'field_update',
				'billing_country' => 'US',
				'email'           => 'test@example.com',
				'payment'         => array(
					'payment_gateway_type' => 'cod',
					'payment_gateway_name' => $gateways['cod']->get_title(),
				),
			),
			$this->capture_shortcode_update_event( 'email=test%40example.com&billing_country=US&payment_method=cod' )
		);
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

	// ========================================
	// Event Clearing on Successful Payment Tests
	// ========================================

	/**
	 * Provide status transitions that should trigger event clearing.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function successful_checkout_transitions(): array {
		return array(
			'checkout-draft → processing (pay-for-order link)' => array( 'checkout-draft', 'processing' ),
			'checkout-draft → completed (pay-for-order link)'  => array( 'checkout-draft', 'completed' ),
			'checkout-draft → on-hold (pay-for-order link)'    => array( 'checkout-draft', 'on-hold' ),
			'pending → processing (online gateway)'            => array( 'pending', 'processing' ),
			'pending → completed (virtual product)'            => array( 'pending', 'completed' ),
			'pending → on-hold (offline gateway)'              => array( 'pending', 'on-hold' ),
			'failed → processing (pay-for-order)'              => array( 'failed', 'processing' ),
			'failed → completed (pay-for-order)'               => array( 'failed', 'completed' ),
			'failed → on-hold (pay-for-order offline)'         => array( 'failed', 'on-hold' ),
		);
	}

	/**
	 * Provide status transitions that should NOT trigger event clearing.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function non_clearing_transitions(): array {
		return array(
			'pending → failed (payment failure)'       => array( 'pending', 'failed' ),
			'pending → cancelled'                      => array( 'pending', 'cancelled' ),
			'processing → completed (admin/lifecycle)' => array( 'processing', 'completed' ),
			'on-hold → processing (admin action)'      => array( 'on-hold', 'processing' ),
		);
	}

	/**
	 * @testdox clear_events_on_successful_payment() clears events on successful checkout transitions.
	 * @dataProvider successful_checkout_transitions
	 */
	public function test_clear_events_on_successful_payment_clears( string $old_status, string $new_status ): void {
		$this->mock_collector
			->expects( $this->once() )
			->method( 'clear_collected_events' );

		$this->sut->clear_events_on_successful_payment( 1, $old_status, $new_status );
	}

	/**
	 * @testdox clear_events_on_successful_payment() does NOT clear events for non checkout or unsuccessful transitions.
	 * @dataProvider non_clearing_transitions
	 */
	public function test_clear_events_on_successful_payment_skips( string $old_status, string $new_status ): void {
		$this->mock_collector
			->expects( $this->never() )
			->method( 'clear_collected_events' );

		$this->sut->clear_events_on_successful_payment( 1, $old_status, $new_status );
	}
}
