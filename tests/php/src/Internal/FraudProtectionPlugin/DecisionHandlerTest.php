<?php
/**
 * DecisionHandlerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleEvaluator;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;
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
	 * Mock rule evaluator, reporting no rule match by default.
	 *
	 * @var RuleEvaluator&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $rule_evaluator;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->original_session = WC()->session;

		$this->event_recorder = $this->createMock( SessionEventRecorder::class );
		$this->rule_evaluator = $this->createMock( RuleEvaluator::class );
		$this->sut            = new DecisionHandler();
		$this->sut->init( $this->event_recorder, $this->rule_evaluator );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		WC()->session = $this->original_session;
		remove_all_filters( 'woocommerce_fraud_protection_automated_decision' );
		remove_all_filters( 'woocommerce_fraud_protection_learning_mode' );
		remove_all_actions( 'woocommerce_fraud_protection_rule_applied' );
		parent::tearDown();
	}

	/**
	 * Test apply allow decision.
	 *
	 * @testdox Should return the allow decision unchanged.
	 */
	public function test_apply_allow_decision(): void {
		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * Test apply block decision.
	 *
	 * @testdox Should return the block decision unchanged when learning mode is off.
	 */
	public function test_apply_block_decision(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
	}

	/**
	 * Test that block decisions are not sticky across attempts.
	 *
	 * Regression test: a block verdict must apply only to the attempt that
	 * produced it. A subsequent verify returning allow must be honored, so
	 * false positives can retry and the `woocommerce_fraud_protection_automated_decision`
	 * whitelist filter remains a working recovery path.
	 *
	 * @testdox Should honor an allow decision on the attempt following a block.
	 */
	public function test_block_decision_is_not_sticky_across_attempts(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$first_result  = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );
		$second_result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

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

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );

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
		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Challenge, 'test-session' ), array( 'session_id' => 'test' ) );

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
			'woocommerce_fraud_protection_automated_decision',
			function () {
				return FraudDecision::Allow;
			}
		);

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'info', 'Decision overridden by filter `woocommerce_fraud_protection_automated_decision`' );
	}

	/**
	 * Test filter can override allow to block.
	 *
	 * @testdox Should allow filter to override decision from allow to block.
	 */
	public function test_filter_can_override_allow_to_block(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function () {
				return FraudDecision::Block;
			}
		);

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
		$this->assertLogged( 'info', 'Decision overridden by filter `woocommerce_fraud_protection_automated_decision`' );
	}

	/**
	 * Test filter invalid return uses original decision.
	 *
	 * @testdox Should reject invalid filter return value and use original decision.
	 */
	public function test_filter_invalid_return_uses_original_decision(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function () {
				return 'totally_invalid';
			}
		);

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
		$this->assertLogged( 'warning', 'Filter `woocommerce_fraud_protection_automated_decision` returned invalid decision "totally_invalid"' );
	}

	/**
	 * @testdox A throwing automated-decision filter uses and records the decision that entered it.
	 * @dataProvider automated_filter_throw_scenarios
	 *
	 * @param FraudDecision $entry_decision         The decision that enters the filter.
	 * @param \Throwable    $throwable              The error from the filter callback.
	 * @param bool          $add_allow_before_throw Whether an earlier callback returns Allow.
	 */
	public function test_automated_filter_throw_uses_entry_decision_and_records( FraudDecision $entry_decision, \Throwable $throwable, bool $add_allow_before_throw ): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		if ( $add_allow_before_throw ) {
			add_filter(
				'woocommerce_fraud_protection_automated_decision',
				function () {
					return FraudDecision::Allow;
				},
				10
			);
		}

		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function () use ( $throwable ) {
				throw $throwable;
			},
			20
		);

		$verify_result = VerifyResult::create( $entry_decision, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, $entry_decision, $this->anything() );

		$result = $this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );

		$this->assertSame( $entry_decision, $result );
		$this->assertLogged(
			'warning',
			'Filter `woocommerce_fraud_protection_automated_decision` threw. Using the decision that entered the filter.',
			array(
				'filter'            => 'woocommerce_fraud_protection_automated_decision',
				'decision_received' => $entry_decision->value,
				'exception_class'   => $throwable::class,
				'exception_message' => $throwable->getMessage(),
				'exception_file'    => $throwable->getFile(),
				'exception_line'    => $throwable->getLine(),
			),
			true
		);
	}

	/**
	 * Automated-decision filter error scenarios.
	 *
	 * @return array<string, array{FraudDecision, \Throwable, bool}>
	 */
	public function automated_filter_throw_scenarios(): array {
		return array(
			'block after an earlier Allow' => array( FraudDecision::Block, new \RuntimeException( 'Broken decision filter' ), true ),
			'allow'                        => array( FraudDecision::Allow, new \RuntimeException( 'Broken decision filter' ), false ),
			'Error'                        => array( FraudDecision::Block, new \Error( 'Broken decision filter' ), false ),
		);
	}

	/**
	 * @testdox Default learning mode suppresses a Block recovered after an automated-decision filter error.
	 */
	public function test_automated_filter_throw_still_applies_default_learning_mode(): void {
		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function () {
				throw new \RuntimeException( 'Broken decision filter' );
			}
		);

		$verify_result = VerifyResult::create( FraudDecision::Block, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, FraudDecision::Allow, $this->anything() );

		$result = $this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * @testdox Exposes the intentional verify_result subset (no session ID) to the decision filter.
	 */
	public function test_decision_filter_receives_intentional_verify_result(): void {
		$session_data = array(
			'session' => array( 'wc_identity_id' => 'identity-1' ),
			'payment' => array( 'gateway' => 'woocommerce_payments' ),
		);

		$received_by_filter = null;
		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function ( $decision, $data ) use ( &$received_by_filter ) {
				$received_by_filter = $data;
				return $decision;
			},
			10,
			2
		);

		$this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'session-abc', 0.42 ), $session_data );

		$this->assertIsArray( $received_by_filter );
		$this->assertSame(
			array(
				'risk_score'     => 0.42,
				'payment_method' => 'woocommerce_payments',
			),
			$received_by_filter['verify_result'],
			'The verify_result subset should carry exactly the risk score and payment method, no session ID'
		);
		$this->assertSame( array( 'wc_identity_id' => 'identity-1' ), $received_by_filter['session'], 'The rest of the session data should pass through unchanged' );
	}

	/**
	 * @testdox Exposes a null risk score and empty payment method to the decision filter when the verify produced none.
	 */
	public function test_decision_filter_receives_empty_verify_result_when_data_missing(): void {
		$received_by_filter = null;
		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function ( $decision, $data ) use ( &$received_by_filter ) {
				$received_by_filter = $data;
				return $decision;
			},
			10,
			2
		);

		$this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame(
			array(
				'risk_score'     => null,
				'payment_method' => '',
			),
			$received_by_filter['verify_result']
		);
	}

	/**
	 * @testdox Passes the verify result and the original session data, without the filter-only verify_result key, to the recorder.
	 */
	public function test_recorder_receives_result_and_original_session_data(): void {
		$session_data = array(
			'payment' => array( 'gateway' => 'woocommerce_payments' ),
		);
		$result       = VerifyResult::create( FraudDecision::Allow, 'session-abc', 0.42 );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $result, FraudDecision::Allow, $session_data );

		$this->sut->apply_decision( $result, $session_data );
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
		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'info', 'Learning mode: suppressing "block" decision' );
	}

	/**
	 * @testdox Learning mode suppresses filter override to block.
	 */
	public function test_learning_mode_suppresses_filter_override_to_block(): void {
		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function () {
				return FraudDecision::Block;
			}
		);

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
		$this->assertLogged( 'info', 'Learning mode: suppressing "block" decision' );
	}

	/**
	 * @testdox Records the received block decision with the applied allow when learning mode suppresses it.
	 */
	public function test_records_suppressed_block_decision(): void {
		$result = VerifyResult::create( FraudDecision::Block, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $result, FraudDecision::Allow, $this->anything() );

		$this->sut->apply_decision( $result, array( 'session_id' => 'test' ) );
	}

	/**
	 * @testdox Records the block decision as both received and applied when enforcement is active.
	 */
	public function test_records_enforced_block_decision(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$verify_result = VerifyResult::create( FraudDecision::Block, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, FraudDecision::Block, $this->anything() );

		$result = $this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
	}

	/**
	 * @testdox Records the received challenge decision with the applied allow while returning allow.
	 */
	public function test_records_challenge_decision_and_returns_allow(): void {
		$verify_result = VerifyResult::create( FraudDecision::Challenge, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, FraudDecision::Allow, $this->anything() );

		$result = $this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * @testdox Records the allow decision as both received and applied.
	 */
	public function test_records_allow_decision(): void {
		$verify_result = VerifyResult::create( FraudDecision::Allow, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, FraudDecision::Allow, $this->anything() );

		$this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );
	}

	/**
	 * @testdox Forwards a fail-open verify result to the recorder unchanged, so it can derive the verify_error trigger.
	 */
	public function test_forwards_fail_open_result_to_the_recorder(): void {
		$verify_result = VerifyResult::fail_open();

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, FraudDecision::Allow, $this->anything() );

		$this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Merchant Rules Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * A rule with the given action, as the evaluator would return it.
	 *
	 * @param FraudDecision $action The rule action.
	 * @return Rule
	 */
	private function a_matching_rule( FraudDecision $action ): Rule {
		return Rule::from_row(
			array(
				'id'         => 7,
				'action'     => $action->value,
				'status'     => 'active',
				'position'   => 1,
				'conditions' => '{"field":"email","operator":"equals","value":"someone@example.com"}',
				'created_at' => '2026-07-27 00:00:00',
			)
		);
	}

	/**
	 * @testdox A matching merchant block rule enforces even in learning mode.
	 */
	public function test_matching_block_rule_enforces_in_learning_mode(): void {
		$this->rule_evaluator->method( 'evaluate_for_session' )->willReturn( $this->a_matching_rule( FraudDecision::Block ) );

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result, 'The merchant block rule must enforce even while learning mode (default) is on' );
		$this->assertLogged( 'info', 'Merchant rule 7 decided the session: "block"' );
	}

	/**
	 * @testdox A matching merchant allow rule overrides a Blackbox block verdict.
	 */
	public function test_matching_allow_rule_overrides_block_verdict(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$this->rule_evaluator->method( 'evaluate_for_session' )->willReturn( $this->a_matching_rule( FraudDecision::Allow ) );

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * @testdox A matching rule bypasses the decision filter entirely.
	 */
	public function test_matching_rule_bypasses_decision_filter(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$filter_called = false;
		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function ( $decision ) use ( &$filter_called ) {
				$filter_called = true;
				return FraudDecision::Block;
			}
		);

		$this->rule_evaluator->method( 'evaluate_for_session' )->willReturn( $this->a_matching_rule( FraudDecision::Allow ) );

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Block, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result, 'The rule action must be final' );
		$this->assertFalse( $filter_called, 'The decision filter must not run when a merchant rule decided the session' );
	}

	/**
	 * @testdox Records the received decision with the rule action and the matched rule when a rule decides.
	 */
	public function test_records_matched_rule(): void {
		$rule = $this->a_matching_rule( FraudDecision::Block );
		$this->rule_evaluator->method( 'evaluate_for_session' )->willReturn( $rule );

		$verify_result = VerifyResult::create( FraudDecision::Allow, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, FraudDecision::Block, $this->anything(), $rule );

		$this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );
	}

	/**
	 * @testdox Records no matched rule when no rule decided the session.
	 */
	public function test_records_null_matched_rule_when_no_rule_matches(): void {
		$verify_result = VerifyResult::create( FraudDecision::Allow, 'test-session' );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( $verify_result, FraudDecision::Allow, $this->anything(), null );

		$this->sut->apply_decision( $verify_result, array( 'session_id' => 'test' ) );
	}

	/**
	 * @testdox Fires the rule_applied action with the rule id and the applied and received decisions when a rule decides.
	 */
	public function test_rule_applied_action_fires_with_rule_details(): void {
		$this->rule_evaluator->method( 'evaluate_for_session' )->willReturn( $this->a_matching_rule( FraudDecision::Block ) );

		$action_args = null;
		add_action(
			'woocommerce_fraud_protection_rule_applied',
			function ( ...$args ) use ( &$action_args ) {
				$action_args = $args;
			},
			10,
			4
		);

		$this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session', 0.42 ), array( 'session_id' => 'test' ) );

		$expected_session_data = array(
			'session_id'    => 'test',
			'verify_result' => array(
				'risk_score'     => 0.42,
				'payment_method' => '',
			),
		);
		$this->assertSame( array( 7, FraudDecision::Block, FraudDecision::Allow, $expected_session_data ), $action_args );
	}

	/**
	 * @testdox Does not fire the rule_applied action when no rule decided the session.
	 */
	public function test_rule_applied_action_does_not_fire_without_rule_match(): void {
		$action_fired = false;
		add_action(
			'woocommerce_fraud_protection_rule_applied',
			function () use ( &$action_fired ) {
				$action_fired = true;
			}
		);

		$this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertFalse( $action_fired, 'The action must only fire for rule-decided sessions' );
	}

	/**
	 * @testdox A throwing rule_applied listener does not change the rule outcome.
	 */
	public function test_throwing_rule_applied_listener_does_not_change_the_outcome(): void {
		$this->rule_evaluator->method( 'evaluate_for_session' )->willReturn( $this->a_matching_rule( FraudDecision::Block ) );

		add_action(
			'woocommerce_fraud_protection_rule_applied',
			function () {
				throw new \RuntimeException( 'Broken listener' );
			}
		);

		$result = $this->sut->apply_decision( VerifyResult::create( FraudDecision::Allow, 'test-session' ), array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result, 'The rule decision must survive a throwing listener' );
		$this->assertLogged( 'warning', 'A callback hooked to `woocommerce_fraud_protection_rule_applied` threw an exception.' );
	}
}
