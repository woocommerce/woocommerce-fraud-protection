<?php
/**
 * SessionVerifierTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\DecisionHandler;
use Automattic\WooCommerce\FraudProtection\PaymentDataResolver;
use Automattic\WooCommerce\FraudProtection\Schemas\CardPaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\SessionDataCollector;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the SessionVerifier class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\SessionVerifier
 */
class SessionVerifierTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

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
	 * Mock payment data resolver.
	 *
	 * @var PaymentDataResolver&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $payment_data_resolver;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->data_collector        = $this->createMock( SessionDataCollector::class );
		$this->api_client            = $this->createMock( ApiClient::class );
		$this->decision_handler      = $this->createMock( DecisionHandler::class );
		$this->payment_data_resolver = $this->createMock( PaymentDataResolver::class );

		$this->sut = new SessionVerifier();
		$this->sut->init(
			$this->data_collector,
			$this->api_client,
			$this->decision_handler,
			$this->payment_data_resolver
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_fraud_protection_skip_session_verify' );
		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| Pipeline Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_session() passes collected data, session_id, and request_data through the full pipeline.
	 */
	public function test_verify_session_wires_pipeline_correctly(): void {
		$session_id     = 'test-session-abc';
		$order_id       = 42;
		$collected_data = array(
			'session'  => array( 'wc_identity_id' => 'abc' ),
			'customer' => array(),
		);
		$request_data = array(
			'billing_address' => array( 'first_name' => 'John' ),
			'payment_method'  => 'woocommerce_payments',
			'payment_data'    => array(),
			'create_account'  => false,
		);

		$this->data_collector
			->expects( $this->once() )
			->method( 'get_collected_data' )
			->with( $order_id )
			->willReturn( $collected_data );

		$this->payment_data_resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with( 'woocommerce_payments', array() )
			->willReturn( null );

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

		$result = $this->sut->verify_session( $session_id, 'blocks_checkout', $order_id, $request_data );

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

		$result = $this->sut->verify_session( 'session-123', 'blocks_checkout', 99 );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
	}

	/*
	|--------------------------------------------------------------------------
	| Payment Data Resolution Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_session() resolves payment data from request_data and includes it in the API payload.
	 */
	public function test_verify_session_resolves_payment_data(): void {
		$resolved = new PaymentMethodData(
			'stripe',
			'card',
			false,
			new CardPaymentMethodData( 'visa', 'credit', '4242' )
		);

		$request_data = array(
			'payment_method' => 'stripe',
			'payment_data'   => array( 'wc-stripe-payment-method' => 'pm_123' ),
		);

		$this->payment_data_resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with( 'stripe', array( 'wc-stripe-payment-method' => 'pm_123' ) )
			->willReturn( $resolved );

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->with(
				'test-session',
				$this->callback( function ( $payload ) use ( $resolved ) {
					return $payload['payment'] === $resolved->to_array();
				} )
			)
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->sut->verify_session( 'test-session', 'blocks_checkout', 0, $request_data );
	}

	/**
	 * @testdox verify_session() fails open when payment data resolution throws — verify still runs with null payment.
	 */
	public function test_verify_session_fails_open_when_resolver_throws(): void {
		$this->payment_data_resolver
			->method( 'resolve' )
			->willThrowException( new \RuntimeException( 'Compat layer exploded' ) );

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->with(
				'test-session',
				$this->callback( function ( $payload ) {
					return null === $payload['payment'];
				} )
			)
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$request_data = array( 'payment_method' => 'stripe', 'payment_data' => array() );

		$result = $this->sut->verify_session( 'test-session', 'checkout', 0, $request_data );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
		$this->assertLogged(
			'warning',
			'Payment data resolution failed: Compat layer exploded',
			array(
				'verify_context' => array(
					'source'       => 'checkout',
					'session_id'   => 'test-session',
					'order_id'     => 0,
					'request_data' => $request_data,
				),
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Fail-Open Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_session() fails open when api_client->verify() throws.
	 */
	public function test_verify_session_fails_open_when_api_throws(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willThrowException( new \RuntimeException( 'API call failed' ) );

		$result = $this->sut->verify_session( 'test-session', 'checkout' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
		$this->assertLogged(
			'error',
			'Session verification failed, allowing: API call failed',
			array(
				'verify_context' => array(
					'source'     => 'checkout',
					'session_id' => 'test-session',
				),
			)
		);
	}

	/**
	 * @testdox verify_session() fails open when decision_handler->apply_decision() throws.
	 */
	public function test_verify_session_fails_open_when_decision_handler_throws(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willReturn( ApiClient::DECISION_BLOCK );

		$this->decision_handler
			->method( 'apply_decision' )
			->willThrowException( new \RuntimeException( 'Decision handler exploded' ) );

		$result = $this->sut->verify_session( 'test-session', 'checkout' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
		$this->assertLogged(
			'error',
			'Session verification failed, allowing: Decision handler exploded',
			array(
				'verify_context' => array(
					'source'     => 'checkout',
					'session_id' => 'test-session',
				),
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Should Verify Filter Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_session() skips verification and returns ALLOW when filter returns true.
	 */
	public function test_verify_session_skips_when_filter_returns_true(): void {
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', '__return_true' );

		$this->api_client
			->expects( $this->never() )
			->method( 'verify' );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
		$this->assertLogged( 'info', 'Session verification skipped by `woocommerce_fraud_protection_skip_session_verify` filter for source: blocks_checkout' );
	}

	/**
	 * @testdox verify_session() proceeds normally when filter returns false.
	 */
	public function test_verify_session_proceeds_when_filter_returns_false(): void {
		add_filter( 'woocommerce_fraud_protection_skip_session_verify', '__return_false' );

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
	}

	/**
	 * @testdox verify_session() proceeds normally when filter returns non-bool falsy value.
	 */
	public function test_verify_session_proceeds_when_filter_returns_non_bool(): void {
		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function () {
				return null;
			}
		);

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
	}

	/**
	 * @testdox verify_session() proceeds normally when filter callback throws (fail-open).
	 */
	public function test_verify_session_proceeds_when_filter_throws(): void {
		add_filter( // @phpstan-ignore return.missing
			'woocommerce_fraud_protection_skip_session_verify',
			function () {
				throw new \RuntimeException( 'Filter exploded' );
			}
		);

		$this->data_collector // @phpstan-ignore deadCode.unreachable
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( ApiClient::DECISION_ALLOW );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertSame( ApiClient::DECISION_ALLOW, $result );
		$this->assertLogged( 'warning', '`woocommerce_fraud_protection_skip_session_verify` filter threw: Filter exploded' );
	}

}
