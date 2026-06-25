<?php
/**
 * CartBlockingTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\BlockedSessionNotice;
use Automattic\WooCommerce\FraudProtection\SessionBlockingHandler;
use Automattic\WooCommerce\FraudProtection\SessionClearanceManager;

/**
 * Tests for cart blocking when session is blocked by fraud protection.
 *
 * Tests SessionBlockingHandler hook-based blocking and WC_Cart integration.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\SessionBlockingHandler
 */
class CartBlockingTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionBlockingHandler|null
	 */
	private $sut;

	/**
	 * Test product.
	 *
	 * @var \WC_Product
	 */
	private $product;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->product = \WC_Helper_Product::create_simple_product();

		wc_empty_cart();
		wc_clear_notices();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		if ( isset( $this->sut ) ) {
			remove_filter( 'woocommerce_add_to_cart_validation', array( $this->sut, 'validate_add_to_cart' ), 1 );
			remove_filter( 'woocommerce_available_payment_gateways', array( $this->sut, 'filter_payment_gateways' ), 999 );
			remove_filter( 'rest_pre_dispatch', array( $this->sut, 'filter_store_api_requests' ), 10 );
		}

		$this->product->delete( true );
		wc_empty_cart();
		wc_clear_notices();

		parent::tearDown();
	}

	/**
	 * Create a SessionBlockingHandler with mocked dependencies.
	 *
	 * @param bool $is_blocked Whether the session should report as blocked.
	 * @return SessionBlockingHandler
	 */
	private function create_handler( bool $is_blocked ): SessionBlockingHandler {
		$session_manager_mock = $this->createMock( SessionClearanceManager::class );
		$session_manager_mock->method( 'is_session_blocked' )->willReturn( $is_blocked );

		$blocked_notice_mock = $this->createMock( BlockedSessionNotice::class );
		$blocked_notice_mock->method( 'get_message_html' )->willReturn( 'Blocked message' );

		$handler = new SessionBlockingHandler();
		$handler->init( $session_manager_mock, $blocked_notice_mock );

		return $handler;
	}

	/**
	 * @testdox register should add all blocking hooks.
	 */
	public function test_register_adds_all_hooks(): void {
		$this->sut = $this->create_handler( false );
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
	 * @testdox validate_add_to_cart returns false and adds notice when session is blocked.
	 */
	public function test_add_to_cart_blocked_when_session_blocked(): void {
		$this->sut = $this->create_handler( true );

		$result = $this->sut->validate_add_to_cart( true, $this->product->get_id(), 1, 0, array() );

		$this->assertFalse( $result, 'Add to cart should be blocked when session is blocked' );
		$this->assertTrue( wc_has_notice( 'Blocked message', 'error' ), 'Error notice should be added when session is blocked' );
	}

	/**
	 * @testdox add_to_cart succeeds when session is allowed.
	 */
	public function test_add_to_cart_allowed_when_session_allowed(): void {
		$this->sut = $this->create_handler( false );
		$this->sut->register();

		$result = WC()->cart->add_to_cart( $this->product->get_id(), 1 );

		$this->assertNotFalse( $result, 'Add to cart should succeed when session is allowed' );
		$this->assertEquals( 1, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox add_to_cart succeeds when no blocking hooks are registered.
	 */
	public function test_add_to_cart_allowed_when_no_hooks_registered(): void {
		$result = WC()->cart->add_to_cart( $this->product->get_id(), 1 );

		$this->assertNotFalse( $result, 'Add to cart should succeed without blocking hooks' );
		$this->assertEquals( 1, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox remove_cart_item succeeds when session is allowed.
	 */
	public function test_remove_cart_item_allowed_when_session_allowed(): void {
		$this->sut = $this->create_handler( false );
		$this->sut->register();

		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );
		$result = WC()->cart->remove_cart_item( $cart_item_key );

		$this->assertTrue( $result, 'Remove cart item should succeed when session is allowed' );
		$this->assertEquals( 0, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox remove_cart_item succeeds when no blocking hooks are registered.
	 */
	public function test_remove_cart_item_allowed_when_no_hooks_registered(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );
		$result = WC()->cart->remove_cart_item( $cart_item_key );

		$this->assertTrue( $result, 'Remove cart item should succeed without blocking hooks' );
		$this->assertEquals( 0, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox set_quantity succeeds when session is allowed.
	 */
	public function test_set_quantity_allowed_when_session_allowed(): void {
		$this->sut = $this->create_handler( false );
		$this->sut->register();

		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );
		$result = WC()->cart->set_quantity( $cart_item_key, 5 );

		$this->assertTrue( $result, 'Set quantity should succeed when session is allowed' );
		$this->assertEquals( 5, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * @testdox set_quantity succeeds when no blocking hooks are registered.
	 */
	public function test_set_quantity_allowed_when_no_hooks_registered(): void {
		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$this->assertIsString( $cart_item_key );
		$result = WC()->cart->set_quantity( $cart_item_key, 5 );

		$this->assertTrue( $result, 'Set quantity should succeed without blocking hooks' );
		$this->assertEquals( 5, WC()->cart->get_cart_contents_count() );
	}
}
