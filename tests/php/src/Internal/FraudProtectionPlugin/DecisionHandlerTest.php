<?php
/**
 * DecisionHandlerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the DecisionHandler class.
 */
class DecisionHandlerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var DecisionHandler
	 */
	private $sut;

	/**
	 * The session handler in place before the test, restored in tearDown().
	 *
	 * @var \WC_Session|null
	 */
	private $original_session;

	/**
	 * Mock session event recorder.
	 *
	 * @var SessionEventRecorder&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_recorder;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;

		$this->event_recorder = $this->createMock( SessionEventRecorder::class );
		$this->sut            = new DecisionHandler();
		$this->sut->init( $this->event_recorder );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		WC()->session = $this->original_session;
		remove_all_filters( 'woocommerce_fraud_protection_decision' );
		remove_all_filters( 'woocommerce_fraud_protection_learning_mode' );
		parent::tearDown();
	}

	/**
	 * Test apply allow decision.
	 *
	 * @testdox Should return the allow decision unchanged.
	 */
	public function test_apply_allow_decision(): void {
		$result = $this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * Test apply block decision.
	 *
	 * @testdox Should return the block decision unchanged when learning mode is off.
	 */
	public function test_apply_block_decision(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
	}

	/**
	 * Test that block decisions are not sticky across attempts.
	 *
	 * Regression test: a block verdict must apply only to the attempt that
	 * produced it. A subsequent verify returning allow must be honored, so
	 * false positives can retry and the `woocommerce_fraud_protection_decision`
	 * whitelist filter remains a working recovery path.
	 *
	 * @testdox Should honor an allow decision on the attempt following a block.
	 */
	public function test_block_decision_is_not_sticky_across_attempts(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$first_result  = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );
		$second_result = $this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $first_result );
		$this->assertSame( FraudDecision::Allow, $second_result );
	}

	/**
	 * Test that a block decision has no session or cart side effects.
	 *
	 * Regression test: blocking must not write any state to the WC session
	 * (the old sticky clearance status) nor empty the shopper's cart.
	 *
	 * @testdox Should leave the WC session and cart untouched on a block decision.
	 */
	public function test_block_decision_leaves_session_and_cart_untouched(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		WC()->session = new \WC_Session_Handler();
		WC()->session->init();

		$product = \WC_Helper_Product::create_simple_product();
		WC()->cart->add_to_cart( $product->get_id(), 1 );
		$cart_count = WC()->cart->get_cart_contents_count();
		$this->assertGreaterThan( 0, $cart_count );

		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
		$this->assertSame( $cart_count, WC()->cart->get_cart_contents_count(), 'Cart should not be emptied on block' );
		$this->assertNull( WC()->session->get( '_fraud_protection_clearance_status' ), 'No clearance status should be written to the session' );

		WC()->cart->empty_cart();
		$product->delete( true );
	}

	/**
	 * Test non-actionable decision input fails open to allow.
	 *
	 * @testdox Should coerce a non-actionable decision (challenge) to allow.
	 */
	public function test_non_actionable_decision_defaults_to_allow(): void {
		$result = $this->sut->apply_decision( FraudDecision::Challenge, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'warning', 'Non-actionable decision "challenge" received' );
	}

	/**
	 * Test filter can override block to allow.
	 *
	 * @testdox Should allow filter to override decision from block to allow.
	 */
	public function test_filter_can_override_block_to_allow(): void {
		add_filter(
			'woocommerce_fraud_protection_decision',
			function () {
				return FraudDecision::Allow;
			}
		);

		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'info', 'Decision overridden by filter `woocommerce_fraud_protection_decision`' );
	}

	/**
	 * Test filter can override allow to block.
	 *
	 * @testdox Should allow filter to override decision from allow to block.
	 */
	public function test_filter_can_override_allow_to_block(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		add_filter(
			'woocommerce_fraud_protection_decision',
			function () {
				return FraudDecision::Block;
			}
		);

		$result = $this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
		$this->assertLogged( 'info', 'Decision overridden by filter `woocommerce_fraud_protection_decision`' );
	}

	/**
	 * Test filter invalid return uses original decision.
	 *
	 * @testdox Should reject invalid filter return value and use original decision.
	 */
	public function test_filter_invalid_return_uses_original_decision(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		add_filter(
			'woocommerce_fraud_protection_decision',
			function () {
				return 'totally_invalid';
			}
		);

		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
		$this->assertLogged( 'warning', 'Filter `woocommerce_fraud_protection_decision` returned invalid decision "totally_invalid"' );
	}

	/**
	 * @testdox Exposes the intentional verify_result subset (no session ID) to the decision filter instead of the internal recorder bundle.
	 */
	public function test_decision_filter_receives_intentional_verify_result(): void {
		$session_data = array(
			'session' => array( 'wc_identity_id' => 'identity-1' ),
			SessionEventRecorder::VERIFY_RESULT_KEY => array(
				'session_id'     => 'session-abc',
				'risk_score'     => 0.42,
				'payment_method' => 'woocommerce_payments',
			),
		);

		$received_by_filter = null;
		add_filter(
			'woocommerce_fraud_protection_decision',
			function ( $decision, $data ) use ( &$received_by_filter ) {
				$received_by_filter = $data;
				return $decision;
			},
			10,
			2
		);

		$this->sut->apply_decision( FraudDecision::Allow, $session_data );

		$this->assertIsArray( $received_by_filter );
		$this->assertArrayNotHasKey( SessionEventRecorder::VERIFY_RESULT_KEY, $received_by_filter );
		$this->assertSame(
			array(
				'risk_score'     => 0.42,
				'payment_method' => 'woocommerce_payments',
			),
			$received_by_filter['verify_result']
		);
		$this->assertSame( array( 'wc_identity_id' => 'identity-1' ), $received_by_filter['session'], 'The rest of the session data should pass through unchanged' );
	}

	/**
	 * @testdox Exposes a null risk score and empty payment method to the decision filter when the verify produced none.
	 */
	public function test_decision_filter_receives_empty_verify_result_when_bundle_missing(): void {
		$received_by_filter = null;
		add_filter(
			'woocommerce_fraud_protection_decision',
			function ( $decision, $data ) use ( &$received_by_filter ) {
				$received_by_filter = $data;
				return $decision;
			},
			10,
			2
		);

		$this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );

		$this->assertSame(
			array(
				'risk_score'     => null,
				'payment_method' => '',
			),
			$received_by_filter['verify_result']
		);
	}

	/**
	 * @testdox Passes the original session data, internal recorder bundle included, to the recorder.
	 */
	public function test_recorder_receives_original_session_data(): void {
		$session_data = array(
			SessionEventRecorder::VERIFY_RESULT_KEY => array(
				'session_id'     => 'session-abc',
				'risk_score'     => 0.42,
				'payment_method' => 'woocommerce_payments',
			),
		);

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( FraudDecision::Allow, FraudDecision::Allow, SessionTrigger::Blackbox, $session_data );

		$this->sut->apply_decision( FraudDecision::Allow, $session_data );
	}

	/*
	|--------------------------------------------------------------------------
	| Learning Mode Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox Learning mode suppresses block decision from API.
	 */
	public function test_learning_mode_suppresses_block(): void {
		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'info', 'Learning mode: suppressing "block" decision' );
	}

	/**
	 * @testdox Learning mode suppresses filter override to block.
	 */
	public function test_learning_mode_suppresses_filter_override_to_block(): void {
		add_filter(
			'woocommerce_fraud_protection_decision',
			function () {
				return FraudDecision::Block;
			}
		);

		$result = $this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'info', 'Learning mode: suppressing "block" decision' );
	}

	/**
	 * @testdox Records the received block decision with the applied allow when learning mode suppresses it.
	 */
	public function test_records_suppressed_block_decision(): void {
		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( FraudDecision::Block, FraudDecision::Allow, SessionTrigger::Blackbox, $this->anything() );

		$this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );
	}

	/**
	 * @testdox Records the block decision as both received and applied when enforcement is active.
	 */
	public function test_records_enforced_block_decision(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( FraudDecision::Block, FraudDecision::Block, SessionTrigger::Blackbox, $this->anything() );

		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
	}

	/**
	 * @testdox Records the received challenge decision with the applied allow while returning allow.
	 */
	public function test_records_challenge_decision_and_returns_allow(): void {
		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( FraudDecision::Challenge, FraudDecision::Allow, SessionTrigger::Blackbox, $this->anything() );

		$result = $this->sut->apply_decision( FraudDecision::Challenge, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * @testdox Records the allow decision as both received and applied.
	 */
	public function test_records_allow_decision(): void {
		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( FraudDecision::Allow, FraudDecision::Allow, SessionTrigger::Blackbox, $this->anything() );

		$this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );
	}

	/**
	 * @testdox Forwards the given trigger to the recorder (verify_error for fail-open verifies).
	 */
	public function test_records_decision_with_the_given_trigger(): void {
		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( FraudDecision::Allow, FraudDecision::Allow, SessionTrigger::VerifyError, $this->anything() );

		$this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ), SessionTrigger::VerifyError );
	}
}
