<?php
/**
 * PerAttemptBlockingIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\BlocksCheckoutProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleEvaluator;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

/**
 * End-to-end test for the per-attempt blocking invariant (WOOSUBS-1769).
 *
 * Exercises the real protector -> SessionVerifier -> DecisionHandler pipeline
 * with only the Blackbox transport stubbed: a block verdict must reject the
 * attempt that produced it without persisting any block state, and the next
 * attempt must be re-verified from scratch.
 */
class PerAttemptBlockingIntegrationTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var BlocksCheckoutProtector
	 */
	private $sut;

	/**
	 * Partial mock ApiClient with only the transport seam stubbed.
	 *
	 * @var ApiClient&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $api_client;

	/**
	 * The session handler in place before the test, restored in tearDown().
	 *
	 * @var \WC_Session|null
	 */
	private $original_session;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;
		wc_load_cart();

		$this->api_client = $this->getMockBuilder( ApiClient::class )
			->onlyMethods( array( 'jetpack_remote_request' ) )
			->getMock();
		$session_id_normalizer = new SessionIdNormalizer();
		$this->api_client->init( wc_get_container()->get( VisitorIpResolver::class ), $session_id_normalizer );

		$decision_handler = new DecisionHandler();
		$decision_handler->init( $this->createMock( SessionEventRecorder::class ), $this->createMock( RuleEvaluator::class ) );

		$session_verifier = new SessionVerifier();
		$session_verifier->init(
			wc_get_container()->get( SessionDataCollector::class ),
			$this->api_client,
			$decision_handler,
			wc_get_container()->get( PaymentDataResolver::class ),
			$session_id_normalizer
		);

		$this->sut = new BlocksCheckoutProtector();
		$this->sut->init( $session_verifier, new BlockedSessionMessage(), $this->make_blackbox_script_handler() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		WC()->cart->empty_cart();
		if ( WC()->session ) {
			WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, null );
		}
		WC()->session = $this->original_session;
		remove_all_filters( 'woocommerce_fraud_protection_learning_mode' );
		parent::tearDown();
	}

	/**
	 * @testdox Should reject a blocked attempt without persisting block state, and re-verify the next attempt from scratch.
	 */
	public function test_block_applies_to_single_attempt_and_next_attempt_is_reverified(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		// The transport must be hit once per attempt: a block verdict is not
		// cached anywhere, so the second attempt triggers a fresh verify.
		$this->api_client
			->expects( $this->exactly( 2 ) )
			->method( 'jetpack_remote_request' )
			->willReturnOnConsecutiveCalls(
				$this->decision_response( 'block', 'blackbox-attempt-1' ),
				$this->decision_response( 'allow', 'blackbox-attempt-2' )
			);

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 2 );
		$cart_count = WC()->cart->get_cart_contents_count();
		$this->assertGreaterThan( 0, $cart_count );

		$order = \WC_Helper_Order::create_order();

		$this->sut->extract_request_data( $order, $this->checkout_request( 'blackbox-attempt-1' ) );
		$blocked = null;
		try {
			$this->sut->verify_and_block( $order );
		} catch ( RouteException $exception ) {
			$blocked = $exception;
		}

		$this->assertInstanceOf( RouteException::class, $blocked, 'The blocked attempt should be rejected with a RouteException' );
		$this->assertSame( 403, $blocked->getCode(), 'The blocked attempt should be rejected with a 403 status' );
		$this->assertSame( $cart_count, WC()->cart->get_cart_contents_count(), 'The cart should survive a blocked attempt' );
		$this->assertNull( WC()->session->get( '_fraud_protection_clearance_status' ), 'No block state should be persisted to the session' );

		$this->sut->extract_request_data( $order, $this->checkout_request( 'blackbox-attempt-2' ) );
		$this->sut->verify_and_block( $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame(
			'blackbox-attempt-2',
			$order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ),
			'The second attempt should run the full verify pipeline again'
		);

		$product->delete( true );
	}

	/**
	 * @testdox A received 413 blocks only that attempt and the next attempt verifies again.
	 */
	public function test_rejected_request_blocks_single_attempt_and_next_attempt_is_reverified(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );
		$this->api_client
			->expects( $this->exactly( 2 ) )
			->method( 'jetpack_remote_request' )
			->willReturnOnConsecutiveCalls(
				array(
					'response' => array( 'code' => 413 ),
					'body'     => 'Request rejected',
				),
				$this->decision_response( 'allow', 'blackbox-attempt-2' )
			);

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$order = \WC_Helper_Order::create_order();

		$this->sut->extract_request_data( $order, $this->checkout_request( 'blackbox-attempt-1' ) );
		$blocked = null;
		try {
			$this->sut->verify_and_block( $order );
		} catch ( RouteException $exception ) {
			$blocked = $exception;
		}

		$this->assertInstanceOf( RouteException::class, $blocked );
		$this->assertSame( '', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );

		$this->sut->extract_request_data( $order, $this->checkout_request( 'blackbox-attempt-2' ) );
		$this->sut->verify_and_block( $order );

		$order = wc_get_order( $order->get_id() );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'blackbox-attempt-2', $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$product->delete( true );
	}

	/**
	 * Create a blocks checkout REST request carrying a Blackbox session ID.
	 *
	 * @param string $blackbox_session_id The Blackbox session ID for the attempt.
	 * @return \WP_REST_Request
	 */
	private function checkout_request( string $blackbox_session_id ): \WP_REST_Request {
		$request = new \WP_REST_Request();
		$request->set_param( 'payment_method', 'cod' );
		$request->set_param(
			'extensions',
			array(
				'woocommerce/fraud-protection' => array(
					'blackbox_session_id' => $blackbox_session_id,
				),
			)
		);

		return $request;
	}

	/**
	 * A canned successful transport response carrying the given decision.
	 *
	 * @param string $decision   The decision to return in the response body.
	 * @param string $session_id The response-backed session ID.
	 * @return array<string, mixed>
	 */
	private function decision_response( string $decision, string $session_id ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode(
				array(
					'data' => array(
						'decision'   => $decision,
						'session_id' => $session_id,
					),
				)
			),
		);
	}
}
