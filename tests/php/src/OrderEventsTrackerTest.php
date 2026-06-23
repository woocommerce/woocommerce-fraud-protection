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
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the OrderEventsTracker class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\OrderEventsTracker
 */
class OrderEventsTrackerTest extends FraudProtectionUnitTestCase {

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

	/*
	|--------------------------------------------------------------------------
	| Fraud Protection Report Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox fraud_protection_report() calls report with correct payload when session ID exists.
	 */
	public function test_fraud_protection_report_reports_when_session_id_exists(): void {
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
					'source' => ApiClient::REPORT_SOURCE_API,
					'notes'  => 'Payment failed via Stripe.',
				)
			);

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, 'bad', 'Payment failed via Stripe.' );
	}

	/**
	 * @testdox fraud_protection_report() calls report with 'good' status when payment succeeds.
	 */
	public function test_fraud_protection_report_reports_good_status(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-456' );
		$order->save_meta_data();

		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->with(
				'bb-session-456',
				array(
					'label'  => 'good',
					'source' => ApiClient::REPORT_SOURCE_API,
					'notes'  => 'Payment completed successfully.',
				)
			);

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, 'good', 'Payment completed successfully.' );
	}

	/**
	 * @testdox fraud_protection_report() skips reporting when order has no session ID.
	 */
	public function test_fraud_protection_report_skips_when_no_session_id(): void {
		$order = \WC_Helper_Order::create_order();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, 'bad', 'Some notes.' );
	}

	/**
	 * @testdox fraud_protection_report() skips reporting and logs warning when status is invalid.
	 */
	public function test_fraud_protection_report_skips_when_invalid_status(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, 'invalid_status', 'Some notes.' );

		$this->assertLogged( 'warning', 'Invalid report status "invalid_status", skipping report.' );
	}

	/**
	 * @testdox fraud_protection_report() skips reporting and logs warning when source is invalid.
	 */
	public function test_fraud_protection_report_skips_when_invalid_source(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->fraud_protection_report( $order, 'invalid_source', 'bad', 'Some notes.' );

		$this->assertLogged( 'warning', 'Invalid report source "invalid_source", skipping report.' );
	}

	/**
	 * @testdox fraud_protection_report() catches exceptions and logs error.
	 */
	public function test_fraud_protection_report_catches_exceptions(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->method( 'report' )
			->willThrowException( new \RuntimeException( 'API connection failed' ) );

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, 'bad', 'Payment failed.' );

		$this->assertLogged( 'error', 'Failed to report 3rd party event to Blackbox API' );
	}

}
