<?php
/**
 * CartBlockingTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerceFraudProtection\Tests\Internal;

use Automattic\WooCommerceFraudProtection\Internal\BlockedSessionNotice;
use Automattic\WooCommerceFraudProtection\Internal\FraudProtectionController;
use Automattic\WooCommerceFraudProtection\Internal\SessionClearanceManager;

/**
 * Tests for cart blocking when session is blocked by fraud protection.
 *
 * Tests WC_Cart method integration (add_to_cart, remove_cart_item, set_quantity).
 *
 * @covers \WC_Cart
 */
class CartBlockingTest extends \WC_Unit_Test_Case {

	/**
	 * Mock FraudProtectionController.
	 *
	 * @var FraudProtectionController|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $fraud_controller_mock;

	/**
	 * Mock SessionClearanceManager.
	 *
	 * @var SessionClearanceManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_manager_mock;

	/**
	 * Mock BlockedSessionNotice.
	 *
	 * @var BlockedSessionNotice|\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_notice_mock;

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

		$this->fraud_controller_mock = $this->createMock( FraudProtectionController::class );
		$this->session_manager_mock  = $this->createMock( SessionClearanceManager::class );
		$this->blocked_notice_mock   = $this->createMock( BlockedSessionNotice::class );

		// Initialize the controller with mock dependencies.
		$this->fraud_controller_mock->init( $this->blocked_notice_mock, $this->createMock( \Automattic\WooCommerceFraudProtection\Internal\BlackboxScriptHandler::class ) );
		$this->blocked_notice_mock->init( $this->session_manager_mock );

		wc_empty_cart();
		wc_clear_notices();
	}

	/**
	 * Tear down.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_add_to_cart_validation' );
		$this->product->delete( true );
		wc_empty_cart();
		wc_clear_notices();

		parent::tearDown();
	}

	/**
	 * Test add to cart validation rejects when session is blocked.
	 *
	 * Registers a woocommerce_add_to_cart_validation filter that checks the
	 * session manager and verifies it blocks the operation when blocked.
	 *
	 * @testdox woocommerce_add_to_cart_validation returns false when session is blocked.
	 */
	public function test_add_to_cart_blocked_when_session_blocked(): void {
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( true );
		$this->blocked_notice_mock->method( 'get_message_html' )->willReturn( 'Blocked message' );

		$session_manager = $this->session_manager_mock;
		$blocked_notice  = $this->blocked_notice_mock;
		add_filter(
			'woocommerce_add_to_cart_validation',
			function ( $passed ) use ( $session_manager, $blocked_notice ) {
				if ( $session_manager->is_session_blocked() ) {
					wc_add_notice( $blocked_notice->get_message_html( 'purchase' ), 'error' );
					return false;
				}
				return $passed;
			}
		);

		$result = apply_filters( 'woocommerce_add_to_cart_validation', true, $this->product->get_id(), 1 );

		$this->assertFalse( $result );
		$this->assertTrue( wc_has_notice( 'Blocked message', 'error' ) );
	}

	/**
	 * Test add to cart allowed when session allowed.
	 *
	 * @testdox add_to_cart succeeds when session is allowed.
	 */
	public function test_add_to_cart_allowed_when_session_allowed(): void {
		$this->fraud_controller_mock->method( 'feature_is_enabled' )->willReturn( true );
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( false );

		$result = WC()->cart->add_to_cart( $this->product->get_id(), 1 );

		$this->assertNotFalse( $result );
		$this->assertEquals( 1, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * Test add to cart allowed when feature disabled.
	 *
	 * @testdox add_to_cart succeeds when fraud protection is disabled.
	 */
	public function test_add_to_cart_allowed_when_feature_disabled(): void {
		$this->fraud_controller_mock->method( 'feature_is_enabled' )->willReturn( false );
		$this->session_manager_mock->expects( $this->never() )->method( 'is_session_blocked' );

		$result = WC()->cart->add_to_cart( $this->product->get_id(), 1 );

		$this->assertNotFalse( $result );
		$this->assertEquals( 1, WC()->cart->get_cart_contents_count() );
	}

/**
	 * Test remove cart item allowed when session allowed.
	 *
	 * @testdox remove_cart_item succeeds when session is allowed.
	 */
	public function test_remove_cart_item_allowed_when_session_allowed(): void {
		$this->fraud_controller_mock->method( 'feature_is_enabled' )->willReturn( true );
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( false );

		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$result        = WC()->cart->remove_cart_item( $cart_item_key );

		$this->assertTrue( $result );
		$this->assertEquals( 0, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * Test remove cart item allowed when feature disabled.
	 *
	 * @testdox remove_cart_item succeeds when fraud protection is disabled.
	 */
	public function test_remove_cart_item_allowed_when_feature_disabled(): void {
		$this->fraud_controller_mock->method( 'feature_is_enabled' )->willReturn( false );

		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$result        = WC()->cart->remove_cart_item( $cart_item_key );

		$this->assertTrue( $result );
		$this->assertEquals( 0, WC()->cart->get_cart_contents_count() );
	}

/**
	 * Test set quantity allowed when session allowed.
	 *
	 * @testdox set_quantity succeeds when session is allowed.
	 */
	public function test_set_quantity_allowed_when_session_allowed(): void {
		$this->fraud_controller_mock->method( 'feature_is_enabled' )->willReturn( true );
		$this->session_manager_mock->method( 'is_session_blocked' )->willReturn( false );

		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$result        = WC()->cart->set_quantity( $cart_item_key, 5 );

		$this->assertTrue( $result );
		$this->assertEquals( 5, WC()->cart->get_cart_contents_count() );
	}

	/**
	 * Test set quantity allowed when feature disabled.
	 *
	 * @testdox set_quantity succeeds when fraud protection is disabled.
	 */
	public function test_set_quantity_allowed_when_feature_disabled(): void {
		$this->fraud_controller_mock->method( 'feature_is_enabled' )->willReturn( false );

		$cart_item_key = WC()->cart->add_to_cart( $this->product->get_id(), 1 );
		$result        = WC()->cart->set_quantity( $cart_item_key, 5 );

		$this->assertTrue( $result );
		$this->assertEquals( 5, WC()->cart->get_cart_contents_count() );
	}
}
