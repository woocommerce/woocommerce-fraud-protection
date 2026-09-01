<?php
/**
 * SessionEventRecorderTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;

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
	 * Mock schema manager reporting the schema as installed.
	 *
	 * @var SchemaManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $schema_manager;

	/**
	 * Mock visitor IP resolver.
	 *
	 * @var VisitorIpResolver&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $visitor_ip_resolver;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->event_store = $this->createMock( SessionEventStore::class );

		$this->schema_manager = $this->createMock( SchemaManager::class );
		$this->schema_manager->method( 'is_schema_installed' )->willReturn( true );

		$this->visitor_ip_resolver = $this->createMock( VisitorIpResolver::class );
		$this->sut                 = new SessionEventRecorder();
		$this->sut->init( $this->event_store, new MerchantListsFeature(), $this->schema_manager, $this->visitor_ip_resolver );
	}

	/**
	 * A session data payload as assembled by SessionVerifier.
	 *
	 * @return array
	 */
	private function a_session_data_payload(): array {
		return array(
			'source'   => 'blocks_checkout',
			'session'  => array(
				'wc_identity_id' => 'identity-1',
				'email'          => 'account@example.com',
			),
			'customer' => array(
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
			'order'    => array( 'order_id' => 456 ),
			'payment'  => array( 'gateway' => 'woocommerce_payments' ),
		);
	}

	/**
	 * A verify result carrying the given received decision.
	 *
	 * @param FraudDecision $received The decision as received from the API.
	 * @return VerifyResult
	 */
	private function a_verify_result( FraudDecision $received ): VerifyResult {
		return VerifyResult::create( $received, 'session-xyz', 0.87 );
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
		return $this->record_result_and_capture( $this->a_verify_result( $received ), $applied, $payload );
	}

	/**
	 * Record a verify result and return the event row captured by the store mock.
	 *
	 * @param VerifyResult  $result       The verify result to record.
	 * @param FraudDecision $applied      The decision actually applied.
	 * @param ?array        $payload      The session data payload (defaults to {@see a_session_data_payload()}).
	 * @param ?Rule         $matched_rule The merchant rule that decided the outcome, if any.
	 * @return ?array The captured event row.
	 */
	private function record_result_and_capture( VerifyResult $result, FraudDecision $applied, ?array $payload = null, ?Rule $matched_rule = null ): ?array {
		$captured = null;
		$this->event_store
			->method( 'record_event' )
			->willReturnCallback(
				function ( array $event ) use ( &$captured ) {
					$captured = $event;
					return true;
				}
			);

		$this->sut->record_decision( $result, $applied, $payload ?? $this->a_session_data_payload(), $matched_rule );

		return $captured;
	}

	/**
	 * A rule with the given action, as the evaluator would return it.
	 *
	 * @param FraudDecision $action The rule action.
	 * @return Rule
	 */
	private function a_matching_rule( FraudDecision $action ): Rule {
		return Rule::from_row(
			array(
				'id'         => 42,
				'action'     => $action->value,
				'status'     => 'active',
				'position'   => 1,
				'conditions' => '{"field":"email","operator":"equals","value":"someone@example.com"}',
				'created_at' => '2026-07-27 00:00:00',
			)
		);
	}

	/**
	 * @testdox Should not record anything when the feature gate is off.
	 */
	public function test_does_not_record_when_feature_disabled(): void {
		$disabled_feature = $this->createMock( MerchantListsFeature::class );
		$disabled_feature->method( 'is_enabled' )->willReturn( false );

		$sut = new SessionEventRecorder();
		$sut->init( $this->event_store, $disabled_feature, $this->schema_manager, $this->visitor_ip_resolver );

		$this->event_store
			->expects( $this->never() )
			->method( 'record_event' );

		$sut->record_decision( $this->a_verify_result( FraudDecision::Block ), FraudDecision::Allow, $this->a_session_data_payload() );
	}

	/**
	 * @testdox Should not record anything while the sessions schema is not installed.
	 */
	public function test_does_not_record_when_schema_not_installed(): void {
		$missing_schema = $this->createMock( SchemaManager::class );
		$missing_schema->method( 'is_schema_installed' )->willReturn( false );

		$sut = new SessionEventRecorder();
		$sut->init( $this->event_store, new MerchantListsFeature(), $missing_schema, $this->visitor_ip_resolver );

		$this->event_store
			->expects( $this->never() )
			->method( 'record_event' );

		$sut->record_decision( $this->a_verify_result( FraudDecision::Block ), FraudDecision::Block, $this->a_session_data_payload() );
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
	 * @testdox Should store the visitor IP and country from VisitorIpResolver.
	 */
	public function test_records_visitor_ip_resolver_values(): void {
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_address' )
			->willReturn( '203.0.113.7' );
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_country' )
			->with( '203.0.113.7' )
			->willReturn( 'US' );

		$captured = $this->record_and_capture( FraudDecision::Allow, FraudDecision::Allow );

		$this->assertSame( '203.0.113.7', $captured['ip'] );
		$this->assertSame( 'US', $captured['ip_country'] );
	}

	/**
	 * @testdox Should store empty IP data when VisitorIpResolver returns no IP.
	 */
	public function test_records_empty_ip_data_without_resolved_ip(): void {
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_address' )
			->willReturn( null );
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_country' )
			->with( null )
			->willReturn( '' );

		$captured = $this->record_and_capture( FraudDecision::Allow, FraudDecision::Allow );

		$this->assertSame( '', $captured['ip'] );
		$this->assertSame( '', $captured['ip_country'] );
	}

	/**
	 * @testdox Should record fail-open results under the verify_error trigger, distinguishable from genuine Blackbox allows.
	 */
	public function test_derives_verify_error_trigger_for_fail_open_results(): void {
		$captured = $this->record_result_and_capture( VerifyResult::fail_open(), FraudDecision::Allow );

		$this->assertSame( 'allow', $captured['decision'] );
		$this->assertSame( 'allowed', $captured['final_status'] );
		$this->assertSame( 'verify_error', $captured['trigger_type'] );
		$this->assertSame( '', $captured['session_id'], 'A fail-open result must not trust the submitted session ID' );
		$this->assertNull( $captured['risk_score'] );
	}

	/**
	 * @testdox Should record confirmed rejected requests under their own trigger.
	 */
	public function test_derives_request_rejected_trigger(): void {
		$captured = $this->record_result_and_capture( VerifyResult::request_rejected(), FraudDecision::Allow );

		$this->assertSame( 'block', $captured['decision'] );
		$this->assertSame( 'allowed', $captured['final_status'] );
		$this->assertSame( 'request_rejected', $captured['trigger_type'] );
	}

	/**
	 * @testdox Should let a matched rule take priority over the rejected-request trigger.
	 */
	public function test_matched_rule_trigger_takes_precedence_over_request_rejected(): void {
		$captured = $this->record_result_and_capture(
			VerifyResult::request_rejected(),
			FraudDecision::Block,
			null,
			$this->a_matching_rule( FraudDecision::Block )
		);

		$this->assertSame( 'block_rule', $captured['trigger_type'] );
		$this->assertSame( 42, $captured['matched_rule_id'] );
	}

	/**
	 * @testdox Should record a matching allow rule under the allow_rule trigger with the rule id, keeping the received decision.
	 */
	public function test_records_allow_rule_trigger_for_matching_allow_rule(): void {
		$captured = $this->record_result_and_capture(
			$this->a_verify_result( FraudDecision::Block ),
			FraudDecision::Allow,
			null,
			$this->a_matching_rule( FraudDecision::Allow )
		);

		$this->assertSame( 'block', $captured['decision'], 'The Blackbox verdict must be recorded as received' );
		$this->assertSame( 'allowed', $captured['final_status'] );
		$this->assertSame( 'allow_rule', $captured['trigger_type'] );
		$this->assertSame( 42, $captured['matched_rule_id'] );
	}

	/**
	 * @testdox Should record a matching block rule under the block_rule trigger with the rule id.
	 */
	public function test_records_block_rule_trigger_for_matching_block_rule(): void {
		$captured = $this->record_result_and_capture(
			$this->a_verify_result( FraudDecision::Allow ),
			FraudDecision::Block,
			null,
			$this->a_matching_rule( FraudDecision::Block )
		);

		$this->assertSame( 'allow', $captured['decision'] );
		$this->assertSame( 'blocked', $captured['final_status'] );
		$this->assertSame( 'block_rule', $captured['trigger_type'] );
		$this->assertSame( 42, $captured['matched_rule_id'] );
	}

	/**
	 * @testdox Should let a matched rule trigger take precedence over verify_error when the verify failed open.
	 */
	public function test_matched_rule_trigger_takes_precedence_over_verify_error(): void {
		$captured = $this->record_result_and_capture(
			VerifyResult::fail_open(),
			FraudDecision::Block,
			null,
			$this->a_matching_rule( FraudDecision::Block )
		);

		$this->assertSame( 'block_rule', $captured['trigger_type'], 'The rule decided the outcome, whatever the verify did' );
		$this->assertSame( 42, $captured['matched_rule_id'] );
	}

	/**
	 * @testdox Should record a null matched rule id when no rule decided the outcome.
	 */
	public function test_records_null_matched_rule_id_without_a_rule(): void {
		$captured = $this->record_and_capture( FraudDecision::Allow, FraudDecision::Allow );

		$this->assertNull( $captured['matched_rule_id'] );
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

		$this->sut->record_decision( $this->a_verify_result( FraudDecision::Block ), FraudDecision::Block, $this->a_session_data_payload() );

		$this->assertLogged( 'warning', 'Failed to record session event' );
	}

	/**
	 * @testdox Should log a warning and not throw when the store throws.
	 */
	public function test_fails_open_when_store_throws(): void {
		$this->event_store
			->method( 'record_event' )
			->willThrowException( new \RuntimeException( 'database exploded' ) );

		$this->sut->record_decision( $this->a_verify_result( FraudDecision::Block ), FraudDecision::Block, $this->a_session_data_payload() );

		$this->assertLogged( 'warning', 'Session event recording failed' );
	}
}
