<?php
/**
 * PayPalDecisionReuseTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Compat;

require_once dirname( __DIR__, 4 ) . '/Support/PayPalPPCPStubs.php';

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FakePayPalOrder;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalContainerStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalJsonResponseCapture;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalPPCPStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalRequestDataStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\PayPalSubscriptionsStub;
use Automattic\WooCommerce\FraudProtection\Tests\Support\ThrowingPayPalOrder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalDecisionReuse;

/**
 * Tests for PayPal decision reuse.
 *
 * @covers \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalDecisionReuse
 */
class PayPalDecisionReuseTest extends FraudProtectionUnitTestCase {

	private PayPalCompat $paypal_compat;

	private PayPalDecisionReuse $decision_reuse;

	/** @var SessionVerifier&\PHPUnit\Framework\MockObject\MockObject */
	private $session_verifier;

	/** @var mixed */
	private $original_cart;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		if ( ! class_exists( '\\WooCommerce\\PayPalCommerce\\PPCP', false ) ) {
			class_alias( PayPalPPCPStub::class, 'WooCommerce\\PayPalCommerce\\PPCP' );
		}
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			class_alias( PayPalSubscriptionsStub::class, 'WC_Subscriptions' );
		}
		PayPalRequestDataStub::$data  = array();
		PayPalRequestDataStub::$error = null;
		PayPalContainerStub::reset();
		PayPalPPCPStub::set_error( null );
		PayPalJsonResponseCapture::reset();
		$this->original_cart = WC()->cart;

		$this->session_verifier = $this->createMock( SessionVerifier::class );
		$normalizer             = new SessionIdNormalizer();
		$blocked_message        = $this->createMock( BlockedSessionMessage::class );
		$blocked_message->method( 'get_plaintext' )->willReturn( 'We are unable to process this request online.' );
		$this->decision_reuse = new PayPalDecisionReuse();
		$this->decision_reuse->init( $normalizer );
		$this->paypal_compat = new PayPalCompat();
		$this->paypal_compat->init( $this->session_verifier, $blocked_message, $normalizer, $this->decision_reuse );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'wp_doing_ajax' );
		remove_all_filters( 'wp_die_ajax_handler' );
		remove_all_filters( 'woocommerce_fraud_protection_skip_session_verify' );
		remove_all_filters( 'ppcp_request_args' );
		remove_all_actions( 'woocommerce_paypal_payments_create_order_request_started' );
		remove_all_actions( 'woocommerce_paypal_payments_paypal_order_created' );
		PayPalContainerStub::reset();
		PayPalPPCPStub::set_error( null );
		PayPalJsonResponseCapture::reset();
		WC()->cart = $this->original_cart;
		if ( WC()->session ) {
			WC()->session->set( 'ppcp', null );
			WC()->session->set( '_fraud_protection_paypal_verification', null );
			WC()->session->set( '_fraud_protection_paypal_verified_session_id', null );
		}
		unset( $_GET['wc-ajax'] );

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
			$this->paypal_compat->verify_and_block_create_order(
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
			$this->paypal_compat->verify_and_block_create_order(
				array( SessionVerifier::SESSION_ID_FIELD => $session_id )
			);
			$this->fail( 'Expected the block response to terminate the request.' );
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
	}

	/** Score a create-order request and associate its PayPal order. */
	private function score_and_associate_order( string $session_id, string $order_id = 'PP-123', ?string $resolved_session_id = null ): void {
		$this->score_create_order( $session_id, FraudDecision::Allow, $resolved_session_id );
		$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( $order_id ) );
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
	 * Only the shape update_verification_record() writes counts as a
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
	 */
	public function test_supply_defers_for_an_unidentified_request_after_create_order_verification(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->assertFalse( $this->ask( 'shortcode_checkout', '', '' ) );
	}

	/**
	 * @testdox An unrelated saved Allow does not override an earlier Block.
	 */
	public function test_supply_does_not_override_an_earlier_block_with_an_unrelated_saved_allow(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
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
	 * Every deferral path returns the value received, so a decision put in the
	 * chain by an earlier consumer survives a request this class has nothing
	 * to say about.
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
	 * Standard filter arbitration: this callback answers from its record at its
	 * own priority, whatever an earlier consumer returned. A consumer that wants
	 * the last word registers with a later priority.
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
		$request          = $this->create_protected_paypal_request_record( 'create' );
		$original_session = WC()->session;
		$session          = new class( $original_session ) {
			/** @var mixed */
			private $session;

			public bool $retired = false;

			public function __construct( $session ) { // phpcs:ignore
				$this->session = $session;
			}

			public function get( $key, $default = null ) { // phpcs:ignore
				throw new \RuntimeException( 'session read unavailable' );
			}

			public function set( $key, $value = null ): void { // phpcs:ignore
				$this->retired = null === $value;
				$this->session->set( $key, $value );
			}
		};
		WC()->session     = $session;
		$incoming         = new SuppliedDecision( FraudDecision::Block );
		$returned         = null;

		try {
			$returned = $this->decision_reuse->supply_decision_for_paypal_express( $incoming, 'blocks_checkout', $request, 'response-session' );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( $incoming, $returned );
		$this->assertTrue( $session->retired );
		$this->assertNull( $original_session->get( '_fraud_protection_paypal_verification' ) );
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
		$request          = $this->create_protected_paypal_request_record( 'create' );
		$original_session = WC()->session;
		$session          = new class( $original_session ) {
			/** @var mixed */
			private $session;

			private bool $fail_next_write = true;

			public bool $retired = false;

			public function __construct( $session ) { // phpcs:ignore
				$this->session = $session;
			}

			public function get( $key, $default = null ) { // phpcs:ignore
				return $this->session->get( $key, $default );
			}

			public function set( $key, $value = null ): void { // phpcs:ignore
				if ( $this->fail_next_write ) {
					$this->fail_next_write = false;
					throw new \RuntimeException( 'session write unavailable' );
				}

				$this->retired = null === $value;
				$this->session->set( $key, $value );
			}
		};
		WC()->session     = $session;
		$incoming         = new SuppliedDecision( FraudDecision::Block );
		$returned         = null;

		try {
			$returned = $this->decision_reuse->supply_decision_for_paypal_express( $incoming, 'blocks_checkout', $request, 'response-session' );
		} finally {
			WC()->session = $original_session;
		}

		$this->assertSame( $incoming, $returned );
		$this->assertTrue( $session->retired );
		$this->assertNull( $original_session->get( '_fraud_protection_paypal_verification' ) );
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
	 * The declared parameter type is the warning: an earlier consumer returning
	 * something that is neither a SuppliedDecision nor the default raises a
	 * TypeError, which SessionVerifier logs as a warning and answers with a
	 * real verify.
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
	 * @testdox The record is keyed by the session ID the verification resolved, not the one presented.
	 */
	public function test_verify_keys_the_record_by_the_resolved_session_id(): void {
		$this->score_and_associate_order( 'presented-session', resolved_session_id: 'resolved-session' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 'resolved-session', $record['session_id'] );

		$this->assertSame(
			FraudDecision::Allow,
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'resolved-session' )
		);

		$this->score_and_associate_order( 'presented-session', resolved_session_id: 'resolved-session' );
		$this->assertFalse(
			$this->ask( 'blocks_checkout', 'ppcp-credit-card-gateway', 'presented-session' ),
			'The ID the request presented is not the one that was scored; it is verified for real.'
		);
	}

	/**
	 * @testdox Nothing is recorded when the call completed no verification.
	 */
	public function test_verify_records_nothing_when_no_verification_completed(): void {
		WC()->session->set(
			'_fraud_protection_paypal_verification',
			array(
				'session_id'  => 'prior-session',
				'stand_downs' => 0,
				'decision'    => FraudDecision::Block,
			)
		);

		$this->score_create_order( 'presented-session', FraudDecision::Allow, '' );

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox Exact-session replay normalizes a stored session ID written before the byte limit.
	 */
	public function test_exact_session_replay_normalizes_legacy_stored_session_id(): void {
		$normalized = str_repeat( 'a', 255 );
		$stored     = $normalized . 'b';

		$this->score_create_order( $normalized, FraudDecision::Allow );
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
	 * @testdox Invalid stored session IDs do not match a normalized submitted session ID.
	 *
	 * @dataProvider invalid_stored_session_id_provider
	 *
	 * @param string $stored_session_id Stored session ID.
	 */
	public function test_invalid_stored_session_id_does_not_match_submitted_session( string $stored_session_id ): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$record['session_id'] = $stored_session_id;
		$record['decision']   = FraudDecision::Block;
		WC()->session->set( '_fraud_protection_paypal_verification', $record );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'wcfp-invalid-characters' ) );
	}

	/**
	 * Invalid stored session IDs.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_stored_session_id_provider(): array {
		return array(
			'single dot' => array( '.' ),
			'double dot' => array( '..' ),
		);
	}

	/** @testdox An invalid stored session ID does not match an empty submitted session ID. */
	public function test_invalid_stored_session_id_does_not_match_empty_submitted_session(): void {
		$this->score_and_associate_order( 'scored-session' );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$record['session_id'] = '.';
		WC()->session->set( '_fraud_protection_paypal_verification', $record );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			''
		);
		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/*
	|--------------------------------------------------------------------------
	| Recorded decision
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox A blocked create-order without an associated order does not satisfy a final request.
	 *
	 * This is WOOSUBS-1831. The blocked session used to reach a later request and
	 * be waved through, because "already handled" was expressed as "allowed".
	 */
	public function test_supply_answers_a_blocked_session_with_its_block(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->assertFalse(
			$this->ask( 'shortcode_checkout', 'ppcp-gateway', 'blocked-session' ),
			'A blocked PayPal request creates no order to associate with a final request.'
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
	 * @testdox An allowed create-order hands its allow back within the attempt.
	 *
	 * The completion leg of a create-order-by-AJAX flow presents the same session
	 * ID in a later request. It is answered from the record — the allow that
	 * verification produced — rather than scored a second time, which Blackbox
	 * would score harder as a reused session.
	 */
	public function test_supply_answers_an_allowed_session_with_its_allow(): void {
		$this->score_and_associate_order( 'clean-session' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

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
		$this->score_and_associate_order( 'paypal-scored-session', 'PP-SCORED' );

		// The scored order itself sits in the slot, so both the session-keyed
		// and the associated-order reads would answer; only the gateway gate stands
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
	 * @testdox The order created by a verified create-order request is associated with its record.
	 */
	public function test_verify_associates_the_created_order_with_the_record(): void {
		$this->score_and_associate_order( 'scored-session' );

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
	 * The order is foreign code's object; a throw reading its ID must not escape
	 * into ppcp's create-order request, which minted the order already and would
	 * otherwise fail the shopper's checkout. Fail open: the record keeps its
	 * empty order_id, so a later completion leg verifies for real.
	 */
	public function test_association_fails_open_when_the_order_id_throws(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		$this->paypal_compat->associate_created_order_with_verification( new ThrowingPayPalOrder() );

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
		$this->score_and_associate_order( 'scored-session' );

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
		$this->score_and_associate_order( 'scored-session' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-123' ) ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', '' ) );
	}

	/**
	 * @testdox An approved order that is not the scored one is not answered for.
	 */
	public function test_supply_defers_when_the_approved_order_is_not_the_scored_one(): void {
		$this->score_and_associate_order( 'scored-session' );

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
	 * The record is current and valid, but names no order, so the approved-order
	 * route defers.
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
		$this->score_and_associate_order( 'scored-session' );

		WC()->session->set( 'ppcp', array( 'order' => new \stdClass() ) );

		$this->assertFalse( $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );
	}

	/**
	 * @testdox An order created without a verification in this request binds nothing.
	 *
	 * The shape of a server-side order creation — a subscription renewal, for
	 * instance: PayPal's order-created hook fires, but no create-order
	 * verification ran in the request, so there is no verification to associate.
	 */
	public function test_association_adds_nothing_without_a_verification_in_this_request(): void {
		$this->set_verification_record();

		$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( 'PP-123' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( '', $record['order_id'], 'A request that verified nothing must associate no order.' );
	}

	/**
	 * @testdox A blocked create-order associates no order.
	 */
	public function test_association_adds_nothing_on_a_blocked_create_order(): void {
		$this->score_create_order( 'blocked-session', FraudDecision::Block );

		$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( 'PP-123' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( FraudDecision::Block, $record['decision'] );
		$this->assertSame( '', $record['order_id'], 'The blocked request died before an order existed; nothing may be associated.' );
	}

	/**
	 * @testdox One verification associates only the one order its request creates.
	 */
	public function test_association_covers_only_the_one_order_a_request_creates(): void {
		$this->score_and_associate_order( 'scored-session', 'PP-1' );
		$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( 'PP-2' ) );

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->assertIsArray( $record );
		$this->assertSame( 'PP-1', $record['order_id'], 'The association state is consumed on read.' );
	}

	/**
	 * @testdox A record that is no longer this verification's is not associated.
	 */
	public function test_association_ignores_a_record_for_another_session(): void {
		$this->score_create_order( 'scored-session', FraudDecision::Allow );

		// The record was replaced before the order was created.
		$replaced = array(
			'origin'     => 'paypal_express_order_creation',
			'session_id' => 'another-session',
			'decision'   => FraudDecision::Allow,
			'used'       => false,
			'order_id'   => '',
			'cart_hash'  => '',
		);
		WC()->session->set( '_fraud_protection_paypal_verification', $replaced );

		$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( 'PP-123' ) );

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
		$this->score_create_order( 'scored-session', FraudDecision::Allow );
		$this->set_verification_record( origin: 'paypal_vault_order_creation' );
		$record = WC()->session->get( '_fraud_protection_paypal_verification' );

		$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( 'PP-123' ) );

		$this->assertSame( $record, WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/**
	 * @testdox An associated record's decision is what the associated route replays, whatever it is.
	 *
	 * An associated Block cannot be produced today — a blocked create-order dies
	 * before its order exists — but the route's contract is "replay the
	 * recorded decision", and this pin is what stops a future change from
	 * turning an associated record into a verdict-blind allow.
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
	 * @testdox Scoring again replaces the prior use with a fresh record without an associated order.
	 */
	public function test_verify_scoring_again_starts_without_an_associated_order(): void {
		$this->score_and_associate_order( 'scored-session', 'PP-1' );

		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-1' ) ) );
		$this->ask( 'blocks_checkout', 'ppcp-gateway', 'post-reset-spend' );

		// The same session is scored again; the mocks still resolve it.
		$this->paypal_compat->verify_and_block_create_order(
			array( SessionVerifier::SESSION_ID_FIELD => 'scored-session' )
		);

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
		$this->score_and_associate_order( 'scored-session', 'PP-1' );
		WC()->session->set( 'ppcp', array( 'order' => new FakePayPalOrder( 'PP-1' ) ) );

		$this->assertSame( FraudDecision::Allow, $this->ask( 'blocks_checkout', 'ppcp-gateway', 'scored-session' ) );

		// The retry: the same session is scored again and mints a new order.
		$this->paypal_compat->verify_and_block_create_order(
			array( SessionVerifier::SESSION_ID_FIELD => 'scored-session' )
		);
		$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( 'PP-2' ) );
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
	 * @testdox Setup records reject non-checkout sources and recheck final eligibility.
	 *
	 * @dataProvider disallowed_setup_source_provider
	 */
	public function test_setup_record_requires_current_eligible_cart( string $disallowed_source ): void {
		$this->set_setup_cart( 'cart-hash' );
		$this->run_allowed_setup_token_request();
		$request = array( 'payment_method' => 'ppcp-gateway' );

		$this->assert_incoming_decision_is_preserved( $disallowed_source, $request, 'response-session' );

		$this->run_setup_token_request();
		$this->set_setup_cart( 'cart-hash', array(), false );
		$this->assert_incoming_decision_is_preserved( 'blocks_checkout', $request, 'response-session' );
		$this->set_setup_cart( 'cart-hash' );
		$this->run_setup_token_request();
		$this->set_setup_cart( 'changed-hash' );
		$this->assert_incoming_decision_is_preserved( 'blocks_checkout', $request, 'response-session' );

		$this->set_setup_cart( 'cart-hash' );
		$this->run_setup_token_request();
		$this->assertInstanceOf(
			SuppliedDecision::class,
			$this->decision_reuse->supply_decision_for_paypal_express( false, 'shortcode_checkout', $request, 'response-session' )
		);

		$this->run_setup_token_request();
		$this->assertInstanceOf(
			SuppliedDecision::class,
			$this->decision_reuse->supply_decision_for_paypal_express( false, 'blocks_checkout', $request, 'response-session' )
		);
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
	 * @dataProvider final_setup_eligibility_provider
	 */
	public function test_setup_record_rechecks_material_eligibility( string $total, bool $empty, bool $managed_plan, string $cart_hash ): void {
		$this->set_setup_cart( 'cart-hash' );
		$this->run_allowed_setup_token_request();

		$items = array();
		if ( $managed_plan ) {
			$product = $this->createMock( \WC_Product::class );
			$product->method( 'get_meta' )->with( 'ppcp_subscription_plan' )->willReturn( 'plan-id' );
			$items = array( array( 'data' => $product ) );
		}
		$this->set_setup_cart( $cart_hash, $items, true, $total, $empty );

		$this->assert_incoming_decision_is_preserved(
			'blocks_checkout',
			array( 'payment_method' => 'ppcp-gateway' ),
			'response-session'
		);
	}

	/** @return array<string, array{string, bool, bool, string}> */
	public function final_setup_eligibility_provider(): array {
		return array(
			'positive total'      => array( '1', false, false, 'cart-hash' ),
			'empty cart'          => array( '0', true, false, 'cart-hash' ),
			'PayPal-managed plan' => array( '0', false, true, 'cart-hash' ),
			'empty cart hash'     => array( '0', false, false, '' ),
		);
	}

	/**
	 * @testdox Ineligible setup carts do not create reusable records at storage time.
	 *
	 * @dataProvider setup_storage_ineligibility_provider
	 *
	 * @param mixed $plan_metadata PayPal plan metadata.
	 */
	public function test_ineligible_setup_cart_is_not_recorded( string $total, bool $empty, bool $needs_payment, $plan_metadata, string $cart_hash ): void {
		$items = array();
		if ( null !== $plan_metadata ) {
			$product = $this->createMock( \WC_Product::class );
			$product->method( 'get_meta' )->with( 'ppcp_subscription_plan' )->willReturn( $plan_metadata );
			$items = array( array( 'data' => $product ) );
		}
		$this->set_setup_cart( $cart_hash, $items, $needs_payment, $total, $empty );
		$this->run_allowed_setup_token_request();

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
	}

	/** @return array<string, array{string, bool, bool, mixed, string}> */
	public function setup_storage_ineligibility_provider(): array {
		return array(
			'empty cart'               => array( '0', true, true, null, 'cart-hash' ),
			'positive total'           => array( '1', false, true, null, 'cart-hash' ),
			'payment not needed'       => array( '0', false, false, null, 'cart-hash' ),
			'PayPal-managed plan data' => array( '0', false, true, 'plan-id', 'cart-hash' ),
			'empty cart hash'          => array( '0', false, true, null, '' ),
		);
	}

	/** @testdox Nonempty array plan metadata prevents setup record storage. */
	public function test_array_plan_metadata_prevents_setup_record(): void {
		$product = $this->createMock( \WC_Product::class );
		$product->method( 'get_meta' )->with( 'ppcp_subscription_plan' )->willReturn( array( 'plan-id' ) );
		$this->set_setup_cart( 'cart-hash', array( array( 'data' => $product ) ) );
		$this->run_allowed_setup_token_request();

		$this->assertNull( WC()->session->get( '_fraud_protection_paypal_verification' ) );
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

		$this->run_allowed_setup_token_request();

		$record = WC()->session->get( '_fraud_protection_paypal_verification' );
		$this->assertIsArray( $record );
		$this->assertSame( 'cart-hash', $record['cart_hash'] );
	}

	/**
	 * Configure PayPal request-data compatibility stubs.
	 *
	 * @param array  $data    Request data.
	 * @param string $failure Failure mode.
	 */
	private function configure_paypal_request_data( array $data, string $failure = '' ): void {
		if ( ! class_exists( 'WooCommerce\\PayPalCommerce\\Button\\Endpoint\\RequestData' ) ) {
			class_alias( PayPalRequestDataStub::class, 'WooCommerce\\PayPalCommerce\\Button\\Endpoint\\RequestData' );
		}

		PayPalRequestDataStub::$data  = $data;
		PayPalRequestDataStub::$error = 'read' === $failure ? new \RuntimeException( 'invalid request' ) : null;
		PayPalPPCPStub::set_error( 'container' === $failure ? new \RuntimeException( 'container unavailable' ) : null );
		$service = match ( $failure ) {
			'service'   => new \stdClass(),
			default     => new PayPalRequestDataStub(),
		};
		PayPalContainerStub::set_service( 'button.request-data', $service );

	}

	/**
	 * Create a reusable record through its protected PayPal request path.
	 *
	 * @param string $record_type Record type.
	 * @return array Final request data.
	 */
	private function create_protected_paypal_request_record( string $record_type ): array {
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );

		if ( 'create' === $record_type ) {
			$this->score_and_associate_order( 'browser-session', resolved_session_id: 'response-session' );
		} else {
			$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
			if ( 'setup' === $record_type ) {
				$this->set_setup_cart( 'cart-hash' );
				$this->run_setup_token_request();
			} else {
				$this->run_vault_order_request();
				$this->paypal_compat->associate_created_order_with_verification( new FakePayPalOrder( 'PP-123' ) );
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

	/** Run one setup-token request with an allowed response-backed session. */
	private function run_allowed_setup_token_request(): void {
		$this->configure_paypal_request_data( array( SessionVerifier::SESSION_ID_FIELD => 'browser-session' ) );
		$this->session_verifier->method( 'verify_session' )->willReturn( FraudDecision::Allow );
		$this->session_verifier->method( 'last_verified_session_id' )->willReturn( 'response-session' );
		$this->run_setup_token_request();
	}

	/** Run the setup-token request through its exact WooCommerce action and PayPal path. */
	private function run_setup_token_request(): void {
		$this->run_protected_request( 'wc_ajax_ppc-create-setup-token', array( 'method' => 'POST' ), '/v3/vault/setup-tokens' );
	}

	/** Run the vault-order request through its exact WooCommerce action and PayPal path. */
	private function run_vault_order_request(): void {
		$this->run_protected_request( 'wc_ajax_ppc-vault-create-order', array( 'method' => 'POST' ), '/v2/checkout/orders' );
	}

	/**
	 * Run a request filter while its WooCommerce AJAX action is active.
	 *
	 * @param string $action Action name.
	 * @param array  $args   HTTP arguments.
	 * @param string $path   PayPal URL path.
	 * @return mixed Filter result.
	 */
	private function run_protected_request( string $action, array $args, string $path ) {
		$this->paypal_compat->register();
		$result   = null;
		$callback = function () use ( &$result, $args, $path ): void {
			$result = apply_filters( 'ppcp_request_args', $args, 'https://api-m.paypal.com' . $path );
		};
		add_action( $action, $callback );
		try {
			do_action( $action );
		} finally {
			remove_action( $action, $callback );
		}

		return $result;
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
