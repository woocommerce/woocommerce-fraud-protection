<?php
/**
 * SessionBlockingHandlerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionBlockingHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionClearanceManager;

/**
 * Tests for the SessionBlockingHandler class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionBlockingHandler
 */
class SessionBlockingHandlerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionBlockingHandler
	 */
	private $sut;

	/**
	 * Mock session manager.
	 *
	 * @var SessionClearanceManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_manager_mock;

	/**
	 * Mock blocked notice.
	 *
	 * @var BlockedSessionNotice|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_notice_mock;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_manager_mock = $this->createMock( SessionClearanceManager::class );
		$this->blocked_notice_mock  = $this->createMock( BlockedSessionNotice::class );

		$this->sut = new SessionBlockingHandler();
		$this->sut->init( $this->session_manager_mock, $this->blocked_notice_mock );

		wc_clear_notices();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_filter( 'woocommerce_add_to_cart_validation', array( $this->sut, 'validate_add_to_cart' ), 1 );
		remove_filter( 'woocommerce_available_payment_gateways', array( $this->sut, 'filter_payment_gateways' ), 999 );
		remove_filter( 'rest_pre_dispatch', array( $this->sut, 'filter_store_api_requests' ), 10 );

		wc_clear_notices();
		parent::tearDown();
	}

	/**
	 * Create a mock WP_REST_Request.
	 *
	 * @param string $route  The request route.
	 * @param string $method The HTTP method.
	 * @return \WP_REST_Request|\PHPUnit\Framework\MockObject\MockObject
	 */
	private function create_rest_request( string $route, string $method ) {
		$request = $this->createMock( \WP_REST_Request::class );
		$request->method( 'get_route' )->willReturn( $route );
		$request->method( 'get_method' )->willReturn( $method );

		return $request;
	}

	/**
	 * @testdox register should add all blocking filters.
	 */
	public function test_register_adds_all_filters(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_filter( 'woocommerce_add_to_cart_validation', array( $this->sut, 'validate_add_to_cart' ) ),
			'Should register woocommerce_add_to_cart_validation filter'
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_available_payment_gateways', array( $this->sut, 'filter_payment_gateways' ) ),
			'Should register woocommerce_available_payment_gateways filter'
		);
		$this->assertNotFalse(
			has_filter( 'rest_pre_dispatch', array( $this->sut, 'filter_store_api_requests' ) ),
			'Should register rest_pre_dispatch filter'
		);
	}

	/**
	 * @testdox validate_add_to_cart should return false and add notice when session is blocked.
	 */
	public function test_validate_add_to_cart_returns_false_when_blocked(): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( true );
		$this->blocked_notice_mock->expects( $this->once() )
			->method( 'get_message_html' )
			->with( 'purchase' )
			->willReturn( 'Blocked message' );

		$result = $this->sut->validate_add_to_cart( true, 1, 1 );

		$this->assertFalse( $result, 'Should return false when session is blocked' );
		$this->assertTrue( wc_has_notice( 'Blocked message', 'error' ), 'Should add an error notice when blocked' );
	}

	/**
	 * @testdox validate_add_to_cart should pass through original value when session is not blocked.
	 */
	public function test_validate_add_to_cart_passes_through_when_not_blocked(): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( false );

		$this->assertTrue(
			$this->sut->validate_add_to_cart( true, 1, 1 ),
			'Should return true when passed is true and session is not blocked'
		);
		$this->assertFalse(
			$this->sut->validate_add_to_cart( false, 1, 1 ),
			'Should preserve false passed value from previous filters'
		);
	}

	/**
	 * @testdox filter_payment_gateways should return empty array when session is blocked.
	 */
	public function test_filter_payment_gateways_returns_empty_when_blocked(): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( true );

		$gateways = array(
			'bacs'   => 'Bank Transfer',
			'paypal' => 'PayPal',
		);

		$result = $this->sut->filter_payment_gateways( $gateways );

		$this->assertEmpty( $result, 'Should return empty array when session is blocked' );
	}

	/**
	 * @testdox filter_payment_gateways should return original gateways when session is not blocked.
	 */
	public function test_filter_payment_gateways_returns_original_when_not_blocked(): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( false );

		$gateways = array(
			'bacs'   => 'Bank Transfer',
			'paypal' => 'PayPal',
		);

		$result = $this->sut->filter_payment_gateways( $gateways );

		$this->assertSame( $gateways, $result, 'Should return original gateways when session is not blocked' );
	}

	/**
	 * @testdox filter_store_api_requests should return existing result when result is not null.
	 */
	public function test_filter_store_api_requests_returns_existing_result(): void {
		$existing_result = new \WP_Error( 'existing', 'Existing error' );
		$server          = $this->createMock( \WP_REST_Server::class );
		$request         = $this->create_rest_request( '/wc/store/v1/cart/add-item', 'POST' );

		$result = $this->sut->filter_store_api_requests( $existing_result, $server, $request );

		$this->assertSame( $existing_result, $result, 'Should return existing result when not null' );
	}

	/**
	 * @testdox filter_store_api_requests should not intercept non-Store API routes, GET requests, or non-blocked routes.
	 */
	public function test_filter_store_api_requests_ignores_irrelevant_requests(): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( true );
		$server = $this->createMock( \WP_REST_Server::class );

		$this->assertNull(
			$this->sut->filter_store_api_requests( null, $server, $this->create_rest_request( '/wp/v2/posts', 'POST' ) ),
			'Should not intercept non-Store API routes'
		);
		$this->assertNull(
			$this->sut->filter_store_api_requests( null, $server, $this->create_rest_request( '/wc/store/v1/cart', 'GET' ) ),
			'Should not intercept GET requests'
		);
		$this->assertNull(
			$this->sut->filter_store_api_requests( null, $server, $this->create_rest_request( '/wc/store/v1/products', 'POST' ) ),
			'Should not intercept non-blocked Store API routes'
		);
	}

	/**
	 * @testdox filter_store_api_requests should return WP_Error for blocked cart/checkout routes when session is blocked.
	 *
	 * @dataProvider blocked_routes_provider
	 *
	 * @param string $route The route to test.
	 */
	public function test_filter_store_api_requests_blocks_routes_when_blocked( string $route ): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( true );
		$this->blocked_notice_mock->expects( $this->once() )
			->method( 'get_message_plaintext' )
			->with( 'purchase' )
			->willReturn( 'Purchase blocked' );
		$server  = $this->createMock( \WP_REST_Server::class );
		$request = $this->create_rest_request( $route, 'POST' );

		$result = $this->sut->filter_store_api_requests( null, $server, $request );

		$this->assertInstanceOf( \WP_Error::class, $result, "Should return WP_Error for blocked route: $route" );
		$this->assertSame( 'woocommerce_rest_forbidden', $result->get_error_code(), 'Should use correct error code' );
		$this->assertSame( 403, $result->get_error_data()['status'], 'Should return 403 status' );
	}

	/**
	 * Data provider for blocked Store API routes.
	 *
	 * @return array
	 */
	public function blocked_routes_provider(): array {
		return array(
			'cart add-item'             => array( '/wc/store/v1/cart/add-item' ),
			'cart remove-item'          => array( '/wc/store/v1/cart/remove-item' ),
			'cart update-item'          => array( '/wc/store/v1/cart/update-item' ),
			'cart apply-coupon'         => array( '/wc/store/v1/cart/apply-coupon' ),
			'cart remove-coupon'        => array( '/wc/store/v1/cart/remove-coupon' ),
			'cart select-shipping-rate' => array( '/wc/store/v1/cart/select-shipping-rate' ),
			'cart update-customer'      => array( '/wc/store/v1/cart/update-customer' ),
			'checkout'                  => array( '/wc/store/v1/checkout' ),
		);
	}

	/**
	 * @testdox filter_store_api_requests should block all write HTTP methods on blocked routes.
	 *
	 * @dataProvider write_methods_provider
	 *
	 * @param string $method The HTTP method to test.
	 */
	public function test_filter_store_api_requests_blocks_all_write_methods( string $method ): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( true );
		$this->blocked_notice_mock->method( 'get_message_plaintext' )->willReturn( 'Blocked' );
		$server  = $this->createMock( \WP_REST_Server::class );
		$request = $this->create_rest_request( '/wc/store/v1/checkout', $method );

		$result = $this->sut->filter_store_api_requests( null, $server, $request );

		$this->assertInstanceOf( \WP_Error::class, $result, "Should block $method requests when session is blocked" );
	}

	/**
	 * Data provider for HTTP write methods.
	 *
	 * @return array
	 */
	public function write_methods_provider(): array {
		return array(
			'POST'   => array( 'POST' ),
			'PUT'    => array( 'PUT' ),
			'PATCH'  => array( 'PATCH' ),
			'DELETE' => array( 'DELETE' ),
		);
	}

	/**
	 * @testdox filter_store_api_requests should allow blocked routes when session is not blocked.
	 */
	public function test_filter_store_api_requests_allows_routes_when_not_blocked(): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( false );
		$server  = $this->createMock( \WP_REST_Server::class );
		$request = $this->create_rest_request( '/wc/store/v1/cart/add-item', 'POST' );

		$result = $this->sut->filter_store_api_requests( null, $server, $request );

		$this->assertNull( $result, 'Should allow request when session is not blocked' );
	}
}
