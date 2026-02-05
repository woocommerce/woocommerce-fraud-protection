<?php
/**
 * SessionVerifierTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtection\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtection\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtection\SessionDataCollector;
use Automattic\WooCommerce\Internal\FraudProtection\SessionVerifier;
use WC_Unit_Test_Case;

/**
 * Tests for the SessionVerifier class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtection\SessionVerifier
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
	 * @var SessionDataCollector
	 */
	private $data_collector;

	/**
	 * Mock API client.
	 *
	 * @var ApiClient
	 */
	private $api_client;

	/**
	 * Mock decision handler.
	 *
	 * @var DecisionHandler
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
	 * @testdox verify_session() passes collected data and session_id through the full pipeline.
	 */
	public function test_verify_session_wires_pipeline_correctly(): void {
		$session_id = 'test-session-abc';
		$order_id   = 42;
		$payload    = array(
			'event_type' => 'checkout',
			'amount' => 100
		);

		$this->data_collector
			->expects( $this->once() )
			->method( 'get_collected_data' )
			->with( $order_id )
			->willReturn( $payload );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->with( $session_id, $payload )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->decision_handler
			->expects( $this->once() )
			->method( 'apply_decision' )
			->with( ApiClient::DECISION_ALLOW, $payload )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_session( $session_id, $order_id );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
	}

	/**
	 * @testdox verify_session() returns the filtered decision from apply_decision(), not from verify().
	 */
	public function test_verify_session_returns_filtered_decision(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array( 'event_type' => 'checkout' ) );

		// API returns BLOCK, but a filter overrides to ALLOW.
		$this->api_client
			->method( 'verify' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_session( 'session-123', 99 );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
	}
}
