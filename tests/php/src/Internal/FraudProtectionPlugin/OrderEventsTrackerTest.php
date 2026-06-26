<?php
/**
 * OrderEventsTrackerTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\OrderEventsTracker;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the OrderEventsTracker class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\OrderEventsTracker
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

	/**
	 * Build a minimal valid report context.
	 *
	 * @param array<string, mixed> $overrides Fields merged over the defaults.
	 * @return ReportContextData
	 */
	private function make_context( array $overrides = array() ): ReportContextData {
		$context = ReportContextData::from_array(
			array_merge(
				array(
					'type'   => 'payment',
					'result' => 'captured',
				),
				$overrides
			)
		);

		$this->assertInstanceOf( ReportContextData::class, $context );
		return $context;
	}

	/*
	|--------------------------------------------------------------------------
	| Fraud Protection Report Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox fraud_protection_report() sends source, notes, and context when a session ID exists.
	 */
	public function test_fraud_protection_report_sends_context_payload(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$context = $this->make_context(
			array(
				'type'                   => 'dispute',
				'result'                 => 'lost',
				'reason'                 => 'fraud',
				'gateway'                => 'woocommerce_payments',
				'correlation_order_id'   => 555,
				'correlation_dispute_id' => 'dp_1',
			)
		);

		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->with(
				'bb-session-123',
				array(
					'source'  => ApiClient::REPORT_SOURCE_CHARGEBACK,
					'notes'   => 'Visa CB 10.4 fraud.',
					'context' => $context->to_array(),
				)
			);

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_CHARGEBACK, $context, 'Visa CB 10.4 fraud.' );
	}

	/**
	 * @testdox fraud_protection_report() backfills gateway and order_id from the order.
	 */
	public function test_fraud_protection_report_enriches_context_from_order(): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'stripe' );
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-789' );
		$order->save();

		$captured = array();
		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->willReturnCallback(
				function ( string $session_id, array $payload ) use ( &$captured ) {
					$captured = $payload;
					return true;
				}
			);

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, $this->make_context() );

		$this->assertSame( 'stripe', $captured['context']['gateway'], 'gateway should be backfilled from the order' );
		$this->assertSame( $order->get_id(), $captured['context']['correlation_order_id'], 'order_id should be backfilled from the order' );
	}

	/**
	 * @testdox fraud_protection_report() defaults an unknown source to 'api' and still reports.
	 */
	public function test_fraud_protection_report_defaults_unknown_source(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$captured = array();
		$this->api_client
			->expects( $this->once() )
			->method( 'report' )
			->willReturnCallback(
				function ( string $session_id, array $payload ) use ( &$captured ) {
					$captured = $payload;
					return true;
				}
			);

		$this->sut->fraud_protection_report( $order, 'made_up_source', $this->make_context() );

		$this->assertSame( ApiClient::REPORT_SOURCE_API, $captured['source'] );
		$this->assertLogged( 'warning', 'Unknown report source "made_up_source", defaulting to "api".' );
	}

	/**
	 * @testdox fraud_protection_report() skips reporting when the order has no session ID.
	 */
	public function test_fraud_protection_report_skips_when_no_session_id(): void {
		$order = \WC_Helper_Order::create_order();

		$this->api_client
			->expects( $this->never() )
			->method( 'report' );

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, $this->make_context() );

		$this->assertLogged( 'warning', 'Missing session ID in order meta, skipping Blackbox API report.' );
	}

	/**
	 * @testdox fraud_protection_report() catches exceptions and logs an error.
	 */
	public function test_fraud_protection_report_catches_exceptions(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		$order->save_meta_data();

		$this->api_client
			->method( 'report' )
			->willThrowException( new \RuntimeException( 'API connection failed' ) );

		$this->sut->fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, $this->make_context() );

		$this->assertLogged( 'error', 'Failed to report 3rd party event to Blackbox API' );
	}
}
