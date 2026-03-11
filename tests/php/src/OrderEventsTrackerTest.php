<?php
/**
 * OrderEventsTrackerTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\OrderEventsTracker;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the OrderEventsTracker class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\OrderEventsTracker
 */
class OrderEventsTrackerTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

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
		remove_all_actions( 'woocommerce_fraud_protection_report' );

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| Fraud Protection Report Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks on_fraud_protection_report to woocommerce_fraud_protection_report.
	 */
	public function test_register_hooks_fraud_protection_report(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_fraud_protection_report', array( $this->sut, 'on_fraud_protection_report' ) )
		);
	}

	/**
	 * @testdox on_fraud_protection_report() calls report with correct payload when session ID exists.
	 */
	public function test_on_fraud_protection_report_reports_when_session_id_exists(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->with(
				'bb-session-123',
				array(
					'label'  => 'bad',
					'source' => 'payment_gateway_event',
					'notes'  => 'Payment failed via Stripe.',
				)
			);

		$this->sut->on_fraud_protection_report( $order, 'bad', 'Payment failed via Stripe.' );
	}

	/**
	 * @testdox on_fraud_protection_report() skips reporting when order has no session ID.
	 */
	public function test_on_fraud_protection_report_skips_when_no_session_id(): void {
		$order = \WC_Helper_Order::create_order();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->on_fraud_protection_report( $order, 'bad', 'Some notes.' );
	}

	/**
	 * @testdox on_fraud_protection_report() skips reporting and logs warning when status is invalid.
	 */
	public function test_on_fraud_protection_report_skips_when_invalid_status(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->on_fraud_protection_report( $order, 'invalid_status', 'Some notes.' );

		$this->assertLogged( 'warning', 'Invalid report status "invalid_status", skipping report.' );
	}

	/**
	 * @testdox on_fraud_protection_report() catches exceptions and logs error.
	 */
	public function test_on_fraud_protection_report_catches_exceptions(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->method( 'report' )
			->willThrowException( new \RuntimeException( 'API connection failed' ) );

		$this->sut->on_fraud_protection_report( $order, 'bad', 'Payment failed.' );

		$this->assertLogged( 'error', 'Failed to report 3rd party event to Blackbox API: API connection failed' );
	}

}
