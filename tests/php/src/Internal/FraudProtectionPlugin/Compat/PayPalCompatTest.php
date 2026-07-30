<?php
/**
 * PayPalCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the PayPalCompat class.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat
 */
class PayPalCompatTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PayPalCompat
	 */
	private PayPalCompat $sut;

	/**
	 * Mock session verifier.
	 *
	 * @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_verifier;

	/**
	 * Mock blocked-session message generator.
	 *
	 * @var BlockedSessionMessage&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $blocked_session_message;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->session_verifier        = $this->createMock( SessionVerifier::class );
		$this->blocked_session_message = $this->createMock( BlockedSessionMessage::class );

		$this->blocked_session_message
			->method( 'get_plaintext' )
			->willReturn( 'We are unable to process this request online. Please contact support (test@example.com) to complete your purchase.' );

		$this->sut = new PayPalCompat();
		$this->sut->init(
			$this->session_verifier,
			$this->blocked_session_message
		);
	}

	/**
	 * Tear down after each test.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'woocommerce_fraud_protection_enqueue_blackbox_scripts' );
		remove_all_filters( 'woocommerce_fraud_protection_skip_session_verify' );
		remove_all_actions( 'woocommerce_paypal_payments_create_order_request_started' );
		remove_all_actions( 'wp_enqueue_scripts' );
		wp_dequeue_script( 'wc-fraud-protection-blackbox-init' );
		wp_dequeue_script( 'wc-fraud-protection-paypal-express' );

		if ( WC()->session ) {
			WC()->session->set( 'ppcp', null );
			WC()->session->set( '_fraud_protection_paypal_verification', null );
			WC()->session->set( '_fraud_protection_paypal_verified_session_id', null );
		}

		unset( $_GET['wc-ajax'] );

		parent::tearDown();
	}

	/*
	|--------------------------------------------------------------------------
	| register() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox register() hooks the create_order action, enqueue filter, and script action.
	 */
	public function test_register_hooks(): void {
		$this->sut->register();

		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_create_order_request_started', array( $this->sut, 'verify_and_block_create_order' ) ),
			'create_order_request_started action should be registered'
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', array( $this->sut, 'should_enqueue_blackbox' ) ),
			'enqueue_blackbox_scripts filter should be registered'
		);
		$this->assertNotFalse(
			has_action( 'wp_enqueue_scripts', array( $this->sut, 'enqueue_paypal_script' ) ),
			'wp_enqueue_scripts action should be registered'
		);
		$this->assertNotFalse(
			has_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this->sut, 'supply_decision_for_paypal_express' ) ),
			'should_verify_session filter should be registered'
		);
		$this->assertNotFalse(
			has_action( 'woocommerce_paypal_payments_paypal_order_created', array( $this->sut, 'bind_created_order_to_verification' ) ),
			'paypal_order_created action should be registered'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| verify_and_block_create_order() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox verify_and_block_create_order() extracts session_id from data and calls verify_session — allows on ALLOW.
	 */
	public function test_verify_allows_on_allow_decision(): void {
		$data = array( SessionVerifier::SESSION_ID_FIELD => 'test-session-abc' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-abc', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Allow );

		$this->session_verifier
			->method( 'last_verified_session_id' )
			->willReturn( 'test-session-abc' );

		// Should return normally without terminating.
		$this->sut->verify_and_block_create_order( $data );

		$this->assertSame(
			array(
				'session_id'  => 'test-session-abc',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
				'order_id'    => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' )
		);
	}

	/**
	 * @testdox verify_and_block_create_order() does not store empty session ID in WC session.
	 */
	public function test_verify_does_not_store_empty_session_id(): void {
		$data = array( 'context' => 'product' );

		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block_create_order( $data );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox verify_and_block_create_order() sends JSON error with 403 on BLOCK decision.
	 */
	public function test_verify_blocks_on_block_decision(): void {
		$data = array( SessionVerifier::SESSION_ID_FIELD => 'test-session-blocked' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( 'test-session-blocked', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Block );

		$this->blocked_session_message
			->expects( $this->once() )
			->method( 'get_plaintext' )
			->with( MessageContext::Purchase );

		// wp_send_json_error() echoes JSON then calls wp_die(). Force AJAX
		// context (otherwise it calls bare die()) and override the die
		// handler to throw a catchable exception.
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException();
				};
			}
		);

		$this->expectException( \WPDieException::class );
		$this->expectOutputRegex( '/"success":false.*unable to process this request/' );

		$this->sut->verify_and_block_create_order( $data );
	}

	/**
	 * @testdox verify_and_block_create_order() calls verify with empty session_id when field is missing.
	 */
	public function test_verify_with_missing_session_id(): void {
		$data = array( 'context' => 'product' );

		$this->session_verifier
			->expects( $this->once() )
			->method( 'verify_session' )
			->with( '', 'paypal_express_order_creation', 0, $data )
			->willReturn( FraudDecision::Allow );

		$this->sut->verify_and_block_create_order( $data );
	}

	/*
	|--------------------------------------------------------------------------
	| should_enqueue_blackbox() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox should_enqueue_blackbox() returns true when already set to enqueue.
	 */
	public function test_should_enqueue_blackbox_passthrough_when_already_true(): void {
		$this->assertTrue( $this->sut->should_enqueue_blackbox( true ) );
	}

	/**
	 * @testdox should_enqueue_blackbox() returns false when PayPal is not available.
	 */
	public function test_should_enqueue_blackbox_false_when_no_paypal(): void {
		// No PayPal gateways registered by default.
		$this->assertFalse( $this->sut->should_enqueue_blackbox( false ) );
	}

	/*
	|--------------------------------------------------------------------------
	| enqueue_paypal_script() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox enqueue_paypal_script() enqueues when blackbox-init is already enqueued.
	 */
	public function test_enqueue_paypal_script_when_blackbox_init_enqueued(): void {
		wp_enqueue_script( 'wc-fraud-protection-blackbox-init', 'https://example.com/blackbox-init.js', array(), '1.0', true );

		$this->sut->enqueue_paypal_script();

		$this->assertTrue( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/**
	 * @testdox enqueue_paypal_script() does not enqueue when blackbox-init is absent.
	 */
	public function test_enqueue_paypal_script_skips_when_blackbox_init_absent(): void {
		$this->sut->enqueue_paypal_script();

		$this->assertFalse( wp_script_is( 'wc-fraud-protection-paypal-express', 'enqueued' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| supply_decision_for_paypal_express() Tests
	|--------------------------------------------------------------------------
	*/

	/**
	 * Drive the filter callback the way SessionVerifier does.
	 *
	 * Seeds the call with `false`, the filter's default. A deferral passes that
	 * default through untouched, so it comes back as `false`.
	 *
	 * @param string $source         Source identifier.
	 * @param string $payment_method Gateway id on the request.
	 * @param string $session_id     Blackbox session ID on the request.
	 * @return mixed A FraudDecision when answered, false when deferred.
	 */
	private function ask( string $source, string $payment_method, string $session_id ) {
		return $this->sut->supply_decision_for_paypal_express(
			false,
			$source,
			array( 'payment_method' => $payment_method ),
			$session_id
		);
	}

	/**
	 * Run a create-order verification with the given decision.
	 *
	 * @param string        $session_id          The session ID the request presents.
	 * @param FraudDecision $decision            What the verifier returns.
	 * @param ?string       $resolved_session_id The session ID the verifier resolves, when it differs.
	 */
	private function score_create_order( string $session_id, FraudDecision $decision, ?string $resolved_session_id = null ): void {
		$this->session_verifier
			->method( 'verify_session' )
			->willReturn( $decision );

		// A completed verification exposes the session ID it resolved; the
		// record is keyed by that, not by what the request presented.
		$this->session_verifier
			->method( 'last_verified_session_id' )
			->willReturn( $resolved_session_id ?? $session_id );

		if ( FraudDecision::Block !== $decision ) {
			$this->sut->verify_and_block_create_order(
				array( SessionVerifier::SESSION_ID_FIELD => $session_id )
			);
			return;
		}

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			function () {
				return function () {
					throw new \WPDieException();
				};
			}
		);

		try {
			$this->sut->verify_and_block_create_order(
				array( SessionVerifier::SESSION_ID_FIELD => $session_id )
			);
			$this->fail( 'Expected the block response to terminate the request.' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
	}

	/**
	 * @testdox An approved order alone no longer answers for any gateway.
	 *
	 * Deliberate 0.1.6 behavior change, not a regression: this route used to
	 * stand down whenever any order sat in PayPal's session slot, whatever it
	 * was and whoever put it there. It now answers only for the order the
	 * recorded verification minted; with nothing recorded, every request
	 * defers to a real verify.
	 */
	public function test_supply_defers_for_an_approved_order_nothing_here_scored(): void {
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-FOREIGN' ) ) );

		$gateways = array( 'ppcp-gateway', 'ppcp-credit-card-gateway', 'ppcp-applepay', 'ppcp-googlepay', 'ppcp-axo-gateway' );

		foreach ( $gateways as $gateway ) {
			$this->assertFalse(
				$this->ask( 'blocks_checkout', $gateway, 'some-session-id' ),
				"Expected a deferral for gateway: $gateway"
			);
		}
	}

	/**
	 * @testdox The ppc-create-order query parameter alone answers for nothing.
	 *
	 * The query string is supplied by whoever made the request. A request that
	 * merely says it is ppc-create-order must never be enough to omit verification.
	 */
	public function test_supply_defers_on_the_create_order_query_parameter(): void {
		$_GET['wc-ajax'] = 'ppc-create-order';

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'ppcp-gateway', 'some-session-id' ),
			'A request parameter must never be enough to omit verification entirely.'
		);
	}

	/**
	 * @testdox A record under the retired pre-0.1.6 session key is not read.
	 *
	 * Records written by earlier versions are orphaned by the key rename, not
	 * migrated: they age out with their WC session. A shopper updating
	 * mid-checkout gets one extra real verification — never a skip.
	 */
	public function test_supply_ignores_a_record_under_the_retired_session_key(): void {
		WC()->session->set( '_fraud_protection_paypal_verified_session_id', 'test-session-abc' );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'test-session-abc' ) );
	}

	/**
	 * @testdox Two empty session IDs are not a match.
	 */
	public function test_supply_defers_when_both_session_ids_are_empty(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => '',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', '' ) );
	}

	/**
	 * @testdox A session ID that does not match the recorded one is not answered for.
	 */
	public function test_supply_defers_when_session_id_does_not_match(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'old-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-ideal', 'new-session' ) );
	}

	/**
	 * @testdox A ppcp-* flow with nothing recorded is not answered for.
	 */
	public function test_supply_defers_for_paypal_without_anything_recorded(): void {
		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'some-session-id' ) );
	}

	/**
	 * @testdox A record this code could not have written is not answered from.
	 *
	 * Only the shape record_create_order_verification() writes counts as a
	 * record. A matching session ID whose decision no verification produced
	 * must be verified for real, not served a normalized allow.
	 */
	public function test_supply_defers_when_the_record_is_malformed(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'some-session-id',
				'stand_downs' => 0,
				'decision'    => 'block',
			)
		);

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'some-session-id' ) );
	}

	/**
	 * @testdox A non-PayPal gateway is never answered for, even with an approved order in session.
	 */
	public function test_supply_defers_for_non_paypal_gateway(): void {
		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'stripe', 'some-session-id' ) );
	}

	/**
	 * @testdox This class does not answer for its own verification source.
	 */
	public function test_supply_defers_for_own_source(): void {
		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'paypal_express_order_creation', 'ppcp-gateway', 'some-session-id' ) );
	}

	/**
	 * @testdox An earlier consumer's decision is passed through when this class defers.
	 *
	 * Every deferral path returns the value received, so a decision put in the
	 * chain by an earlier consumer survives a request this class has nothing
	 * to say about.
	 */
	public function test_supply_passes_an_earlier_decision_through_when_it_defers(): void {
		$this->assertSame(
			FraudDecision::Block,
			$this->sut->supply_decision_for_paypal_express(
				FraudDecision::Block,
				'blocks_checkout',
				array( 'payment_method' => 'stripe' ),
				'some-session-id'
			)
		);
	}

	/**
	 * @testdox The record answers at this callback's priority, over an earlier consumer's decision.
	 *
	 * Standard filter arbitration: this callback answers from its record at its
	 * own priority, whatever an earlier consumer returned. A consumer that wants
	 * the last word registers with a later priority.
	 */
	public function test_supply_answers_from_its_record_over_an_earlier_decision(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'some-session-id',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->assertSame(
			FraudDecision::Allow,
			$this->sut->supply_decision_for_paypal_express(
				FraudDecision::Block,
				'blocks_checkout',
				array( 'payment_method' => 'ppcp-gateway' ),
				'some-session-id'
			)
		);
	}

	/**
	 * @testdox A malformed value in the chain fails loudly instead of being silently ignored.
	 *
	 * The declared parameter type is the warning: an earlier consumer returning
	 * something that is neither a FraudDecision nor the default raises a
	 * TypeError, which SessionVerifier logs as a warning and answers with a
	 * real verify.
	 */
	public function test_supply_rejects_a_malformed_earlier_value_loudly(): void {
		$this->expectException( \TypeError::class );

		$this->sut->supply_decision_for_paypal_express(
			'allow',
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'some-session-id'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| In-request marker
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A create-order verification answers for the form validation that follows it.
	 */
	public function test_supply_answers_for_the_validation_inside_a_verified_create_order_request(): void {
		$this->score_create_order( 'in-flight-session', FraudDecision::Allow );

		// PayPal rebuilt $_POST from its serialized form, so this call has no
		// session ID and no payment method to read.
		$this->assertSame( FraudDecision::Allow, $this->ask( 'shortcode_checkout', '', '' ) );
	}

	/**
	 * @testdox One create-order verification answers for only one form validation.
	 */
	public function test_supply_covers_only_one_validation_per_create_order_verification(): void {
		$this->score_create_order( 'in-flight-session', FraudDecision::Allow );

		$this->ask( 'shortcode_checkout', '', '' );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', '', '' ),
			'The marker is consumed on read: a second validation verifies for itself.'
		);
	}

	/**
	 * @testdox The in-request marker does not survive into another request.
	 */
	public function test_supply_does_not_answer_for_a_session_verified_in_an_earlier_request(): void {
		// A fresh instance is what the next request gets.
		$next_request = new PayPalCompat();
		$next_request->init( $this->session_verifier, $this->blocked_session_message );

		$this->score_create_order( 'in-flight-session', FraudDecision::Allow );

		$this->assertFalse(
			$next_request->supply_decision_for_paypal_express(
				false,
				'shortcode_checkout',
				array( 'payment_method' => '' ),
				''
			)
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Stand-down budget
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A verified session is answered for once, and the reuse after that is verified again.
	 *
	 * One is what a genuine order flow produces: ppc-create-order scores the ID and
	 * the Store API request that follows presents the same ID once. Past that we
	 * defer, so reuse is scored instead of served.
	 */
	public function test_supply_answers_for_a_verified_session_only_up_to_the_bound(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'reused-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'reused-session' ),
			'The one stand-down a genuine flow needs must be granted.'
		);

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'reused-session' ),
			'Reuse past the genuine shape must fall through to a real verify.'
		);
	}

	/**
	 * @testdox One verification cannot answer for a whole Store API batch.
	 *
	 * A batch request replays the same session ID across every sub-request. The
	 * budget is what stops one verification covering all of them: the first is
	 * answered, the rest are verified for real.
	 */
	public function test_supply_does_not_answer_for_a_replayed_batch(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'batched-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);

		$answered = 0;
		$deferred = 0;

		for ( $sub_request = 0; $sub_request < 25; $sub_request++ ) {
			if ( false === $this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'batched-session' ) ) {
				++$deferred;
			} else {
				++$answered;
			}
		}

		$this->assertSame( 1, $answered, 'Exactly one sub-request may be answered from the record.' );
		$this->assertSame( 24, $deferred, 'Every other sub-request must be verified for real.' );
	}

	/**
	 * @testdox Scoring the same session again does not refresh its budget.
	 *
	 * The budget belongs to the session and is spent once. A fresh budget comes
	 * with a fresh session's record, not from scoring the old one again.
	 */
	public function test_supply_does_not_refresh_the_budget_when_the_same_session_is_scored_again(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'reused-session',
				'stand_downs' => 1,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->score_create_order( 'reused-session', FraudDecision::Allow );

		// That scoring set the in-request marker, which would answer the ask
		// below on its own. Spend it first, so what is under test is the budget.
		$this->ask( 'shortcode_checkout', '', '' );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 1, $record['stand_downs'], 'The budget must not be topped up by scoring the session again.' );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'reused-session' ),
			'A spent budget stays spent.'
		);
	}

	/**
	 * @testdox A fresh session starts a fresh record with its own budget.
	 */
	public function test_supply_grants_a_fresh_session_its_own_budget(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'spent-session',
				'stand_downs' => 1,
				'decision'    => FraudDecision::Allow,
			)
		);

		$this->score_create_order( 'fresh-session', FraudDecision::Allow );

		$this->ask( 'shortcode_checkout', '', '' );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'fresh-session' )
		);
	}

	/**
	 * @testdox The record is keyed by the session ID the verification resolved, not the one presented.
	 */
	public function test_verify_keys_the_record_by_the_resolved_session_id(): void {
		$this->score_create_order( 'presented-session', FraudDecision::Allow, 'resolved-session' );

		// Spend the in-request marker; the asks below are later requests.
		$this->ask( 'shortcode_checkout', '', '' );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 'resolved-session', $record['session_id'] );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'presented-session' ),
			'The ID the request presented is not the one that was scored; it is verified for real.'
		);
		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'resolved-session' )
		);
	}

	/**
	 * @testdox Nothing is recorded when the call completed no verification.
	 */
	public function test_verify_records_nothing_when_no_verification_completed(): void {
		$this->score_create_order( 'presented-session', FraudDecision::Allow, '' );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Recorded decision
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A blocked create-order hands the block back, not an allow.
	 *
	 * This is WOOSUBS-1831. The blocked session used to reach a later request and
	 * be waved through, because "already handled" was expressed as "allowed".
	 */
	public function test_supply_answers_a_blocked_session_with_its_block(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertSame(
			FraudDecision::Block,
			$this->ask( 'shortcode_checkout', 'ppcp-gateway', 'blocked-session' ),
			'A session scored as blocked must not be answered with an allow.'
		);
	}

	/**
	 * @testdox The verified-session record survives a blocked create-order.
	 *
	 * The blocked attempt is the one whose record matters most: the request after
	 * it is answered from that record, and the block is what comes back.
	 */
	public function test_verify_records_the_verified_session_even_on_block(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertSame(
			array(
				'session_id'  => 'blocked-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Block,
				'order_id'    => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' ),
			'A blocked create-order must still record the session it scored.'
		);
	}

	/**
	 * @testdox A blocked create-order leaves no in-request marker behind.
	 */
	public function test_verify_leaves_no_in_request_marker_on_block(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', '', '' ),
			'A blocked create-order must not hand the rest of the request a free pass.'
		);
	}

	/**
	 * @testdox An allowed create-order hands its allow back within the attempt.
	 *
	 * The completion leg of a create-order-by-AJAX flow presents the same session
	 * ID in a later request. It is answered from the record — the allow that
	 * verification produced — rather than scored a second time, which Blackbox
	 * would score harder as a reused session.
	 */
	public function test_supply_answers_an_allowed_session_with_its_allow(): void {
		$this->score_create_order( 'clean-session', FraudDecision::Allow );

		$this->ask( 'shortcode_checkout', '', '' );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'clean-session' )
		);
	}

	/**
	 * @testdox A recorded allow never answers for another gateway.
	 *
	 * The regression that sank the first central design: a stored ppcp allow at
	 * one amount satisfied a cod checkout at another with no verification at all.
	 * The gateway gate sits above every record read, so a non-PayPal checkout
	 * presenting the recorded session ID is verified for real — even with
	 * PayPal's approved-order slot populated.
	 */
	public function test_supply_does_not_apply_a_recorded_allow_to_another_gateway(): void {
		$this->score_create_order( 'paypal-scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-SCORED' ) );

		// Spend the in-request marker: the cross-gateway request is a later one.
		$this->ask( 'shortcode_checkout', '', '' );

		// The scored order itself sits in the slot, so both the session-keyed
		// and the order-bound reads would answer; only the gateway gate stands
		// between them and this request.
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-SCORED' ) ) );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'cod', 'paypal-scored-session' ),
			'A recorded allow must never answer for a non-PayPal checkout.'
		);
	}

	/**
	 * @testdox A recorded block does not answer for another gateway either; the request verifies.
	 *
	 * Same gate, other verdict. A non-PayPal checkout presenting a blocked ID
	 * gets a real verification instead of the record — the record answers only
	 * requests of the gateway whose flow produced it.
	 */
	public function test_supply_does_not_apply_a_recorded_block_to_another_gateway(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'cod', 'blocked-session' ),
			'The record must not answer for a non-PayPal checkout, whatever it holds.'
		);
	}

	/**
	 * @testdox A block recorded for one session does not answer for another.
	 *
	 * Guards the read side independently of the write side. The record is keyed
	 * on the session ID that was scored; a block must not become a property of
	 * the shopper, which is the sticky-block behaviour deliberately removed in
	 * #73. The expectation changed with 0.1.6's order binding — deliberately,
	 * not as a regression: this setup used to be answered with an allow by the
	 * approved-order route; unbound, it now defers to a real verify, which
	 * still proves the block did not stick.
	 */
	public function test_supply_does_not_apply_a_block_recorded_for_another_session(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'a-different-blocked-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Block,
			)
		);
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-FOREIGN' ) ) );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'this-session' ),
			'Another session being blocked says nothing about this one; it verifies for real.'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Order binding
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox The order created by a verified create-order request is bound to its record.
	 */
	public function test_verify_binds_the_created_order_to_the_record(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->assertSame(
			array(
				'session_id'  => 'scored-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
				'order_id'    => 'PP-123',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' )
		);
	}

	/**
	 * @testdox The scored order's completion is answered with its decision, on a fresh session ID.
	 *
	 * The express flow: create-order scores and mints, Blackbox resets, the
	 * buyer approves, and the completion leg presents an ID that cannot match
	 * the record. The order in PayPal's slot is the identity that can.
	 */
	public function test_supply_answers_the_scored_orders_completion_with_its_decision(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		// Spend the in-request marker; the completion leg is a later request.
		$this->ask( 'shortcode_checkout', '', '' );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-session' )
		);
	}

	/**
	 * @testdox An approved order that is not the scored one is not answered for.
	 */
	public function test_supply_defers_when_the_approved_order_is_not_the_scored_one(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->ask( 'shortcode_checkout', '', '' );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-999' ) ) );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-session' ),
			'A foreign order in the slot must be verified for real.'
		);
	}

	/**
	 * @testdox An unbound record does not answer for an approved order.
	 *
	 * Records written before the binding existed lack the order_id key; they
	 * stay valid for the session-keyed reads but name no order, so the
	 * approved-order route defers.
	 */
	public function test_supply_defers_for_an_unbound_record_with_an_approved_order(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'scored-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
			)
		);
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-session' ) );
	}

	/**
	 * @testdox Two empty order IDs are not a match.
	 */
	public function test_supply_defers_when_neither_side_names_an_order(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'scored-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
				'order_id'    => '',
			)
		);

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-session' ),
			'No order on either side is not an identity match.'
		);
	}

	/**
	 * @testdox A slot order that cannot be read defers.
	 */
	public function test_supply_defers_when_the_slot_order_is_not_readable(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->ask( 'shortcode_checkout', '', '' );

		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-session' ) );
	}

	/**
	 * @testdox An order created without a verification in this request binds nothing.
	 *
	 * The shape of a server-side order creation — a subscription renewal, for
	 * instance: PayPal's order-created hook fires, but no create-order
	 * verification ran in the request, so there is nothing to bind to.
	 */
	public function test_bind_appends_nothing_without_a_verification_in_this_request(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'scored-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
				'order_id'    => '',
			)
		);

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A request that verified nothing must bind nothing.' );
	}

	/**
	 * @testdox A blocked create-order binds nothing.
	 */
	public function test_bind_appends_nothing_on_a_blocked_create_order(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( FraudDecision::Block, $record['decision'] );
		$this->assertSame( '', $record['order_id'], 'The blocked request died before an order existed; nothing may bind.' );
	}

	/**
	 * @testdox One verification binds only the one order its request creates.
	 */
	public function test_bind_covers_only_the_one_order_a_request_creates(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-1' ) );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-2' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 'PP-1', $record['order_id'], 'The binding state is consumed on read.' );
	}

	/**
	 * @testdox A record that is no longer this verification's is not bound.
	 */
	public function test_bind_ignores_a_record_for_another_session(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		// The record was replaced before the order was created.
		$replaced = array(
			'session_id'  => 'another-session',
			'stand_downs' => 0,
			'decision'    => FraudDecision::Allow,
			'order_id'    => '',
		);
		WC()->session->set( '_fraud_protection_paypal_verification', $replaced );

		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->assertSame(
			$replaced,
			WC()->session->get( '_fraud_protection_paypal_verification' ),
			'Another verification\'s record must not inherit this order.'
		);
	}

	/**
	 * @testdox Binding preserves the spent budget.
	 */
	public function test_bind_preserves_the_spent_budget(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'reused-session',
				'stand_downs' => 1,
				'decision'    => FraudDecision::Allow,
				'order_id'    => '',
			)
		);

		$this->score_create_order( 'reused-session', FraudDecision::Allow );
		$this->sut->bind_created_order_to_verification( new FakePayPalOrder( 'PP-9' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 1, $record['stand_downs'], 'Binding must not top the budget up.' );
		$this->assertSame( 'PP-9', $record['order_id'] );
	}

}

/**
 * Serializable stand-in for PayPal's order entity: the one method read from it.
 */
class FakePayPalOrder {

	/**
	 * @param string $order_id The PayPal order ID.
	 */
	public function __construct( private string $order_id ) {}

	/**
	 * The PayPal order ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->order_id;
	}
}
