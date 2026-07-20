<?php
/**
 * DecisionHandlerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionClearanceManager;
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
	 * Mock session clearance manager.
	 *
	 * @var SessionClearanceManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_manager;

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

		$this->session_manager = $this->createMock( SessionClearanceManager::class );
		$this->event_recorder  = $this->createMock( SessionEventRecorder::class );
		$this->sut             = new DecisionHandler();
		$this->sut->init( $this->session_manager, $this->event_recorder );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'woocommerce_fraud_protection_decision' );
		remove_all_filters( 'woocommerce_fraud_protection_learning_mode' );
		parent::tearDown();
	}

	/**
	 * Test apply allow decision.
	 *
	 * @testdox Should apply allow decision and update session to allowed when session is not blocked.
	 */
	public function test_apply_allow_decision(): void {
		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( false );

		$this->session_manager
			->expects( $this->once() )
			->method( 'allow_session' );

		$result = $this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Allow, $result );
	}

	/**
	 * Test allow decision does not overwrite blocked session.
	 *
	 * @testdox Should preserve blocked session status when allow decision is received.
	 *
	 * This prevents race conditions where emptying the cart during block_session
	 * causes subsequent fraud checks to return "allow" (due to lower cart value).
	 */
	public function test_allow_decision_does_not_overwrite_blocked_session(): void {
		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( true );

		$this->session_manager
			->expects( $this->never() )
			->method( 'allow_session' );

		$result = $this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );

		$this->assertLogged( 'info', 'Preserving blocked session status' );
	}

	/**
	 * Test apply block decision.
	 *
	 * @testdox Should apply block decision and update session to blocked.
	 */
	public function test_apply_block_decision(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$this->session_manager
			->expects( $this->once() )
			->method( 'block_session' );

		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
	}

	/**
	 * Test non-actionable decision input fails open to allow.
	 *
	 * @testdox Should coerce a non-actionable decision (challenge) to allow.
	 */
	public function test_non_actionable_decision_defaults_to_allow(): void {
		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( false );

		$this->session_manager
			->expects( $this->once() )
			->method( 'allow_session' );

		$this->session_manager
			->expects( $this->never() )
			->method( 'block_session' );

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

		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( false );

		$this->session_manager
			->expects( $this->once() )
			->method( 'allow_session' );

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

		$this->session_manager
			->expects( $this->once() )
			->method( 'block_session' );

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

		$this->session_manager
			->expects( $this->once() )
			->method( 'block_session' );

		$result = $this->sut->apply_decision( FraudDecision::Block, array( 'session_id' => 'test' ) );

		$this->assertSame( FraudDecision::Block, $result );
		$this->assertLogged( 'warning', 'Filter `woocommerce_fraud_protection_decision` returned invalid decision "totally_invalid"' );
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
		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( false );

		$this->session_manager
			->expects( $this->once() )
			->method( 'allow_session' );

		$this->session_manager
			->expects( $this->never() )
			->method( 'block_session' );

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

		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( false );

		$this->session_manager
			->expects( $this->once() )
			->method( 'allow_session' );

		$this->session_manager
			->expects( $this->never() )
			->method( 'block_session' );

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
		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( false );

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
		$this->session_manager
			->method( 'is_session_blocked' )
			->willReturn( false );

		$this->event_recorder
			->expects( $this->once() )
			->method( 'record_decision' )
			->with( FraudDecision::Allow, FraudDecision::Allow, SessionTrigger::Blackbox, $this->anything() );

		$this->sut->apply_decision( FraudDecision::Allow, array( 'session_id' => 'test' ) );
	}
}
