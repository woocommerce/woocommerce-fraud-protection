<?php
/**
 * SessionEventRecorderTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the SessionEventRecorder class.
 */
class SessionEventRecorderTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionEventRecorder
	 */
	private $sut;

	/**
	 * Mock session event store.
	 *
	 * @var SessionEventStore&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_store;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->event_store = $this->createMock( SessionEventStore::class );
		$this->sut         = new SessionEventRecorder();
		$this->sut->init( $this->event_store, new MerchantListsFeature() );
	}

	/**
	 * A session data payload as assembled by SessionVerifier.
	 *
	 * @return array
	 */
	private function a_session_data_payload(): array {
		return array(
			'source'                                 => 'blocks_checkout',
			'session'                                => array(
				'wc_identity_id' => 'identity-1',
				'email'          => 'account@example.com',
			),
			'customer'                               => array(
				'billing_email'   => 'Customer@Example.COM ',
				'billing_address' => array(
					'first_name' => 'Jane',
					'last_name'  => 'Doe',
					'city'       => 'San Francisco',
					'state'      => 'CA',
					'postcode'   => '94110',
					'country'    => 'US',
				),
			),
			'order'                                  => array( 'order_id' => 456 ),
			SessionEventRecorder::VERIFY_RESULT_KEY => array(
				'session_id'     => 'session-xyz',
				'risk_score'     => 0.87,
				'payment_method' => 'woocommerce_payments',
			),
		);
	}

	/**
	 * Record a decision and return the event row captured by the store mock.
	 *
	 * @param FraudDecision $received The decision as received from the API.
	 * @param FraudDecision $applied  The decision actually applied.
	 * @param ?array        $payload  The session data payload (defaults to {@see a_session_data_payload()}).
	 * @return ?array The captured event row.
	 */
	private function record_and_capture( FraudDecision $received, FraudDecision $applied, ?array $payload = null ): ?array {
		$captured = null;
		$this->event_store
			->method( 'record_event' )
			->willReturnCallback(
				function ( array $event ) use ( &$captured ) {
					$captured = $event;
					return true;
				}
			);

		$this->sut->record_decision( $received, $applied, SessionTrigger::Blackbox, $payload ?? $this->a_session_data_payload() );

		return $captured;
	}

	/**
	 * @testdox Should not record anything when the feature gate is off.
	 */
	public function test_does_not_record_when_feature_disabled(): void {
		$disabled_feature = $this->createMock( MerchantListsFeature::class );
		$disabled_feature->method( 'is_enabled' )->willReturn( false );

		$sut = new SessionEventRecorder();
		$sut->init( $this->event_store, $disabled_feature );

		$this->event_store
			->expects( $this->never() )
			->method( 'record_event' );

		$sut->record_decision( FraudDecision::Block, FraudDecision::Allow, SessionTrigger::Blackbox, $this->a_session_data_payload() );
	}

	/**
	 * @testdox Should record allow decisions too, with final status allowed.
	 */
	public function test_records_allow_decisions(): void {
		$captured = $this->record_and_capture( FraudDecision::Allow, FraudDecision::Allow );

		$this->assertSame( 'allow', $captured['decision'] );
		$this->assertSame( 'allowed', $captured['final_status'] );
	}

	/**
	 * @testdox Should derive final status allowed for a suppressed block decision, keeping the received block.
	 */
	public function test_derives_allowed_for_suppressed_block(): void {
		$captured = $this->record_and_capture( FraudDecision::Block, FraudDecision::Allow );

		$this->assertSame( 'block', $captured['decision'] );
		$this->assertSame( 'allowed', $captured['final_status'] );
	}

	/**
	 * @testdox Should derive final status blocked whenever the applied decision is block.
	 */
	public function test_derives_blocked_for_applied_block(): void {
		$captured = $this->record_and_capture( FraudDecision::Allow, FraudDecision::Block );

		$this->assertSame( 'allow', $captured['decision'] );
		$this->assertSame( 'blocked', $captured['final_status'], 'A filter override to block, enforced, should be recorded as blocked' );
	}

	/**
	 * @testdox Should map the session data payload to an event row for block decisions.
	 */
	public function test_records_block_decision_with_mapped_payload(): void {
		$this->event_store->expects( $this->once() )->method( 'record_event' );

		$captured = $this->record_and_capture( FraudDecision::Block, FraudDecision::Block );

		$this->assertSame( 'session-xyz', $captured['session_id'] );
		$this->assertSame( 'blocks_checkout', $captured['source'] );
		$this->assertSame( 'block', $captured['decision'] );
		$this->assertSame( 'blocked', $captured['final_status'] );
		$this->assertSame( 'blackbox', $captured['trigger_type'] );
		$this->assertSame( 0.87, $captured['risk_score'] );
		$this->assertSame( 'customer@example.com', $captured['email'], 'The billing email should be normalized' );
		$this->assertSame( 'US', $captured['billing_country'] );
		$this->assertSame( 'Jane Doe', $captured['billing_name'] );
		$this->assertSame( 456, $captured['order_id'] );
		$this->assertSame( 'woocommerce_payments', $captured['payment_method'] );
	}

	/**
	 * @testdox Should fall back to the account email when there is no billing email.
	 */
	public function test_falls_back_to_account_email(): void {
		$payload                              = $this->a_session_data_payload();
		$payload['customer']['billing_email'] = '';

		$captured = $this->record_and_capture( FraudDecision::Challenge, FraudDecision::Allow, $payload );

		$this->assertSame( 'account@example.com', $captured['email'] );
		$this->assertSame( 'challenge', $captured['decision'] );
		$this->assertSame( 'allowed', $captured['final_status'] );
	}

	/**
	 * @testdox Should cap text fields by characters, not bytes, so a multibyte value is never split mid-character.
	 */
	public function test_caps_multibyte_fields_by_characters(): void {
		$payload = $this->a_session_data_payload();

		$payload['customer']['billing_address']['first_name'] = str_repeat( 'é', 300 );
		$payload['customer']['billing_address']['last_name']  = '';

		$captured = $this->record_and_capture( FraudDecision::Allow, FraudDecision::Allow, $payload );

		$this->assertSame( str_repeat( 'é', 255 ), $captured['billing_name'], 'The cap must keep 255 whole characters, not 255 bytes' );
	}

	/**
	 * @testdox Should log a warning and not throw when the store reports a failure.
	 */
	public function test_logs_warning_when_store_fails(): void {
		$this->event_store
			->method( 'record_event' )
			->willReturn( false );

		$this->sut->record_decision( FraudDecision::Block, FraudDecision::Block, SessionTrigger::Blackbox, $this->a_session_data_payload() );

		$this->assertLogged( 'warning', 'Failed to record session event' );
	}

	/**
	 * @testdox Should log a warning and not throw when the store throws.
	 */
	public function test_fails_open_when_store_throws(): void {
		$this->event_store
			->method( 'record_event' )
			->willThrowException( new \RuntimeException( 'database exploded' ) );

		$this->sut->record_decision( FraudDecision::Block, FraudDecision::Block, SessionTrigger::Blackbox, $this->a_session_data_payload() );

		$this->assertLogged( 'warning', 'Session event recording failed' );
	}
}
