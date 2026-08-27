<?php
/**
 * SessionVerifierTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the SessionVerifier class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\SessionVerifier
 */
class SessionVerifierTest extends FraudProtectionUnitTestCase {

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
	 * Session ID normalizer.
	 *
	 * @var SessionIdNormalizer
	 */
	private $session_id_normalizer;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->data_collector        = $this->createMock( SessionDataCollector::class );
		$this->api_client            = $this->createMock( ApiClient::class );
		$this->decision_handler      = $this->createMock( DecisionHandler::class );
		$this->payment_data_resolver = $this->createMock( PaymentDataResolver::class );
		$this->session_id_normalizer  = new SessionIdNormalizer();

		$this->sut = new SessionVerifier();
		$this->sut->init(
			$this->data_collector,
			$this->api_client,
			$this->decision_handler,
			$this->payment_data_resolver,
			$this->session_id_normalizer
		);
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		if ( WC()->session ) {
			WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, null );
		}
		remove_all_filters( 'woocommerce_fraud_protection_skip_session_verify' );
		remove_all_actions( 'woocommerce_checkout_order_created' );

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

		$resolved_payment = new PaymentMethodData( 'woocommerce_payments' );

		$this->payment_data_resolver
			->expects( $this->once() )
			->method( 'resolve' )
			->with( 'woocommerce_payments', array() )
			->willReturn( $resolved_payment );

		$expected_payload = array_merge(
			$collected_data,
			array(
				'source'  => 'blocks_checkout',
				'payment' => $resolved_payment->to_array(),
			)
		);

		$verify_result = VerifyResult::create( FraudDecision::Allow, $session_id );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->with( $session_id, $expected_payload )
			->willReturn( $verify_result );

		// The decision handler receives the verify result and the same payload
		// that was sent to the API, unchanged.
		$this->decision_handler
			->expects( $this->once() )
			->method( 'apply_decision' )
			->with( $verify_result, $expected_payload )
			->willReturn( FraudDecision::Allow );

		$result = $this->sut->verify_session( $session_id, 'blocks_checkout', $order_id, $request_data );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * @testdox verify_session() caps the source consistently at 32 characters.
	 */
	public function test_verify_session_caps_source_consistently(): void {
		$source          = str_repeat( 'a', 32 ) . 'b';
		$expected_source = str_repeat( 'a', 32 );
		$filter_source   = null;

		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function ( $supplied, string $filtered_source ) use ( &$filter_source ) {
				$filter_source = $filtered_source;
				return $supplied;
			},
			10,
			2
		);

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$expected_payload = array(
			'source'  => $expected_source,
			'payment' => array(),
		);
		$verify_result    = VerifyResult::create( FraudDecision::Allow, 'test-session' );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->with( 'test-session', $expected_payload )
			->willReturn( $verify_result );

		$this->decision_handler
			->expects( $this->once() )
			->method( 'apply_decision' )
			->with( $verify_result, $expected_payload )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_session( 'test-session', $source );

		$this->assertSame( $expected_source, $filter_source );
	}

	/**
	 * @testdox verify_session() passes the normalized submitted value to the API
	 */
	public function test_verify_session_uses_normalized_submitted_input(): void {
		$submitted  = array( 'malformed' );
		$normalized = 'normalized-by-helper';
		$normalizer = $this->createMock( SessionIdNormalizer::class );

		$normalizer
			->expects( $this->once() )
			->method( 'normalize' )
			->with( $submitted )
			->willReturn( $normalized );
		$this->sut->init(
			$this->data_collector,
			$this->api_client,
			$this->decision_handler,
			$this->payment_data_resolver,
			$normalizer
		);

		$this->data_collector->method( 'get_collected_data' )->willReturn( array() );
		$verify_result = VerifyResult::create( FraudDecision::Allow, '' );
		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->with( $normalized, $this->anything() )
			->willReturn( $verify_result );
		$this->decision_handler
			->expects( $this->once() )
			->method( 'apply_decision' )
			->with( $verify_result, $this->anything() )
			->willReturn( FraudDecision::Allow );

		$this->assertSame( FraudDecision::Allow, $this->sut->verify_session( $submitted, 'direct_caller' ) );
	}

	/**
	 * Reserved submitted markers.
	 *
	 * @return array<string, array{string}>
	 */
	public function submitted_marker_provider(): array {
		return array(
			'boolean'    => array( 'wcfp-invalid-boolean' ),
			'null'       => array( 'wcfp-invalid-null' ),
			'array'      => array( 'wcfp-invalid-array' ),
			'object'     => array( 'wcfp-invalid-object' ),
			'resource'   => array( 'wcfp-invalid-resource' ),
			'characters' => array( 'wcfp-invalid-characters' ),
			'number'     => array( 'wcfp-invalid-number' ),
		);
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
			->willReturn( VerifyResult::create( FraudDecision::Block, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$result = $this->sut->verify_session( 'session-123', 'blocks_checkout', 99 );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * @testdox verify_session() passes a fail-open verify result through to apply_decision() unchanged.
	 */
	public function test_verify_session_passes_fail_open_result_to_decision_handler(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$verify_result = VerifyResult::fail_open();

		$this->api_client
			->method( 'verify' )
			->willReturn( $verify_result );

		$this->decision_handler
			->expects( $this->once() )
			->method( 'apply_decision' )
			->with( $verify_result, $this->anything() )
			->willReturn( FraudDecision::Allow );

		$result = $this->sut->verify_session( 'session-123', 'blocks_checkout', 0 );

		$this->assertSame( FraudDecision::Allow, $result );
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
			PaymentInstrumentData::from_array( array( 'brand' => 'visa', 'funding' => 'credit', 'last4' => '4242' ) ),
			PaymentMode::Live,
			'acct_123',
			'account'
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
			->willReturn( VerifyResult::create( FraudDecision::Allow, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_session( 'test-session', 'blocks_checkout', 0, $request_data );
	}

	/**
	 * @testdox verify_session() fails open when payment data resolution throws — verify still runs with null payment.
	 */
	public function test_verify_session_fails_open_when_resolver_throws(): void {
		$spy   = $this->spy_on_controller_logging();
		$error = new \RuntimeException( 'Compat layer exploded with resolver-exception-marker' );

		$this->payment_data_resolver
			->method( 'resolve' )
			->willThrowException( $error );

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
			->willReturn( VerifyResult::create( FraudDecision::Allow, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$request_data = array(
			'payment_method' => 'stripe',
			'payment_data'   => array( 'card-token' => 'submitted-payment-value' ),
		);

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout', 0, $request_data );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged(
			'warning',
			'Payment data resolution failed',
			null,
			true
		);
		$this->assertSame(
			array(
				'event_source'      => 'blocks_checkout',
				'session_id'        => 'test-session',
				'order_id'          => 0,
				'payment_type'      => 'stripe',
				'hook'              => 'payment_data_resolution',
				'exception_class'   => \RuntimeException::class,
				'exception_message' => $error->getMessage(),
				'exception_file'    => $error->getFile(),
				'exception_line'    => $error->getLine(),
			),
			$spy->entries[0]['context']
		);
	}

	/**
	 * @testdox verify_session() does not copy a malformed payment method into the resolution error log.
	 */
	public function test_verify_session_excludes_malformed_payment_method_from_resolution_error_log(): void {
		$spy = $this->spy_on_controller_logging();
		$this->stub_successful_verification();

		$result = $this->sut->verify_session(
			'test-session',
			'blocks_checkout',
			0,
			array(
				'payment_method' => array( 'gateway' => 'submitted-request-value' ),
				'payment_data'   => array( 'card-token' => 'submitted-request-value' ),
			)
		);

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'warning', 'Payment data resolution failed', array( 'payment_type' => '' ), true );
		$entry = end( $spy->entries );
		$this->assertIsArray( $entry );
		$this->assertArrayNotHasKey( 'verify_context', $entry['context'] );
		$this->assertStringNotContainsString( 'submitted-request-value', (string) wp_json_encode( $entry ) );
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
		$spy   = $this->spy_on_controller_logging();
		$error = new \RuntimeException( 'API call failed with api-exception-marker' );

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willThrowException( $error );

		$request_data = array( 'payment_data' => array( 'card-token' => 'submitted-payment-value' ) );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout', 0, $request_data );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged(
			'error',
			'Session verification failed, allowing',
			null,
			true
		);
		$this->assertSame(
			array(
				'event_source'      => 'blocks_checkout',
				'session_id'        => 'test-session',
				'order_id'          => 0,
				'hook'              => 'session_verify',
				'exception_class'   => \RuntimeException::class,
				'exception_message' => $error->getMessage(),
				'exception_file'    => $error->getFile(),
				'exception_line'    => $error->getLine(),
			),
			$spy->entries[0]['context']
		);
	}

	/**
	 * @testdox verify_session() clears empty-result associations before failing open when the decision handler throws.
	 */
	public function test_verify_session_fails_open_when_decision_handler_throws(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-order-id' );
		$order->save_meta_data();
		WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-session-id' );

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Block, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willThrowException( new \RuntimeException( 'Decision handler exploded' ) );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout', $order->get_id() );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertSame( '', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$this->assertSame( '', $this->sut->last_verified_session_id() );
		$this->assertLogged( 'error', 'Session verification failed, allowing' );
	}

	/*
	|--------------------------------------------------------------------------
	| Session ID Persistence Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_session() persists session ID to order meta when order_id > 0.
	 */
	public function test_verify_session_persists_session_id_to_order_meta(): void {
		$order = \WC_Helper_Order::create_order();

		$this->stub_successful_verification();

		$this->sut->verify_session( 'bb-session-xyz', 'blocks_checkout', $order->get_id() );

		// Re-read from DB to ensure it was saved.
		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'bb-session-xyz',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() overwrites session ID on order meta when called again.
	 */
	public function test_verify_session_overwrites_session_id_on_order_meta(): void {
		$order = \WC_Helper_Order::create_order();

		$this->stub_successful_verification();

		$this->sut->verify_session( 'first-session', 'blocks_checkout', $order->get_id() );
		$this->sut->verify_session( 'second-session', 'blocks_checkout', $order->get_id() );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'second-session',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() stores session ID in WC session when order_id is 0.
	 */
	public function test_verify_session_stores_session_id_in_wc_session(): void {
		$this->stub_successful_verification();

		$this->sut->verify_session( 'bb-session-deferred', 'shortcode_checkout', 0 );

		$this->assertSame(
			'bb-session-deferred',
			WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox persist_session_id_to_order() copies session ID from WC session to order meta.
	 */
	public function test_persist_session_id_to_order_copies_from_session(): void {
		$order = \WC_Helper_Order::create_order();

		// Simulate shortcode checkout: session ID stored in WC session.
		WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'deferred-session-abc' );

		$this->sut->persist_session_id_to_order( $order );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'deferred-session-abc',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox persist_session_id_to_order() preserves an existing invalid marker
	 *
	 * @dataProvider submitted_marker_provider
	 *
	 * @param string $marker Stored legacy association.
	 */
	public function test_persist_session_id_to_order_preserves_existing_invalid_marker( string $marker ): void {
		$order = \WC_Helper_Order::create_order();
		WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, $marker );

		$this->sut->persist_session_id_to_order( $order );

		$this->assertSame( $marker, wc_get_order( $order->get_id() )->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
	}

	/**
	 * @testdox persist_session_id_to_order() does nothing when WC session has no session ID.
	 */
	public function test_persist_session_id_to_order_skips_when_session_empty(): void {
		$order = \WC_Helper_Order::create_order();

		$this->sut->persist_session_id_to_order( $order );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() gracefully handles invalid order ID when wc_get_order() returns false.
	 */
	public function test_verify_session_handles_invalid_order_id(): void {
		$this->stub_successful_verification();

		$this->sut->verify_session( 'bb-session-invalid-order', 'blocks_checkout', 99999 );

		// Session ID should still be stored in WC session despite invalid order ID.
		$this->assertSame(
			'bb-session-invalid-order',
			WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() does not persist session ID when verification fails open.
	 */
	public function test_verify_session_does_not_persist_when_verification_fails(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-order-id' );
		$order->save_meta_data();
		WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-session-id' );

		$this->data_collector
			->method( 'get_collected_data' )
			->willThrowException( new \RuntimeException( 'Collector exploded' ) );

		$this->sut->verify_session( 'bb-session-fail', 'blocks_checkout', $order->get_id() );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'prior-order-id',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
		$this->assertSame( 'prior-session-id', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
	}

	/**
	 * @testdox verify_session() persists the Blackbox-returned session ID on the no-session path.
	 */
	public function test_verify_session_persists_returned_session_id_on_no_session_path(): void {
		$order = \WC_Helper_Order::create_order();

		$this->stub_verification_with_returned_id( 'bb-generated-noss' );

		// No collect ID was sent (collect failed / timed out); Blackbox generated one.
		$this->sut->verify_session( '', 'shortcode_checkout', $order->get_id() );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'bb-generated-noss',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
		$this->assertSame(
			'bb-generated-noss',
			WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() prefers the Blackbox-returned session ID over the request ID (degraded verify).
	 */
	public function test_verify_session_prefers_returned_session_id_over_request_id(): void {
		$order = \WC_Helper_Order::create_order();

		$this->stub_verification_with_returned_id( 'bb-returned-xyz' );

		$this->sut->verify_session( 'collect-abc', 'blocks_checkout', $order->get_id() );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'bb-returned-xyz',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
		$this->assertSame(
			'bb-returned-xyz',
			WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() persists the returned session ID and returns the block on a blocked degraded verify.
	 */
	public function test_verify_session_persists_returned_id_under_block_verdict(): void {
		$order = \WC_Helper_Order::create_order();

		// A tampered request ID degrades; Blackbox creates a new session and blocks it.
		$this->stub_verification_with_returned_id( 'bb-degraded-block', FraudDecision::Block );

		$result = $this->sut->verify_session( 'tampered-collect-id', 'blocks_checkout', $order->get_id() );

		$this->assertSame( FraudDecision::Block, $result );

		// The Blackbox-created ID is what /report must correlate against, not the tampered one.
		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'bb-degraded-block',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
		$this->assertSame(
			'bb-degraded-block',
			WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() does not attach a prior checkout's session ID to a new order when the current verify has none.
	 */
	public function test_verify_session_does_not_attach_stale_session_id_to_new_order(): void {
		$this->sut->register();

		// A prior checkout left an ID in the WC session.
		WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'stale-prior-id' );

		// A no-session verify for the new checkout returns no ID.
		$this->stub_verification_with_returned_id( '' );
		$this->sut->verify_session( '', 'shortcode_checkout', 0 );

		// The new order must not inherit the stale ID.
		$order = \WC_Helper_Order::create_order();
		do_action( 'woocommerce_checkout_order_created', $order );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox verify_session() clears current WC and order associations for an empty current result
	 */
	public function test_verify_session_clears_current_associations_for_empty_result(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-order-id' );
		$order->save_meta_data();
		WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-session-id' );

		$this->stub_verification_with_returned_id( '' );

		$this->assertSame( FraudDecision::Allow, $this->sut->verify_session( 'submitted-id', 'blocks_checkout', $order->get_id() ) );
		$this->assertSame( '', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$this->assertSame( '', wc_get_order( $order->get_id() )->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$this->assertSame( '', $this->sut->last_verified_session_id() );
	}

	/**
	 * @testdox No-session generated ID survives to order meta via the deferred order-created hook.
	 */
	public function test_no_session_generated_id_reaches_order_meta_via_deferred_hook(): void {
		$this->sut->register();

		$this->stub_verification_with_returned_id( 'bb-generated-deferred' );

		// Shortcode checkout no-session verify: empty collect ID, no order yet.
		$this->sut->verify_session( '', 'shortcode_checkout', 0 );

		// Generated ID landed in the WC session.
		$this->assertSame(
			'bb-generated-deferred',
			WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);

		// Order is created; the deferred hook copies the ID to order meta for reporting.
		$order = \WC_Helper_Order::create_order();
		do_action( 'woocommerce_checkout_order_created', $order );

		$saved_order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $saved_order );
		$this->assertSame(
			'bb-generated-deferred',
			$saved_order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY )
		);
	}

	/**
	 * @testdox register() hooks persist_session_id_to_order to woocommerce_checkout_order_created.
	 */
	public function test_register_hooks_deferred_persistence(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_checkout_order_created', array( $this->sut, 'persist_session_id_to_order' ) )
		);
	}

	/**
	 * Stub a successful verification pipeline (data collector, API, decision handler).
	 *
	 * The API client stub returns the requested string as response-backed test
	 * data. Production association state still comes only from the result.
	 */
	private function stub_successful_verification(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willReturnCallback(
				function ( string $session_id ) {
					return VerifyResult::create( FraudDecision::Allow, $session_id );
				}
			);

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );
	}

	/**
	 * Stub a verification pipeline whose verify result carries a specific session ID.
	 *
	 * @param string        $returned_session_id The effective session ID carried in the verify result.
	 * @param FraudDecision $decision            The decision returned by both verify() and apply_decision().
	 */
	private function stub_verification_with_returned_id( string $returned_session_id, FraudDecision $decision = FraudDecision::Allow ): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willReturn( VerifyResult::create( $decision, $returned_session_id ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( $decision );
	}

	/*
	|--------------------------------------------------------------------------
	| Should Verify Filter Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_session() applies a supplied ALLOW and stores its session ID on the order.
	 */
	public function test_verify_session_applies_an_allow_supplied_by_the_filter(): void {
		$order = \WC_Helper_Order::create_order();
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-order-id' );
		$order->save_meta_data();
		WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'prior-session-id' );

		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function () {
				return new SuppliedDecision( FraudDecision::Allow, 'response-session-id' );
			}
		);

		$this->api_client
			->expects( $this->never() )
			->method( 'verify' );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout', $order->get_id() );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertSame( 'prior-session-id', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$this->assertSame( 'response-session-id', wc_get_order( $order->get_id() )->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$this->assertSame( '', $this->sut->last_verified_session_id() );
		$this->assertLogged( 'info', 'Decision supplied by `woocommerce_fraud_protection_skip_session_verify` filter for source: blocks_checkout' );
	}

	/**
	 * @testdox verify_session() applies a BLOCK supplied by the filter without calling the API.
	 *
	 * The whole reason this filter carries a decision rather than a boolean: a
	 * consumer that already scored this attempt and got a block must be able to say
	 * so. A boolean could only ever mean "do not verify", which reads as allow.
	 */
	public function test_verify_session_applies_a_block_supplied_by_the_filter(): void {
		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function () {
				return new SuppliedDecision( FraudDecision::Block );
			}
		);

		$this->api_client
			->expects( $this->never() )
			->method( 'verify' );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Block, $result );
	}

	/**
	 * @testdox verify_session() verifies normally when the filter passes its default through.
	 *
	 * The standard WordPress shape for a consumer with nothing to say: return the
	 * value received. Skipping takes a decision; not skipping takes nothing.
	 */
	public function test_verify_session_proceeds_when_filter_passes_the_default_through(): void {
		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function ( $decision ) {
				return $decision;
			}
		);

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Allow, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * @testdox verify_session() ignores a malformed filter return and verifies.
	 *
	 * A bool is the shape this filter's pre-0.1.6 contract used. It must not be
	 * honoured: `true` would otherwise mean "allow", which is the conflation the
	 * decision-carrying contract removed. Null is not a signal either — a
	 * consumer with nothing to say passes the default through instead.
	 *
	 * @dataProvider malformed_filter_returns
	 *
	 * @param mixed $returned What the filter hands back.
	 */
	public function test_verify_session_ignores_a_malformed_filter_return( $returned ): void {
		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function () use ( $returned ) {
				return $returned;
			}
		);

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Allow, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$this->assertSame( FraudDecision::Allow, $this->sut->verify_session( 'test-session', 'blocks_checkout' ) );
	}

	/**
	 * @testdox verify_session() warns about a malformed filter return, wherever in the chain it was made.
	 *
	 * The typed PayPalCompat callback already fails loudly for garbage put in
	 * the chain before it; this warning covers the rest of the chain, so a
	 * miscalling consumer is named in the log regardless of its priority.
	 */
	public function test_verify_session_warns_about_a_malformed_filter_return(): void {
		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function () {
				return 'allow';
			}
		);

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Allow, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertLogged(
			'warning',
			'`woocommerce_fraud_protection_skip_session_verify` filter returned a non-decision',
			array( 'returned' => 'string' )
		);
	}

	/**
	 * @testdox verify_session() does not warn when the filter chain passes the default through.
	 *
	 * The untouched `false` seed is the one non-decision that is not a miscall;
	 * warning on it would flag every unfiltered checkout.
	 */
	public function test_verify_session_does_not_warn_about_the_untouched_default(): void {
		$spy = $this->spy_on_controller_logging();

		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->expects( $this->once() )
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Allow, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$warnings = array_filter(
			$spy->entries,
			function ( array $entry ): bool {
				return false !== strpos( $entry['message'], 'returned a non-decision' );
			}
		);

		$this->assertSame( array(), $warnings, 'The false seed must not be reported as a miscall.' );
	}

	/**
	 * Returns that must never stand in for a verdict.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function malformed_filter_returns(): array {
		return array(
			'true (the pre-0.1.6 skip shape)' => array( true ),
			'false'                           => array( false ),
			'null'                            => array( null ),
			'the decision as a string'        => array( 'allow' ),
			'a non-actionable decision'       => array( FraudDecision::Challenge ),
			'a non-actionable supplied result' => array( new SuppliedDecision( FraudDecision::Challenge ) ),
			'an unrelated object'             => array( new \stdClass() ),
		);
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
			->willReturn( VerifyResult::create( FraudDecision::Allow, '' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$result = $this->sut->verify_session( 'test-session', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'warning', '`woocommerce_fraud_protection_skip_session_verify` filter threw' );
	}

	/*
	|--------------------------------------------------------------------------
	| last_verified_session_id() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox last_verified_session_id() carries the effective ID of a completed verification.
	 *
	 * The effective ID is the one the verify resolved — which need not be the
	 * one the caller sent — and the one a caller recording the verification
	 * must key its record by.
	 */
	public function test_last_verified_session_id_returns_the_resolved_id(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Allow, 'resolved-id' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_session( 'sent-id', 'blocks_checkout' );

		$this->assertSame( 'resolved-id', $this->sut->last_verified_session_id() );
	}

	/**
	 * @testdox last_verified_session_id() is empty after a supplied decision, not a stale earlier ID.
	 */
	public function test_last_verified_session_id_is_empty_after_a_supplied_decision(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->willReturn( array() );

		$this->api_client
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Allow, 'resolved-id' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		// A completed verification first, so a stale ID would be there to leak.
		$this->sut->verify_session( 'sent-id', 'blocks_checkout' );

		add_filter(
			'woocommerce_fraud_protection_skip_session_verify',
			function () {
				return new SuppliedDecision( FraudDecision::Block );
			}
		);

		$this->sut->verify_session( 'another-id', 'blocks_checkout' );

		$this->assertSame( '', $this->sut->last_verified_session_id() );
	}

	/**
	 * @testdox last_verified_session_id() is empty after a verification that failed open.
	 */
	public function test_last_verified_session_id_is_empty_after_a_failed_verification(): void {
		$this->data_collector
			->method( 'get_collected_data' )
			->will(
				$this->onConsecutiveCalls(
					$this->returnValue( array() ),
					$this->throwException( new \RuntimeException( 'Collector exploded' ) )
				)
			);

		$this->api_client
			->method( 'verify' )
			->willReturn( VerifyResult::create( FraudDecision::Allow, 'resolved-id' ) );

		$this->decision_handler
			->method( 'apply_decision' )
			->willReturn( FraudDecision::Allow );

		// A completed verification first, so a stale ID would be there to leak.
		$this->sut->verify_session( 'sent-id', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Allow, $this->sut->verify_session( 'another-id', 'blocks_checkout' ) );
		$this->assertSame( '', $this->sut->last_verified_session_id() );
	}

}
