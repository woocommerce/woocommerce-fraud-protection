<?php
/**
 * SessionVerifierTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\Internal\ApiClient;
use Automattic\WooCommerce\Internal\DecisionHandler;
use Automattic\WooCommerce\Internal\SessionDataCollector;
use Automattic\WooCommerce\Internal\SessionVerifier;
use WC_Unit_Test_Case;

/**
 * Tests for the SessionVerifier class.
 *
 * @covers \Automattic\WooCommerce\Internal\SessionVerifier
 */
class SessionVerifierTest extends WC_Unit_Test_Case {

	/**
	 * The System Under Test.
	 *
	 * @var SessionVerifier
	 */
	private SessionVerifier $sut;

	/**
	 * Mock session data collector.
	 *
	 * @var SessionDataCollector&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $data_collector;

	/**
	 * Mock API client.
	 *
	 * @var ApiClient&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $api_client;

	/**
	 * Mock decision handler.
	 *
	 * @var DecisionHandler&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $decision_handler;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->data_collector   = $this->createMock( SessionDataCollector::class );
		$this->api_client       = $this->createMock( ApiClient::class );
		$this->decision_handler = $this->createMock( DecisionHandler::class );

		$this->sut = new SessionVerifier();
		$this->sut->init(
			$this->data_collector,
			$this->api_client,
			$this->decision_handler
		);
	}

	/**
	 * @testdox verify_session() passes collected data, session_id, and request_data through the full pipeline.
	 */
	public function test_verify_session_wires_pipeline_correctly(): void {
		$session_id    = 'test-session-abc';
		$order_id      = 42;
		$collected_data = array(
			'session'  => array( 'wc_session_id' => 'abc' ),
			'customer' => array(),
		);
		$request_data = array(
			'billing_address'  => array( 'first_name' => 'John' ),
			'payment_method'   => 'woocommerce_payments',
			'create_account'   => false,
		);

		$this->data_collector
			->expects( $this->once() )
			->method( 'get_collected_data' )
			->with( $order_id )
			->willReturn( $collected_data );

		$expected_payload = array_merge(
			$collected_data,
			array(
				'source'       => 'blocks_checkout',
				'request_data' => $request_data,
				'payment'      => null,
			)
		);

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->with( $session_id, $expected_payload )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->decision_handler
			->expects( $this->once() )
			->method( 'apply_decision' )
			->with( ApiClient::DECISION_ALLOW, $expected_payload )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_session( $session_id, $order_id, 'blocks_checkout', $request_data );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
	}

	/**
	 * @testdox verify_session() returns the filtered decision from apply_decision(), not from verify().
	 */
	public function test_verify_session_returns_filtered_decision(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array( 'session' => array(), 'customer' => array() ) );

		// API returns BLOCK, but a filter overrides to ALLOW.
		$this->api_client
			->method( 'verify' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_session( 'session-123', 99, 'blocks_checkout' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
	}
}
