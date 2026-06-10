<?php
/**
 * WooPaymentsReportCompatTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsReportCompat;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\RestApi\UnitTests\LoggerSpyTrait;
use WC_Unit_Test_Case;

/**
 * Tests for the WooPaymentsReportCompat class.
 *
 * Integration-style: fires the WooPayments webhook delivery action with
 * fixture Stripe event bodies and captures the outgoing Blackbox /report
 * request via pre_http_request.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Compat\WooPaymentsReportCompat
 */
class WooPaymentsReportCompatTest extends WC_Unit_Test_Case {

	use LoggerSpyTrait;

	/**
	 * The WooPayments webhook delivery action.
	 *
	 * @var string
	 */
	private const WEBHOOK_HOOK = 'woocommerce_payments_after_webhook_delivery';

	/**
	 * The System Under Test.
	 *
	 * @var WooPaymentsReportCompat
	 */
	private WooPaymentsReportCompat $sut;

	/**
	 * URLs of captured outgoing HTTP requests.
	 *
	 * @var array<int, string>
	 */
	private array $captured_urls = array();

	/**
	 * JSON-decoded bodies of captured outgoing HTTP requests.
	 *
	 * @var array<int, array>
	 */
	private array $captured_bodies = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Jetpack connection data so ApiClient::make_request() reaches the HTTP layer.
		update_option( 'jetpack_options', array( 'id' => 12345 ) );
		update_option( 'jetpack_private_options', array( 'blog_token' => 'IAM.AJETPACKBLOGTOKEN' ) );

