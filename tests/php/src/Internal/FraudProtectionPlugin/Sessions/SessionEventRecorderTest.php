<?php
/**
 * SessionEventRecorderTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
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
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( MerchantListsFeature::OPTION_NAME );
		parent::tearDown();
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
	 * @testdox Should not record anything when the feature is disabled.
	 */
	public function test_does_not_record_when_feature_disabled(): void {
		$this->event_store
			->expects( $this->never() )
			->method( 'record_event' );

		$this->sut->record_verdict( FraudDecision::Block, SessionFinalStatus::NotEnforced, SessionTrigger::Blackbox, $this->a_session_data_payload() );
	}

	/**
	 * @testdox Should record allow verdicts too.
	 */
	public function test_records_allow_verdicts(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$captured = null;
		$this->event_store
			->expects( $this->once() )
			->method( 'record_event' )
			->willReturnCallback(
				function ( array $event ) use ( &$captured ) {
					$captured = $event;
					return true;
				}
			);

		$this->sut->record_verdict( FraudDecision::Allow, SessionFinalStatus::Allowed, SessionTrigger::Blackbox, $this->a_session_data_payload() );

		$this->assertSame( 'allow', $captured['verdict'] );
		$this->assertSame( 'allowed', $captured['final_status'] );
	}

	/**
	 * @testdox Should map the session data payload to an event row for block verdicts.
	 */
	public function test_records_block_verdict_with_mapped_payload(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$captured = null;
		$this->event_store
			->expects( $this->once() )
			->method( 'record_event' )
			->willReturnCallback(
				function ( array $event ) use ( &$captured ) {
					$captured = $event;
					return true;
				}
			);

		$this->sut->record_verdict( FraudDecision::Block, SessionFinalStatus::Blocked, SessionTrigger::Blackbox, $this->a_session_data_payload() );

		$this->assertSame( 'session-xyz', $captured['session_id'] );
		$this->assertSame( 'blocks_checkout', $captured['source'] );
		$this->assertSame( 'block', $captured['verdict'] );
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
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$payload                              = $this->a_session_data_payload();
		$payload['customer']['billing_email'] = '';

		$captured = null;
		$this->event_store
			->method( 'record_event' )
			->willReturnCallback(
				function ( array $event ) use ( &$captured ) {
					$captured = $event;
					return true;
				}
			);

		$this->sut->record_verdict( FraudDecision::Challenge, SessionFinalStatus::NotEnforced, SessionTrigger::Blackbox, $payload );

		$this->assertSame( 'account@example.com', $captured['email'] );
		$this->assertSame( 'challenge', $captured['verdict'] );
	}

	/**
	 * @testdox Should log a warning and not throw when the store reports a failure.
	 */
	public function test_logs_warning_when_store_fails(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$this->event_store
			->method( 'record_event' )
			->willReturn( false );

		$this->sut->record_verdict( FraudDecision::Block, SessionFinalStatus::Blocked, SessionTrigger::Blackbox, $this->a_session_data_payload() );

		$this->assertLogged( 'warning', 'Failed to record session event' );
	}

	/**
	 * @testdox Should log a warning and not throw when the store throws.
	 */
	public function test_fails_open_when_store_throws(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$this->event_store
			->method( 'record_event' )
			->willThrowException( new \RuntimeException( 'database exploded' ) );

		$this->sut->record_verdict( FraudDecision::Block, SessionFinalStatus::Blocked, SessionTrigger::Blackbox, $this->a_session_data_payload() );

		$this->assertLogged( 'warning', 'Session event recording failed' );
	}
}
