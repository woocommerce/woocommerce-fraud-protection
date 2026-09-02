<?php
/**
 * PayPalDecisionReuseTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FakePayPalOrder;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalSubscriptionsStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\ThrowingPayPalOrder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalDecisionReuse;

/**
 * Tests for PayPal decision reuse.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalDecisionReuse
 */
class PayPalDecisionReuseTest extends FraudProtectionUnitTestCase {

	private PayPalDecisionReuse $decision_reuse;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			class_alias( PayPalSubscriptionsStub::class, 'WC_Subscriptions' );
		}
		$normalizer           = new SessionIdNormalizer();
		$this->decision_reuse = new PayPalDecisionReuse();
		$this->decision_reuse->init( $normalizer );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		$session = $this->get_original_woocommerce_session();
		if ( $session ) {
			$session->set( 'ppcp', null );
			$session->set( '_fraud_protection_paypal_verification', null );
			$session->set( '_fraud_protection_paypal_verified_session_id', null );
		}
		parent::tearDown();
	}

	/** Test supplied-decision filter registration. */
	public function test_register_hooks(): void {
		$this->decision_reuse->register();

		$this->assertSame(
			10,
			has_filter( 'woocommerce_fraud_protection_skip_session_verify', array( $this->decision_reuse, 'supply_decision_for_paypal_express' ) )
		);
	}

	/**
	 * @testdox Protected PayPal request sources preserve an incoming supplied decision.
	 *
	 * @dataProvider protected_paypal_request_source_provider
	 */
	public function test_protected_paypal_request_sources_preserve_supplied_decision( string $record_type, string $source, string $final_source ): void {
		$request  = $this->create_protected_paypal_request_record( $record_type );
		$supplied = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame( $supplied, $this->decision_reuse->supply_decision_for_paypal_express( $supplied, $source, $request, 'response-session' ) );
		$final = $this->decision_reuse->supply_decision_for_paypal_express( false, $final_source, $request, 'response-session' );
		$this->assertInstanceOf( SuppliedDecision::class, $final );
		$this->assertSame( FraudDecision::Allow, $final->decision );
		$this->assertFalse( $this->decision_reuse->supply_decision_for_paypal_express( false, $final_source, $request, 'response-session' ) );
	}

	/** @return array<string, array{string, string, string}> */
	public function protected_paypal_request_source_provider(): array {
		return array(
			'create order' => array( 'create', 'paypal_express_order_creation', 'blocks_checkout' ),
			'setup token'  => array( 'setup', 'paypal_setup_token_creation', 'blocks_checkout' ),
			'vault order'  => array( 'vault', 'paypal_vault_order_creation', 'subscriptions_change_payment' ),
		);
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
		$supplied_decision = $this->decision_reuse->supply_decision_for_paypal_express(
			false,
			$source,
			array( 'payment_method' => $payment_method ),
			$session_id
		);

		return $supplied_decision instanceof SuppliedDecision ? $supplied_decision->decision : false;
	}

	/**
	 * Assert that a deferral preserves the exact incoming decision object.
	 *
	 * @param string $source       Verification source.
	 * @param array  $request_data Request data.
	 * @param string $session_id   Submitted session ID.
	 */
	private function assert_incoming_decision_is_preserved( string $source, array $request_data, string $session_id ): void {
		$incoming = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$incoming,
			$this->decision_reuse->supply_decision_for_paypal_express( $incoming, $source, $request_data, $session_id )
		);
	}

	/**
	 * Store a verification result for the decision-reuse tests.
	 *
	 * @param string        $session_id          The session ID the request presents.
	 * @param FraudDecision $decision            What the verifier returns.
	 * @param ?string       $resolved_session_id The session ID the verifier resolves, when it differs.
	 */
	private function record_verification( string $session_id, FraudDecision $decision, ?string $resolved_session_id = null, string $origin = PayPalDecisionReuse::ORDER_CREATION_SOURCE, bool $can_store_result = true ): void {
		$this->decision_reuse->record_verification(
			$origin,
			$resolved_session_id ?? $session_id,
			$decision,
			$can_store_result
		);
	}

	/** Store a verification result and associate its PayPal order. */
	private function record_order( string $session_id, string $order_id = 'PP-123', ?string $resolved_session_id = null, string $origin = PayPalDecisionReuse::ORDER_CREATION_SOURCE ): void {
		$this->record_verification( $session_id, FraudDecision::Allow, $resolved_session_id, $origin );
		$this->associate_order( $resolved_session_id ?? $session_id, $order_id, $origin );
	}

	/** Associate an order with the current verification record. */
	private function associate_order( string $session_id = 'scored-session', string $order_id = 'PP-123', string $origin = PayPalDecisionReuse::ORDER_CREATION_SOURCE ): void {
		$this->decision_reuse->associate_created_order( new FakePayPalOrder( $order_id ), $session_id, $origin );
	}

	/**
	 * @testdox An approved order alone no longer answers for any gateway.
	 *
	 * Deliberate 0.1.6 behavior change, not a regression: this route used to
	 * answer whenever any order sat in PayPal's session slot, whatever it
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
	 * The query string is caller-controlled and must not be trusted as evidence
	 * that this plugin already verified the request.
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
	 * Records under the former key are intentionally orphaned when the key
	 * changes; they must never satisfy a later request.
	 */
	public function test_supply_ignores_a_record_under_the_retired_session_key(): void {
		WC()->session->set( '_fraud_protection_paypal_verified_session_id', 'test-session-abc' );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'test-session-abc' ) );
	}

	/**
	 * @testdox Two empty session IDs are not a match.
	 */
	public function test_supply_defers_when_both_session_ids_are_empty(): void {
		$this->set_verification_record( session_id: '', order_id: 'PP-123' );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', '' ) );
	}

	/**
	 * @testdox A session ID that does not match the recorded one is not answered for.
	 */
	public function test_supply_defers_when_session_id_does_not_match(): void {
		$this->set_verification_record( session_id: 'old-session', order_id: 'PP-123' );

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
	 * A matching session ID is not enough: the complete record shape and an
	 * actionable decision are required before stored state can be reused.
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

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'some-session-id'
		);
	}

	/**
	 * @testdox A non-PayPal gateway is never answered for, even with an approved order in session.
	 */
	public function test_supply_defers_for_non_paypal_gateway(): void {
		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'stripe', 'some-session-id' ) );
	}

	/**
	 * @testdox A non-string payment method defers and preserves the incoming value.
	 */
	public function test_supply_defers_for_non_string_payment_method(): void {
		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$supplied_decision,
			$this->decision_reuse->supply_decision_for_paypal_express(
				$supplied_decision,
				'blocks_checkout',
				array( 'payment_method' => array( 'ppcp-gateway' ) ),
				'some-session-id'
			)
		);
	}

	/**
	 * @testdox This class does not answer for its own verification source.
	 */
	public function test_supply_defers_for_own_source(): void {
		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'paypal_express_order_creation', 'ppcp-gateway', 'some-session-id' ) );
	}

	/**
	 * @testdox Create-order verification does not answer for an unidentified later request.
	 *
	 * A later request must identify the same PayPal attempt before it can reuse a
	 * stored result.
	 */
	public function test_supply_defers_for_an_unidentified_request_after_create_order_verification(): void {
		$this->record_verification( 'scored-session', FraudDecision::Allow );

		$this->assertFalse( $this->ask( 'shortcode_checkout', '', '' ) );
	}

	/**
	 * @testdox An unrelated saved Allow does not override an earlier Block.
	 *
	 * A result for another session cannot replace an actionable decision already
	 * supplied by an earlier filter consumer.
	 */
	public function test_supply_does_not_override_an_earlier_block_with_an_unrelated_saved_allow(): void {
		$this->record_verification( 'scored-session', FraudDecision::Allow );
		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$supplied_decision,
			$this->decision_reuse->supply_decision_for_paypal_express(
				$supplied_decision,
				'shortcode_checkout',
				array( 'payment_method' => 'ppcp-gateway' ),
				'different-session'
			)
		);
	}

	/**
	 * @testdox An earlier consumer's decision is passed through when this class defers.
	 *
	 * Filter arbitration must preserve an earlier consumer's result when this
	 * class has no matching record.
	 */
	public function test_supply_passes_an_earlier_decision_through_when_it_defers(): void {
		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$this->assertSame(
			$supplied_decision,
			$this->decision_reuse->supply_decision_for_paypal_express(
				$supplied_decision,
				'blocks_checkout',
				array( 'payment_method' => 'stripe' ),
				'some-session-id'
			)
		);
	}

	/**
	 * @testdox The record answers at this callback's priority, over an earlier consumer's decision.
	 *
	 * This callback may answer at its own priority; consumers that need the final
	 * decision can register later.
	 */
	public function test_supply_answers_from_its_record_over_an_earlier_decision(): void {
		$this->set_verification_record( session_id: 'some-session-id', order_id: 'PP-123' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$supplied_decision = new SuppliedDecision( FraudDecision::Block );

		$returned = $this->decision_reuse->supply_decision_for_paypal_express(
			$supplied_decision,
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'some-session-id'
		);

		$this->assertInstanceOf( SuppliedDecision::class, $returned );
		$this->assertSame( FraudDecision::Allow, $returned->decision );
	}

	/** @testdox A final record read failure preserves the incoming decision and retires stored state. */
	public function test_supply_read_failure_preserves_incoming_decision_and_retires(): void {
		$request = array(
			'payment_method' => 'ppcp-gateway',
			'payment_data'   => array( 'paypal_order_id' => 'PP-123' ),
		);
		$session = $this->createMock( \WC_Session::class );
		$session->method( 'get' )->willThrowException( new \RuntimeException( 'session read unavailable' ) );
		$session->expects( $this->once() )->method( 'set' )->with( '_fraud_protection_paypal_verification', null );
		WC()->session = $session;
		$incoming = new SuppliedDecision( FraudDecision::Block );

		$returned = $this->decision_reuse->supply_decision_for_paypal_express( $incoming, 'blocks_checkout', $request, 'response-session' );

		$this->assertSame( $incoming, $returned );
		$this->assertLogged(
			'warning',
			'Reading or consuming the PayPal request verification record failed',
			array(
				'event_source'      => 'blocks_checkout',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session read unavailable',
			)
		);
	}

	/** @testdox A final used-state write failure preserves the incoming decision and retires stored state. */
	public function test_supply_write_failure_preserves_incoming_decision_and_retires(): void {
		$request         = array(
			'payment_method' => 'ppcp-gateway',
			'payment_data'   => array( 'paypal_order_id' => 'PP-123' ),
		);
		$record          = array(
			'origin'     => PayPalDecisionReuse::ORDER_CREATION_SOURCE,
			'session_id' => 'response-session',
			'decision'   => FraudDecision::Allow,
			'used'       => false,
			'order_id'   => 'PP-123',
			'cart_hash'  => '',
		);
		$expected_record = $record;
		$expected_record['used'] = true;
		$session         = $this->createMock( \WC_Session::class );
		$write_count     = 0;
		$session->method( 'get' )->willReturn( $record );
		$session->expects( $this->exactly( 2 ) )->method( 'set' )->willReturnCallback(
			function ( string $key, $value ) use ( $expected_record, &$write_count ): void {
				$this->assertSame( '_fraud_protection_paypal_verification', $key );
				++$write_count;
				if ( 1 === $write_count ) {
					$this->assertSame( $expected_record, $value );
					throw new \RuntimeException( 'session write unavailable' );
				}

				$this->assertNull( $value );
			}
		);
		WC()->session = $session;
		$incoming = new SuppliedDecision( FraudDecision::Block );

		$returned = $this->decision_reuse->supply_decision_for_paypal_express( $incoming, 'blocks_checkout', $request, 'response-session' );

		$this->assertSame( $incoming, $returned );
		$this->assertLogged(
			'warning',
			'Reading or consuming the PayPal request verification record failed',
			array(
				'event_source'      => 'blocks_checkout',
				'session_id'        => 'response-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'session write unavailable',
			)
		);
	}

	/**
	 * @testdox A malformed value in the chain fails loudly instead of being silently ignored.
	 *
	 * The typed callback contract rejects values that are neither the default nor
	 * a supplied decision.
	 */
	public function test_supply_rejects_a_malformed_earlier_value_loudly(): void {
		$this->expectException( \TypeError::class );

		$this->decision_reuse->supply_decision_for_paypal_express(
			'allow',
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'some-session-id'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Shared supplied use
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox The record uses the response-backed session ID, not the submitted one.
	 */
	public function test_record_uses_the_response_backed_session_id(): void {
		$this->record_order( 'presented-session', resolved_session_id: 'resolved-session' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 'resolved-session', $record['session_id'] );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'resolved-session' )
		);

		$this->record_order( 'presented-session', resolved_session_id: 'resolved-session' );
		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'presented-session' ),
			'The ID the request presented is not the one that was scored; it is verified for real.'
		);
	}

	/**
	 * @testdox No record remains when verification produced no response-backed session ID.
	 */
	public function test_record_is_removed_without_a_response_backed_session_id(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'prior-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Block,
			)
		);

		$this->record_verification( 'presented-session', FraudDecision::Allow, '' );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox Exact-session replay normalizes a stored session ID written before the byte limit.
	 */
	public function test_exact_session_replay_normalizes_legacy_stored_session_id(): void {
		$normalized = str_repeat( 'a', 255 );
		$stored     = $normalized . 'b';

		$this->record_verification( $normalized, FraudDecision::Allow );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$record['session_id'] = $stored;
		$record['decision']   = FraudDecision::Block;
		$record['order_id']   = 'PP-123';
		WC()->session->set( '_fraud_protection_paypal_verification', $record );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertSame( FraudDecision::Block, $this->ask( 'blocks_checkout', 'ppcp-gateway', $normalized ) );
		$stored_record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertSame( $stored, $stored_record['session_id'] );
		$this->assertTrue( $stored_record['used'] );
	}

	/**
	 * @testdox Invalid stored session IDs do not match a submitted session ID.
	 *
	 * @dataProvider invalid_stored_session_id_provider
	 */
	public function test_invalid_stored_session_id_does_not_match_submitted_session( string $stored_session_id, string $submitted_session_id ): void {
		$this->record_order( 'scored-session' );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$record['session_id'] = $stored_session_id;
		WC()->session->set( '_fraud_protection_paypal_verification', $record );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$incoming = '' === $submitted_session_id ? new SuppliedDecision( FraudDecision::Block ) : false;
		$returned = $this->decision_reuse->supply_decision_for_paypal_express(
			$incoming,
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			$submitted_session_id
		);
		if ( '' === $submitted_session_id ) {
			$this->assertSame( $incoming, $returned );
		} else {
			$this->assertFalse( $returned );
		}
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{string, string}> */
	public function invalid_stored_session_id_provider(): array {
		return array(
			'single dot'           => array( '.', 'wcfp-invalid-characters' ),
			'double dot'           => array( '..', 'wcfp-invalid-characters' ),
			'empty submitted value' => array( '.', '' ),
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Recorded decision
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A blocked create-order without an associated order does not satisfy a final request.
	 *
	 * A blocked create-order has no order to bind, so its record cannot authorize
	 * a later completion request.
	 */
	public function test_blocked_session_without_an_order_defers(): void {
		$this->record_verification( 'blocked-session', FraudDecision::Block );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'ppcp-gateway', 'blocked-session' ),
			'A blocked PayPal request creates no order to associate with a final request.'
		);
	}

	/**
	 * @testdox The verified-session record survives a blocked create-order.
	 *
	 * The response-backed decision is recorded before Block enforcement. An
	 * order-producing Block cannot be reused because no order is associated.
	 */
	public function test_record_verification_stores_the_response_backed_block(): void {
		$this->record_verification( 'blocked-session', FraudDecision::Block );

		$this->assertSame(
			array(
				'origin'     => 'paypal_express_order_creation',
				'session_id' => 'blocked-session',
				'decision'   => FraudDecision::Block,
				'used'       => false,
				'order_id'   => '',
				'cart_hash'  => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' ),
			'A blocked create-order must still record the session it scored.'
		);
	}

	/**
	 * @testdox A recorded allow never answers for another gateway.
	 *
	 * Stored PayPal state is scoped to PayPal requests; another gateway must
	 * continue through normal verification.
	 */
	public function test_supply_does_not_apply_a_recorded_allow_to_another_gateway(): void {
		$this->record_order( 'paypal-scored-session', 'PP-SCORED' );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-SCORED' ) ) );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'cod', 'paypal-scored-session' ),
			'A recorded allow must never answer for a non-PayPal checkout.'
		);
	}

	/**
	 * @testdox A recorded block does not answer for another gateway either; the request verifies.
	 *
	 * The gateway check applies regardless of the recorded decision.
	 */
	public function test_supply_does_not_apply_a_recorded_block_to_another_gateway(): void {
		$this->record_verification( 'blocked-session', FraudDecision::Block );

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
	 * #73. The expectation changed with 0.1.6's order association — deliberately,
	 * not as a regression: this setup used to be answered with an allow by the
	 * approved-order route; without an associated order, it now defers to a real verify, which
	 * still proves the block did not stick.
	 */
	public function test_supply_does_not_apply_a_block_recorded_for_another_session(): void {
		$this->set_verification_record( session_id: 'a-different-blocked-session', decision: FraudDecision::Block, order_id: 'PP-FOREIGN' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-FOREIGN' ) ) );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'this-session' ),
			'Another session being blocked says nothing about this one; it verifies for real.'
		);
	}

	/*
	|--------------------------------------------------------------------------
	| Order association
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A create-order record can be associated with its created order.
	 */
	public function test_record_can_be_associated_with_the_created_order(): void {
		$this->record_order( 'scored-session' );

		$this->assertSame(
			array(
				'origin'     => 'paypal_express_order_creation',
				'session_id' => 'scored-session',
				'decision'   => FraudDecision::Allow,
				'used'       => false,
				'order_id'   => 'PP-123',
				'cart_hash'  => '',
			),
			WC()->session->get( '_fraud_protection_paypal_verification' )
		);
	}

	/**
	 * @testdox An associated-order id() that throws leaves the record without an associated order and is logged.
	 *
	 * PayPal order objects are foreign objects. An ID read failure must leave the
	 * record safe and allow the later request to verify normally.
	 */
	public function test_association_fails_open_when_the_order_id_throws(): void {
		$this->record_verification( 'scored-session', FraudDecision::Allow );

		$this->decision_reuse->associate_created_order( new ThrowingPayPalOrder(), 'scored-session', PayPalDecisionReuse::ORDER_CREATION_SOURCE );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A throwing order must leave the record without an associated order.' );
		$this->assertLogged(
			'warning',
			'Associating the created PayPal order threw',
			array(
				'session_id'        => 'scored-session',
				'exception_class'   => 'RuntimeException',
				'exception_message' => 'id() is unavailable',
			)
		);
	}

	/**
	 * @testdox The scored order's completion is answered with its decision.
	 */
	public function test_supply_answers_the_scored_orders_completion_with_its_decision(): void {
		$this->record_order( 'scored-session' );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$supplied_decision = $this->decision_reuse->supply_decision_for_paypal_express(
			false,
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'scored-session'
		);

		$this->assertInstanceOf( SuppliedDecision::class, $supplied_decision );
		$this->assertSame( FraudDecision::Allow, $supplied_decision->decision );
		$this->assertSame( 'scored-session', $supplied_decision->session_id_for_order );
	}

	/**
	 * @testdox An associated approved order does not replay with an empty session ID.
	 */
	public function test_supply_does_not_answer_associated_order_with_empty_session_id(): void {
		$this->record_order( 'scored-session' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', '' ) );
	}

	/**
	 * @testdox An approved order that is not the scored one is not answered for.
	 */
	public function test_supply_defers_when_the_approved_order_is_not_the_scored_one(): void {
		$this->record_order( 'scored-session' );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-999' ) ) );

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'scored-session'
		);
	}

	/** @testdox An explicit final order ID takes precedence over the WC PayPal session order. */
	public function test_explicit_final_order_mismatch_defers_and_retires(): void {
		$request = $this->create_protected_paypal_request_record( 'create' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );
		$request['payment_data']['paypal_order_id'] = 'PP-OTHER';

		$this->assert_incoming_decision_is_preserved( 'blocks_checkout', $request, 'response-session' );
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox A record without an associated order does not answer for an approved order.
	 *
	 */
	public function test_supply_defers_for_a_record_without_an_associated_order(): void {
		$this->set_verification_record();
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
	}

	/**
	 * @testdox Two empty order IDs are not a match.
	 */
	public function test_supply_defers_when_neither_side_names_an_order(): void {
		$this->set_verification_record();

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'scored-session'
		);
	}

	/**
	 * @testdox A slot order that cannot be read defers.
	 */
	public function test_supply_defers_when_the_slot_order_is_not_readable(): void {
		$this->record_order( 'scored-session' );

		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
	}

	/**
	 * @testdox An order created without a verification in this request binds nothing.
	 *
	 * Association is valid only when the current request produced the matching
	 * verification record.
	 */
	public function test_association_adds_nothing_without_a_verification_in_this_request(): void {
		$this->set_verification_record();

		$this->associate_order( '', 'PP-123' );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A request that verified nothing must associate no order.' );
	}

	/**
	 * @testdox A blocked create-order associates no order.
	 */
	public function test_association_adds_nothing_on_a_blocked_create_order(): void {
		$this->record_verification( 'blocked-session', FraudDecision::Block );

		$this->associate_order( '', 'PP-123' );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( FraudDecision::Block, $record['decision'] );
		$this->assertSame( '', $record['order_id'], 'The blocked request died before an order existed; nothing may be associated.' );
	}

	/**
	 * @testdox A record that is no longer this verification's is not associated.
	 */
	public function test_association_ignores_a_record_for_another_session(): void {
		$this->record_verification( 'scored-session', FraudDecision::Allow );

		$replaced = array(
			'origin'     => 'paypal_express_order_creation',
			'session_id' => 'another-session',
			'decision'   => FraudDecision::Allow,
			'used'       => false,
			'order_id'   => '',
			'cart_hash'  => '',
		);
		WC()->session->set( '_fraud_protection_paypal_verification', $replaced );

		$this->associate_order();

		$this->assertSame(
			$replaced,
			WC()->session->get( '_fraud_protection_paypal_verification' ),
			'Another verification\'s record must not inherit this order.'
		);
	}

	/**
	 * @testdox A create-order association does not associate an order with a vault-order record for the same session.
	 */
	public function test_association_ignores_a_record_from_another_origin(): void {
		$this->record_verification( 'scored-session', FraudDecision::Allow );
		$this->set_verification_record( origin: 'paypal_vault_order_creation' );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->associate_order();

		$this->assertSame( $record, WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox An associated record's decision is what the associated route replays, whatever it is.
	 *
	 */
	public function test_supply_answers_an_associated_block_with_its_block(): void {
		$this->set_verification_record( order_id: 'PP-BOUND', decision: FraudDecision::Block );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-BOUND' ) ) );

		$this->assertSame(
			FraudDecision::Block,
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' )
		);
	}

	/**
	 * @testdox Recording again replaces the prior use without carrying an associated order.
	 */
	public function test_recording_again_starts_without_an_associated_order(): void {
		$this->record_order( 'scored-session', 'PP-1' );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-1' ) ) );
		$this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-spend' );

		$this->record_verification( 'scored-session', FraudDecision::Allow );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A superseded scoring\'s order must not carry over.' );
		$this->assertFalse( $record['used'], 'A fresh verification must start unused.' );
	}

	/**
	 * @testdox Order IDs match as strings, never numerically.
	 */
	public function test_supply_matches_order_ids_as_strings_not_numbers(): void {
		$this->set_verification_record( order_id: '100' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( '1e2' ) ) );

		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ),
			'"1e2" is not "100"; a numeric comparison would say it is.'
		);
	}

	/**
	 * @testdox A non-string order_id reads as unassociated, never as a castable value.
	 */
	public function test_supply_treats_a_non_string_order_id_as_unassociated(): void {
		$this->set_verification_record( order_id: 100 );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( '100' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
	}

	/**
	 * @testdox A new verification replaces the used record.
	 */
	public function test_new_verification_replaces_the_used_record(): void {
		$this->record_order( 'scored-session', 'PP-1' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-1' ) ) );

		$this->assertSame( FraudDecision::Allow, $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );

		$this->record_order( 'scored-session', 'PP-2' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-2' ) ) );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ),
			'The new unused record must answer once.'
		);
	}

	/**
	 * @testdox A record in the retired shape is not reused.
	 */
	public function test_supply_does_not_reuse_the_retired_record_shape(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'scored-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Allow,
				'order_id'    => 'PP-123',
			)
		);
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox Each record has one shared use across its allowed final sources.
	 *
	 * @dataProvider final_order_source_provider
	 */
	public function test_records_supply_once_across_allowed_sources( string $origin, string $source, string $second_source ): void {
		$request = $this->create_protected_paypal_request_record( $origin );
		$first = $this->decision_reuse->supply_decision_for_paypal_express( false, $source, $request, 'response-session' );

		$this->assertInstanceOf( SuppliedDecision::class, $first );
		$this->assertSame( 'response-session', $first->session_id_for_order );
		$this->assert_incoming_decision_is_preserved( $second_source, $request, 'response-session' );
	}

	/** @return array<string, array{string, string, string}> */
	public function final_order_source_provider(): array {
		return array(
			'create Classic'       => array( 'create', 'shortcode_checkout', 'blocks_checkout' ),
			'create Blocks'        => array( 'create', 'blocks_checkout', 'shortcode_checkout' ),
			'create pay for order' => array( 'create', 'pay_for_order', 'blocks_checkout' ),
			'vault Classic'        => array( 'vault', 'shortcode_checkout', 'blocks_checkout' ),
			'vault Blocks'         => array( 'vault', 'blocks_checkout', 'shortcode_checkout' ),
			'vault pay for order'  => array( 'vault', 'pay_for_order', 'subscriptions_change_payment' ),
			'vault subscription'   => array( 'vault', 'subscriptions_change_payment', 'pay_for_order' ),
			'setup Classic'        => array( 'setup', 'shortcode_checkout', 'blocks_checkout' ),
			'setup Blocks'         => array( 'setup', 'blocks_checkout', 'shortcode_checkout' ),
		);
	}

	/**
	 * @testdox $origin records do not supply to unsupported $source requests.
	 *
	 * @dataProvider unsupported_final_source_provider
	 */
	public function test_records_reject_unsupported_final_sources( string $origin, string $source ): void {
		$request = $this->create_protected_paypal_request_record( $origin );

		$this->assert_incoming_decision_is_preserved( $source, $request, 'response-session' );
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{string, string}> */
	public function unsupported_final_source_provider(): array {
		return array(
			'setup pay for order'       => array( 'setup', 'pay_for_order' ),
			'setup change payment'      => array( 'setup', 'subscriptions_change_payment' ),
			'create change payment'     => array( 'create', 'subscriptions_change_payment' ),
			'vault add payment method'  => array( 'vault', 'add_payment_method' ),
		);
	}

	/**
	 * @testdox Setup records reject non-checkout sources.
	 *
	 * @dataProvider disallowed_setup_source_provider
	 */
	public function test_setup_record_requires_current_eligible_cart( string $disallowed_source ): void {
		$this->set_setup_cart( 'cart-hash' );
		$this->record_setup_verification();
		$request = array( 'payment_method' => 'ppcp-gateway' );

		$this->assert_incoming_decision_is_preserved( $disallowed_source, $request, 'response-session' );
	}

	/** @return array<string, array{string}> */
	public function disallowed_setup_source_provider(): array {
		return array(
			'add payment method'            => array( 'add_payment_method' ),
			'subscriptions change payment' => array( 'subscriptions_change_payment' ),
		);
	}

	/**
	 * @testdox Setup records require each material eligibility fact at final use.
	 *
	 * @dataProvider setup_eligibility_provider
	 *
	 * @param mixed $plan_metadata PayPal plan metadata.
	 */
	public function test_setup_record_rechecks_material_eligibility( string $total, bool $empty, bool $needs_payment, $plan_metadata, string $cart_hash, bool $can_store ): void {
		unset( $can_store );
		$this->set_setup_cart( 'cart-hash' );
		$this->record_setup_verification();

		$this->set_setup_cart( $cart_hash, $this->setup_cart_items( $plan_metadata ), $needs_payment, $total, $empty );

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'response-session'
		);
	}

	/** @return array<string, array{string, bool, bool, mixed, string, bool}> */
	public function setup_eligibility_provider(): array {
		return array(
			'positive total'              => array( '1', false, true, null, 'cart-hash', false ),
			'empty cart'                  => array( '0', true, true, null, 'cart-hash', false ),
			'payment not needed'          => array( '0', false, false, null, 'cart-hash', false ),
			'scalar PayPal-managed plan' => array( '0', false, true, 'plan-id', 'cart-hash', false ),
			'array PayPal-managed plan'  => array( '0', false, true, array( 'plan-id' ), 'cart-hash', false ),
			'empty cart hash'             => array( '0', false, true, null, '', false ),
			'changed cart hash'           => array( '0', false, true, null, 'changed-hash', true ),
		);
	}

	/**
	 * @testdox Setup cart eligibility controls record storage.
	 *
	 * @dataProvider setup_eligibility_provider
	 *
	 * @param mixed $plan_metadata PayPal plan metadata.
	 */
	public function test_setup_cart_eligibility_controls_record_storage( string $total, bool $empty, bool $needs_payment, $plan_metadata, string $cart_hash, bool $can_store ): void {
		$this->set_setup_cart( $cart_hash, $this->setup_cart_items( $plan_metadata ), $needs_payment, $total, $empty );
		$this->record_setup_verification();

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		if ( $can_store ) {
			$this->assertIsArray( $record );
			$this->assertSame( $cart_hash, $record['cart_hash'] );
		} else {
			$this->assertNull( $record );
		}
	}

	/** @testdox Setup record storage initializes the cart before checking its payment requirement. */
	public function test_setup_record_initializes_cart_totals(): void {
		$totals_calculated = false;
		$cart              = $this->getMockBuilder( \WC_Cart::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_empty', 'get_total', 'needs_payment', 'get_cart', 'get_cart_hash', 'calculate_totals' ) )
			->getMock();
		$cart->method( 'is_empty' )->willReturn( false );
		$cart->method( 'get_total' )->willReturn( '0' );
		$cart->method( 'needs_payment' )->willReturnCallback(
			static function () use ( &$totals_calculated ): bool {
				return $totals_calculated;
			}
		);
		$cart->method( 'get_cart' )->willReturn( array() );
		$cart->method( 'get_cart_hash' )->willReturn( 'cart-hash' );
		$cart->expects( $this->once() )->method( 'calculate_totals' )->willReturnCallback(
			static function () use ( &$totals_calculated ): void {
				$totals_calculated = true;
			}
		);
		WC()->cart = $cart;

		$this->record_setup_verification();

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$this->assertSame( 'cart-hash', $record['cart_hash'] );
	}

	/** Create a reusable record for a final-request test. */
	private function create_protected_paypal_request_record( string $record_type ): array {
		if ( 'create' === $record_type ) {
			$this->record_order( 'browser-session', resolved_session_id: 'response-session' );
		} else {
			if ( 'setup' === $record_type ) {
				$this->set_setup_cart( 'cart-hash' );
				$this->record_verification( 'browser-session', FraudDecision::Allow, 'response-session', PayPalDecisionReuse::SETUP_TOKEN_CREATION_SOURCE );
			} else {
				$this->record_order( 'browser-session', 'PP-123', 'response-session', PayPalDecisionReuse::VAULT_ORDER_CREATION_SOURCE );
			}
		}

		$request = array( 'payment_method' => 'ppcp-gateway' );
		if ( 'setup' !== $record_type ) {
			$request['payment_data'] = array( 'paypal_order_id' => 'PP-123' );
		}

		return $request;
	}

	/**
	 * Store a valid PayPal verification record for a final-request test.
	 *
	 * @param string        $origin     Verification origin.
	 * @param string        $session_id Response-backed session ID.
	 * @param FraudDecision $decision   Recorded decision.
	 * @param bool          $used       Whether the shared use is spent.
	 * @param mixed         $order_id   Associated PayPal order ID.
	 * @param string        $cart_hash  Associated setup cart hash.
	 */
	private function set_verification_record(
		string $origin = 'paypal_express_order_creation',
		string $session_id = 'scored-session',
		FraudDecision $decision = FraudDecision::Allow,
		bool $used = false,
		$order_id = '',
		string $cart_hash = ''
	): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'origin'     => $origin,
				'session_id' => $session_id,
				'decision'   => $decision,
				'used'       => $used,
				'order_id'   => $order_id,
				'cart_hash'  => $cart_hash,
			)
		);
	}

	/** Store a setup-token verification with the current cart eligibility. */
	private function record_setup_verification(): void {
		$this->record_verification( 'browser-session', FraudDecision::Allow, 'response-session', PayPalDecisionReuse::SETUP_TOKEN_CREATION_SOURCE );
	}

	/**
	 * Build cart items with controlled PayPal plan metadata.
	 *
	 * @param mixed $plan_metadata PayPal plan metadata.
	 * @return array<int, array{data: \WC_Product}>
	 */
	private function setup_cart_items( $plan_metadata ): array {
		if ( null === $plan_metadata ) {
			return array();
		}

		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->with( 'ppcp_subscription_plan' )->willReturn( $plan_metadata );

		return array( array( 'data' => $product ) );
	}

	/**
	 * Set a controlled eligible setup cart.
	 *
	 * @param string $hash          Cart hash.
	 * @param array  $items         Cart items.
	 * @param bool   $needs_payment Whether the cart needs a payment method.
	 * @param mixed  $total         Cart total.
	 * @param bool   $empty         Whether the cart is empty.
	 */
	private function set_setup_cart( string $hash, array $items = array(), bool $needs_payment = true, $total = '0', bool $empty = false ): void {
		$cart = $this->getMockBuilder( \WC_Cart::class )
			->disableOriginalConstructor()
			->onlyMethods( array( 'is_empty', 'get_total', 'needs_payment', 'get_cart', 'get_cart_hash', 'calculate_totals' ) )
			->getMock();
		$cart->method( 'is_empty' )->willReturn( $empty );
		$cart->method( 'get_total' )->willReturn( $total );
		$cart->method( 'needs_payment' )->willReturn( $needs_payment );
		$cart->method( 'get_cart' )->willReturn( $items );
		$cart->method( 'get_cart_hash' )->willReturn( $hash );
		WC()->cart = $cart;
	}

}