		$this->captured_urls   = array();
		$this->captured_bodies = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				unset( $preempt );
				$this->captured_urls[]   = $url;
				$this->captured_bodies[] = json_decode( $args['body'], true );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode( array( 'data' => array( 'status' => 'ok' ) ) ),
				);
			},
			10,
			3
		);

		// The plugin bootstrap registers its own instance; clear it so only the SUT listens.
		remove_all_actions( self::WEBHOOK_HOOK );
		$this->sut = new WooPaymentsReportCompat();
		$this->sut->register();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_filters( 'pre_http_request' );
		remove_all_actions( self::WEBHOOK_HOOK );
		delete_option( 'jetpack_options' );
		delete_option( 'jetpack_private_options' );
		parent::tearDown();
	}

	/**
	 * Create an order paid with WooPayments, carrying a Blackbox session ID.
	 *
	 * @param array<string, string> $meta Extra order meta (e.g. _intent_id, _charge_id).
	 * @return \WC_Order
	 */
	private function create_woopayments_order( array $meta = array() ): \WC_Order {
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->update_meta_data( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, 'bb-session-123' );
		foreach ( $meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		$order->save();

		return $order;
	}

	/**
	 * Build a payment_intent.* event body fixture.
	 *
	 * @param array<string, mixed> $intent_overrides Fields merged over the intent object.
	 * @return array
	 */
	private function make_intent_event( array $intent_overrides = array() ): array {
		return array(
			'id'      => 'evt_1',
			'created' => 1750000000,
			'data'    => array(
				'object' => array_merge(
					array(
						'id'              => 'pi_123',
						'amount'          => 4500,
						'amount_received' => 4500,
						'currency'        => 'usd',
						'metadata'        => array(),
						'charges'         => array( 'data' => array( array( 'id' => 'ch_123' ) ) ),
					),
					$intent_overrides
				),
			),
		);
	}

	/**
	 * Build a charge.dispute.* event body fixture.
	 *
	 * @param array<string, mixed> $dispute_overrides Fields merged over the dispute object.
	 * @return array
	 */
	private function make_dispute_event( array $dispute_overrides = array() ): array {
		return array(
			'id'      => 'evt_2',
			'created' => 1750000000,
			'data'    => array(
				'object' => array_merge(
					array(
						'id'             => 'dp_123',
						'charge'         => 'ch_123',
						'payment_intent' => 'pi_123',
						'reason'         => 'fraudulent',
						'status'         => 'needs_response',
						'amount'         => 4500,
						'currency'       => 'usd',
					),
					$dispute_overrides
				),
			),
		);
	}

	/**
	 * Return the context object of the single captured report request.
	 *
	 * @return array
	 */
	private function get_single_reported_context(): array {
		$this->assertCount( 1, $this->captured_bodies, 'Exactly one report request should have been sent' );
		$this->assertStringContainsString( '/v1/report/bb-session-123', $this->captured_urls[0] );
		$this->assertArrayHasKey( 'context', $this->captured_bodies[0] );

		return $this->captured_bodies[0]['context'];
	}

	/*
	|--------------------------------------------------------------------------
	| payment_intent.succeeded
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox Reports a captured payment with amount, correlation IDs, and order-derived gateway.
	 */
	public function test_payment_succeeded_reports_captured_context(): void {
		$order = $this->create_woopayments_order();
		$event = $this->make_intent_event( array( 'metadata' => array( 'order_id' => $order->get_id() ) ) );

		do_action( self::WEBHOOK_HOOK, 'payment_intent.succeeded', $event );

		$context = $this->get_single_reported_context();
		$this->assertSame( 'api', $this->captured_bodies[0]['source'] );
		$this->assertSame( 'payment', $context['type'] );
		$this->assertSame( 'captured', $context['result'] );
		$this->assertSame( 'neutral', $context['evidence_strength'] );
		$this->assertSame( array( 'minor_units' => 4500, 'currency' => 'USD' ), $context['amount'] );
		$this->assertSame( '2025-06-15T15:06:40Z', $context['occurred_at'] );
		$this->assertSame( 'woocommerce_payments', $context['gateway'] );
		$this->assertSame(
			array(
				'order_id'           => $order->get_id(),
				'transaction_id'     => 'ch_123',
				'payment_attempt_id' => 'pi_123',
			),
			$context['correlation']
		);
		$this->assertArrayNotHasKey( 'reason', $context );
	}

	/**
	 * @testdox Resolves the order through _intent_id meta when intent metadata has no order_id.
	 */
	public function test_payment_succeeded_resolves_order_by_intent_meta(): void {
		$this->create_woopayments_order( array( '_intent_id' => 'pi_123' ) );

		do_action( self::WEBHOOK_HOOK, 'payment_intent.succeeded', $this->make_intent_event() );

		$context = $this->get_single_reported_context();
		$this->assertSame( 'captured', $context['result'] );
	}

	/*
	|--------------------------------------------------------------------------
	| payment_intent.payment_failed
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox Reports a declined payment with the mapped decline reason.
	 */
	public function test_payment_failed_reports_mapped_decline_reason(): void {
		$order = $this->create_woopayments_order();
		$event = $this->make_intent_event(
			array(
				'metadata'           => array( 'order_id' => $order->get_id() ),
				'last_payment_error' => array(
					'code'         => 'card_declined',
					'decline_code' => 'insufficient_funds',
				),
			)
		);

		do_action( self::WEBHOOK_HOOK, 'payment_intent.payment_failed', $event );

		$context = $this->get_single_reported_context();
		$this->assertSame( 'declined', $context['result'] );
		$this->assertSame( 'insufficient_funds', $context['reason'] );
		$this->assertSame( 'soft_decline', $context['classification'] );
		$this->assertSame( 'correlated', $context['evidence_strength'] );
	}

	/**
	 * @testdox Falls back to generic_decline for an unmapped decline code.
	 */
	public function test_payment_failed_falls_back_to_generic_decline(): void {
		$order = $this->create_woopayments_order();
		$event = $this->make_intent_event(
			array(
				'metadata'           => array( 'order_id' => $order->get_id() ),
				'last_payment_error' => array( 'code' => 'card_declined', 'decline_code' => 'try_again_later' ),
			)
		);

		do_action( self::WEBHOOK_HOOK, 'payment_intent.payment_failed', $event );

		$context = $this->get_single_reported_context();
		$this->assertSame( 'declined', $context['result'] );
		$this->assertSame( 'generic_decline', $context['reason'] );
		$this->assertSame( 'hard_decline', $context['classification'] );
	}

	/**
	 * @testdox Classifies a fraudulent decline code as a provider fraud block.
	 */
	public function test_payment_failed_with_fraudulent_decline_code_reports_blocked(): void {
		$order = $this->create_woopayments_order();
		$event = $this->make_intent_event(
			array(
				'metadata'           => array( 'order_id' => $order->get_id() ),
				'last_payment_error' => array( 'code' => 'card_declined', 'decline_code' => 'fraudulent' ),
			)
		);

		do_action( self::WEBHOOK_HOOK, 'payment_intent.payment_failed', $event );

		$context = $this->get_single_reported_context();
		$this->assertSame( 'blocked', $context['result'] );
		$this->assertSame( 'suspected_fraud', $context['reason'] );
		$this->assertSame( 'fraud_flagged', $context['classification'] );
		$this->assertSame( 'confirmed', $context['evidence_strength'] );
	}

	/**
	 * @testdox Classifies a blocked charge outcome as a provider fraud block.
	 */
	public function test_payment_failed_with_blocked_outcome_reports_blocked(): void {
		$order = $this->create_woopayments_order();
		$event = $this->make_intent_event(
			array(
				'metadata'           => array( 'order_id' => $order->get_id() ),
				'last_payment_error' => array( 'code' => 'card_declined', 'decline_code' => 'generic_decline' ),
				'charges'            => array(
					'data' => array(
						array(
							'id'      => 'ch_123',
							'outcome' => array( 'type' => 'blocked' ),
						),
					),
				),
			)
		);

		do_action( self::WEBHOOK_HOOK, 'payment_intent.payment_failed', $event );

		$context = $this->get_single_reported_context();
		$this->assertSame( 'blocked', $context['result'] );
		$this->assertSame( 'suspected_fraud', $context['reason'] );
	}

	/*
	|--------------------------------------------------------------------------
	| charge.dispute.created / charge.dispute.closed
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox Reports an open fraud dispute with source chargeback and full correlation.
	 */
	public function test_dispute_created_reports_open_fraud_dispute(): void {
		$order = $this->create_woopayments_order( array( '_charge_id' => 'ch_123' ) );

		do_action( self::WEBHOOK_HOOK, 'charge.dispute.created', $this->make_dispute_event() );

		$context = $this->get_single_reported_context();
		$this->assertSame( 'chargeback', $this->captured_bodies[0]['source'] );
		$this->assertSame( 'dispute', $context['type'] );
		$this->assertSame( 'open', $context['result'] );
		$this->assertSame( 'fraud', $context['reason'] );
		$this->assertSame( 'fraud_flagged', $context['classification'] );
		$this->assertSame( 'correlated', $context['evidence_strength'] );
		$this->assertSame(
			array(
				'order_id'           => $order->get_id(),
				'transaction_id'     => 'ch_123',
				'payment_attempt_id' => 'pi_123',
				'dispute_id'         => 'dp_123',
			),
			$context['correlation']
		);
	}

	/**
	 * @testdox Reports a pre-dispute warning status as an inquiry.
	 */
	public function test_dispute_created_with_warning_status_reports_inquiry(): void {
		$this->create_woopayments_order( array( '_charge_id' => 'ch_123' ) );

		do_action(
			self::WEBHOOK_HOOK,
			'charge.dispute.created',
			$this->make_dispute_event( array( 'status' => 'warning_needs_response' ) )
		);

		$context = $this->get_single_reported_context();
		$this->assertSame( 'inquiry', $context['result'] );
	}

	/**
	 * @testdox Reports a won dispute as confirmed evidence.
	 */
	public function test_dispute_closed_won_reports_won(): void {
		$this->create_woopayments_order( array( '_charge_id' => 'ch_123' ) );

		do_action(
			self::WEBHOOK_HOOK,
			'charge.dispute.closed',
			$this->make_dispute_event( array( 'status' => 'won' ) )
		);

		$context = $this->get_single_reported_context();
		$this->assertSame( 'won', $context['result'] );
		$this->assertSame( 'confirmed', $context['evidence_strength'] );
	}

	/**
	 * @testdox Reports a lost fraud dispute as confirmed fraud-flagged evidence.
	 */
	public function test_dispute_closed_lost_reports_lost_fraud(): void {
		$this->create_woopayments_order( array( '_charge_id' => 'ch_123' ) );

		do_action(
			self::WEBHOOK_HOOK,
			'charge.dispute.closed',
			$this->make_dispute_event( array( 'status' => 'lost' ) )
		);

		$context = $this->get_single_reported_context();
		$this->assertSame( 'lost', $context['result'] );
		$this->assertSame( 'fraud', $context['reason'] );
		$this->assertSame( 'fraud_flagged', $context['classification'] );
		$this->assertSame( 'confirmed', $context['evidence_strength'] );
	}

	/**
	 * @testdox Maps an unmapped dispute reason to other.
	 */
	public function test_dispute_with_unmapped_reason_falls_back_to_other(): void {
		$this->create_woopayments_order( array( '_charge_id' => 'ch_123' ) );

		do_action(
			self::WEBHOOK_HOOK,
			'charge.dispute.created',
			$this->make_dispute_event( array( 'reason' => 'some_future_reason' ) )
		);

		$context = $this->get_single_reported_context();
		$this->assertSame( 'other', $context['reason'] );
		$this->assertSame( 'no_risk', $context['classification'] );
	}

	/*
	|--------------------------------------------------------------------------
	| Skip paths
	|--------------------------------------------------------------------------
	*/

	/**
	 * @testdox Skips silently when the order has no Blackbox session ID.
	 */
	public function test_skips_order_without_session_id(): void {
		$order = \WC_Helper_Order::create_order();
		$order->set_payment_method( 'woocommerce_payments' );
		$order->save();
		$event = $this->make_intent_event( array( 'metadata' => array( 'order_id' => $order->get_id() ) ) );

		do_action( self::WEBHOOK_HOOK, 'payment_intent.succeeded', $event );

		$this->assertCount( 0, $this->captured_bodies, 'No report should be sent for an order without a session' );
		$this->assertNoErrorLogged();
	}

	/**
	 * @testdox Ignores unhandled webhook event types.
	 */
	public function test_ignores_unhandled_event_types(): void {
		$this->create_woopayments_order( array( '_charge_id' => 'ch_123' ) );

		do_action( self::WEBHOOK_HOOK, 'charge.refunded', $this->make_dispute_event() );

		$this->assertCount( 0, $this->captured_bodies, 'Unhandled event types should not produce reports' );
	}

	/**
	 * @testdox Skips silently when no order matches the event.
	 */
	public function test_skips_unresolvable_order(): void {
		do_action( self::WEBHOOK_HOOK, 'payment_intent.succeeded', $this->make_intent_event() );

		$this->assertCount( 0, $this->captured_bodies, 'No report should be sent when the order cannot be resolved' );
		$this->assertNoErrorLogged();
	}

	/**
	 * @testdox Skips and logs a warning for an unmapped dispute status.
	 */
	public function test_skips_unmapped_dispute_status(): void {
		$this->create_woopayments_order( array( '_charge_id' => 'ch_123' ) );

		do_action(
			self::WEBHOOK_HOOK,
			'charge.dispute.created',
			$this->make_dispute_event( array( 'status' => 'charge_refunded' ) )
		);

		$this->assertCount( 0, $this->captured_bodies, 'An unmapped dispute status should skip the report' );
		$this->assertLogged( 'warning', 'unmapped dispute status "charge_refunded"' );
	}

	/**
	 * @testdox Ignores malformed hook arguments without throwing.
	 */
	public function test_ignores_malformed_arguments(): void {
		do_action( self::WEBHOOK_HOOK, array( 'not-a-string' ), 'not-an-array' );
		do_action( self::WEBHOOK_HOOK, 'payment_intent.succeeded', array( 'data' => 'not-an-object' ) );

		$this->assertCount( 0, $this->captured_bodies, 'Malformed arguments should not produce reports' );
		$this->assertNoErrorLogged();
	}

	/**
	 * @testdox Swallows and logs exceptions thrown while handling an event.
	 */
	public function test_exception_is_swallowed_and_logged(): void {
		$this->create_woopayments_order( array( '_intent_id' => 'pi_123' ) );
		// @phpstan-ignore return.missing (the callback always throws)
		add_filter(
			'woocommerce_order_query_args',
			function () {
				throw new \RuntimeException( 'boom' );
			}
		);

		do_action( self::WEBHOOK_HOOK, 'payment_intent.succeeded', $this->make_intent_event() ); // @phpstan-ignore deadCode.unreachable

		remove_all_filters( 'woocommerce_order_query_args' );
		$this->assertCount( 0, $this->captured_bodies, 'No report should be sent when handling throws' );
		$this->assertLogged( 'error', 'failed to report WooPayments webhook event' );
	}
}
