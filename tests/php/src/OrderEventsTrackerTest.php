<?php
/**
 * OrderEventsTrackerTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\OrderEventsTracker;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderEventsTracker class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\OrderEventsTracker
 */
class OrderEventsTrackerTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var OrderEventsTracker
	 */
	private OrderEventsTracker $sut;

	/**
	 * Mock API client.
	 *
	 * @var ApiClient&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $api_client;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->api_client = $this->createMock( ApiClient::class );

		$this->sut = new OrderEventsTracker();
		$this->sut->init( $this->api_client );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( 'woocommerce_order_note_added' );
		remove_all_actions( 'woocommerce_order_refunded' );

		parent::tearDown();
	}

	/**
	 * @testdox register() hooks on_order_note_added to woocommerce_order_note_added.
	 */
	public function test_register_hooks_order_note_added(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_order_note_added', array( $this->sut, 'on_order_note_added' ) )
		);
	}

	/**
	 * @testdox on_order_note_added() calls report with correct payload when session ID exists.
	 */
	public function test_on_order_note_added_reports_when_session_id_exists(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$comment_id = $order->add_order_note( 'Payment completed via Stripe.' );

		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->with(
				'bb-session-123',
				array(
					'label'  => 'demo-label',
					'source' => 'order_note_added',
					'notes'  => 'Payment completed via Stripe.',
				)
			);

		$this->sut->on_order_note_added( $comment_id, $order );
	}

	/**
	 * @testdox on_order_note_added() skips reporting when order has no session ID.
	 */
	public function test_on_order_note_added_skips_when_no_session_id(): void {
		$order      = \WC_Helper_Order::create_order();
		$comment_id = $order->add_order_note( 'Some note.' );

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->on_order_note_added( $comment_id, $order );
	}

	/**
	 * @testdox on_order_note_added() skips reporting when comment does not exist.
	 */
	public function test_on_order_note_added_skips_when_comment_missing(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->on_order_note_added( 999999999, $order );
	}

	/*
	|--------------------------------------------------------------------------
	| Order Refunded Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks on_order_refunded to woocommerce_order_refunded.
	 */
	public function test_register_hooks_order_refunded(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_order_refunded', array( $this->sut, 'on_order_refunded' ) )
		);
	}

	/**
	 * @testdox on_order_refunded() calls report with correct payload when session ID exists.
	 */
	public function test_on_order_refunded_reports_when_session_id_exists(): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_status( 'completed' );
		$order->save();

		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-456' );
		$order->save_meta_data();

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '10.00',
				'reason'   => 'Customer requested refund.',
			)
		);

		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->with(
				'bb-session-456',
				array(
					'label'  => 'demo-label',
					'source' => 'order_refunded',
					'notes'  => 'Customer requested refund.',
				)
			);

		$this->sut->on_order_refunded( $order->get_id(), $refund->get_id() );
	}

	/**
	 * @testdox on_order_refunded() skips reporting when order has no session ID.
	 */
	public function test_on_order_refunded_skips_when_no_session_id(): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_status( 'completed' );
		$order->save();

		$refund = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '10.00',
				'reason'   => 'Refund reason.',
			)
		);

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->on_order_refunded( $order->get_id(), $refund->get_id() );
	}

	/**
	 * @testdox on_order_refunded() skips reporting when order does not exist.
	 */
	public function test_on_order_refunded_skips_when_order_missing(): void {
		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->on_order_refunded( 999999999, 999999998 );
	}
}
