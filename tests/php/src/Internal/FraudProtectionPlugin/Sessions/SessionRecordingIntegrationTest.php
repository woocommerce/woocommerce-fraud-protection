<?php
/**
 * SessionRecordingIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;

/**
 * Integration test for the session recording pipeline: a verify call whose
 * transport returns a block decision must produce a row in the sessions table,
 * exercising ApiClient parsing, DecisionHandler, RuleEvaluator,
 * SessionEventRecorder and SessionEventStore together.
 */
class SessionRecordingIntegrationTest extends FraudProtectionUnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->unset_server_variables( array( 'REMOTE_ADDR' ) );

		$schema_manager = wc_get_container()->get( SchemaManager::class );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $schema_manager->get_sessions_table_schema() );
		dbDelta( $schema_manager->get_rules_table_schema() );

		// The recorder skips events while the schema is not recorded as
		// installed, so stamp the version the same way a real install does.
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;

		$schema_manager = wc_get_container()->get( SchemaManager::class );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $schema_manager->get_sessions_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $schema_manager->get_rules_table_name() );
		delete_option( SchemaManager::DB_VERSION_OPTION );
		remove_all_filters( 'woocommerce_fraud_protection_learning_mode' );
		remove_all_filters( 'woocommerce_fraud_protection_automated_decision' );

		if ( function_exists( 'WC' ) && WC()->session instanceof \WC_Session ) {
			WC()->session->set( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY, null );
		}

		parent::tearDown();
	}

	/**
	 * Get the latest recorded row for a session ID, straight from the table.
	 *
	 * The store exposes no read methods (production only writes and prunes),
	 * so tests inspect the table directly.
	 *
	 * @param string $session_id The Blackbox session ID.
	 * @return ?array The row as an associative array, or null if not found.
	 */
	private function latest_row_for( string $session_id ): ?array {
		global $wpdb;

		$table = wc_get_container()->get( SchemaManager::class )->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s ORDER BY id DESC LIMIT 1", $session_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Get the latest row without an associated session ID.
	 *
	 * @return ?array The row, or null if none exists.
	 */
	private function latest_row_without_session_id(): ?array {
		global $wpdb;

		$table = wc_get_container()->get( SchemaManager::class )->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( "SELECT * FROM {$table} WHERE session_id IS NULL ORDER BY id DESC LIMIT 1", ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count the rows in the sessions table.
	 *
	 * @return int
	 */
	private function count_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . wc_get_container()->get( SchemaManager::class )->get_sessions_table_name() );
	}

	/**
	 * A SessionVerifier whose ApiClient transport returns the given response body.
	 *
	 * @param array     $response_data The `data` object of the API response body.
	 * @param ?callable $capture       Optional callback for the request arguments and body.
	 * @return SessionVerifier
	 */
	private function a_session_verifier_receiving( array $response_data, ?callable $capture = null ): SessionVerifier {
		return $this->a_session_verifier_with_transport(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => $response_data ) ),
			),
			$capture
		);
	}

	/**
	 * A SessionVerifier whose ApiClient transport returns the given raw result.
	 *
	 * @param array|\WP_Error $transport_result The value the stubbed transport returns.
	 * @param ?callable        $capture          Optional callback for the request arguments and body.
	 * @return SessionVerifier
	 */
	private function a_session_verifier_with_transport( $transport_result, ?callable $capture = null ): SessionVerifier {
		$api_client = $this->getMockBuilder( ApiClient::class )
			->onlyMethods( array( 'jetpack_remote_request' ) )
			->getMock();

		$api_client->method( 'jetpack_remote_request' )->willReturnCallback(
			function ( array $request_args, string $body ) use ( $transport_result, $capture ) {
				if ( null !== $capture ) {
					$capture( $request_args, $body );
				}

				return $transport_result;
			}
		);

		$container = wc_get_container();
		$session_id_normalizer = $container->get( SessionIdNormalizer::class );
		$api_client->init( $container->get( VisitorIpResolver::class ), $session_id_normalizer );
		$verifier  = new SessionVerifier();
		$verifier->init(
			$container->get( SessionDataCollector::class ),
			$api_client,
			$container->get( DecisionHandler::class ),
			$container->get( PaymentDataResolver::class ),
			$session_id_normalizer
		);

		return $verifier;
	}

	/**
	 * @testdox A block decision suppressed by learning mode is recorded as received block with final status allowed, with its risk score.
	 */
	public function test_suppressed_block_decision_is_recorded(): void {
		$verifier = $this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-1',
				'decision'   => 'block',
				'risk_score' => 0.87,
			)
		);

		$decision = $verifier->verify_session( 'integration-session-1', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Allow, $decision, 'Learning mode (default) should suppress the block' );

		$row = $this->latest_row_for( 'integration-session-1' );
		$this->assertNotNull( $row, 'The decision should have been recorded in the sessions table' );
		$this->assertSame( 'block', $row['decision'] );
		$this->assertSame( 'allowed', $row['final_status'] );
		$this->assertSame( 'blackbox', $row['trigger_type'] );
		$this->assertSame( 'blocks_checkout', $row['source'] );
		$this->assertSame( 0.87, (float) $row['risk_score'] );
	}

	/**
	 * @testdox An enforced block decision is recorded as blocked.
	 */
	public function test_enforced_block_decision_is_recorded_as_blocked(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$verifier = $this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-2',
				'decision'   => 'block',
			)
		);

		$decision = $verifier->verify_session( 'integration-session-2', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Block, $decision );

		$row = $this->latest_row_for( 'integration-session-2' );
		$this->assertNotNull( $row );
		$this->assertSame( 'blocked', $row['final_status'] );
		$this->assertNull( $row['risk_score'], 'No risk score in the response should record as null' );
	}

	/**
	 * @testdox A throwing automated-decision filter preserves, records, and persists an enforced Block.
	 */
	public function test_automated_filter_error_preserves_enforced_block_and_records_it(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );
		add_filter(
			'woocommerce_fraud_protection_automated_decision',
			function () {
				throw new \RuntimeException( 'Broken decision filter' );
			}
		);

		$verifier = $this->a_session_verifier_receiving(
			array(
				'decision'   => 'block',
				'session_id' => 'integration-filter-response-session',
			)
		);

		$decision = $verifier->verify_session( 'integration-filter-request-session', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Block, $decision );

		$row = $this->latest_row_for( 'integration-filter-response-session' );
		$this->assertNotNull( $row );
		$this->assertSame( 'block', $row['decision'] );
		$this->assertSame( 'blocked', $row['final_status'] );
		$this->assertSame( 'integration-filter-response-session', $verifier->last_verified_session_id() );
		$this->assertSame( 'integration-filter-response-session', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
		$this->assertLogged(
			'warning',
			'Filter `woocommerce_fraud_protection_automated_decision` threw. Using the decision that entered the filter.',
			array(
				'filter'            => 'woocommerce_fraud_protection_automated_decision',
				'decision_received' => 'block',
			),
			true
		);
	}

	/**
	 * @testdox An allow decision is recorded as allowed.
	 */
	public function test_allow_decision_is_recorded_as_allowed(): void {
		$verifier = $this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-3',
				'decision'   => 'allow',
				'risk_score' => 0.02,
			)
		);

		$verifier->verify_session( 'integration-session-3', 'blocks_checkout' );

		$row = $this->latest_row_for( 'integration-session-3' );
		$this->assertNotNull( $row, 'Allowed sessions must be recorded too' );
		$this->assertSame( 'allow', $row['decision'] );
		$this->assertSame( 'allowed', $row['final_status'] );
		$this->assertSame( 0.02, (float) $row['risk_score'] );
	}

	/**
	 * @testdox A verify that fails open on a transport error is recorded under the verify_error trigger.
	 */
	public function test_failed_verify_is_recorded_with_verify_error_trigger(): void {
		$verifier = $this->a_session_verifier_with_transport( new \WP_Error( 'http_error', 'Connection timeout' ) );

		$decision = $verifier->verify_session( 'integration-session-5', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Allow, $decision, 'A failed verify must fail open to allow' );

		$row = $this->latest_row_without_session_id();
		$this->assertNotNull( $row, 'Fail-open verifies must be recorded so unverified sessions stay visible' );
		$this->assertSame( 'allow', $row['decision'] );
		$this->assertSame( 'allowed', $row['final_status'] );
		$this->assertSame( 'verify_error', $row['trigger_type'], 'The synthetic allow must be distinguishable from a genuine Blackbox allow' );
		$this->assertNull( $row['risk_score'] );
	}

	/**
	 * @testdox A rejected verify is recorded as a received block and learning mode allows it.
	 */
	public function test_rejected_verify_is_recorded_and_suppressed_by_learning_mode(): void {
		$verifier = $this->a_session_verifier_with_transport(
			array(
				'response' => array( 'code' => 413 ),
				'body'     => 'Request rejected',
			)
		);

		$decision = $verifier->verify_session( 'integration-rejected-request', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Allow, $decision );
		$row = $this->latest_row_without_session_id();
		$this->assertNotNull( $row );
		$this->assertSame( 'block', $row['decision'] );
		$this->assertSame( 'allowed', $row['final_status'] );
		$this->assertSame( 'request_rejected', $row['trigger_type'] );
		$this->assertSame( '', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
	}

	/**
	 * @testdox An enforced rejected verify is recorded as blocked.
	 */
	public function test_rejected_verify_is_recorded_as_blocked_in_enforcement_mode(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );
		$verifier = $this->a_session_verifier_with_transport(
			array(
				'response' => array( 'code' => 413 ),
				'body'     => 'Request rejected',
			)
		);

		$decision = $verifier->verify_session( 'integration-rejected-request', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Block, $decision );
		$row = $this->latest_row_without_session_id();
		$this->assertNotNull( $row );
		$this->assertSame( 'block', $row['decision'] );
		$this->assertSame( 'blocked', $row['final_status'] );
		$this->assertSame( 'request_rejected', $row['trigger_type'] );
	}

	/**
	 * @testdox Each verify event of a repeated session ID gets its own row.
	 */
	public function test_repeated_verifies_record_one_row_each(): void {
		$this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-4',
				'decision'   => 'allow',
			)
		)
			->verify_session( 'integration-session-4', 'blocks_checkout' );
		$this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-4',
				'decision'   => 'block',
			)
		)
			->verify_session( 'integration-session-4', 'blocks_checkout' );

		$this->assertSame( 2, $this->count_rows() );
		$this->assertSame( 'block', $this->latest_row_for( 'integration-session-4' )['decision'] );
	}

	/**
	 * @testdox A valid decision without a response ID records SQL NULL and does not trust the request ID
	 */
	public function test_valid_decision_without_response_id_records_null(): void {
		$verifier = $this->a_session_verifier_receiving( array( 'decision' => 'allow' ) );

		$this->assertSame( FraudDecision::Allow, $verifier->verify_session( 'submitted-id', 'blocks_checkout' ) );
		$this->assertNull( $this->latest_row_for( 'submitted-id' ) );
		$this->assertNotNull( $this->latest_row_without_session_id() );
	}

	/**
	 * @testdox An echoed invalid-string marker records SQL NULL and creates no WC association
	 *
	 * @dataProvider invalid_string_marker_provider
	 *
	 * @param string $submitted Submitted invalid string.
	 * @param string $marker    Reserved marker returned by the API.
	 */
	public function test_echoed_invalid_string_marker_records_null( string $submitted, string $marker ): void {
		$verifier = $this->a_session_verifier_receiving(
			array(
				'session_id' => $marker,
				'decision'   => 'block',
			)
		);

		$verifier->verify_session( $submitted, 'blocks_checkout' );

		$this->assertNull( $this->latest_row_for( $marker ) );
		$this->assertNotNull( $this->latest_row_without_session_id() );
		$this->assertSame( '', WC()->session->get( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY ) );
	}

	/**
	 * Invalid strings and their reserved request markers.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function invalid_string_marker_provider(): array {
		return array(
			'dot segment'        => array( '.', 'wcfp-invalid-characters' ),
			'double-dot segment' => array( '..', 'wcfp-invalid-characters' ),
			'control byte'       => array( "a\x00b", 'wcfp-invalid-characters' ),
		);
	}

	/**
	 * Create a merchant rule matching the test visitor IP.
	 *
	 * @param FraudDecision $action The rule action.
	 * @return \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule
	 */
	private function a_rule_matching_the_visitor_ip( FraudDecision $action ) {
		$this->set_server_variables( array( 'REMOTE_ADDR' => '203.0.113.7' ) );

		return wc_get_container()->get( RuleStore::class )->create_rule(
			$action,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '203.0.113.7',
			)
		);
	}

	/**
	 * @testdox A merchant block rule enforces even in learning mode and records a block_rule row with the rule id.
	 */
	public function test_merchant_block_rule_enforces_and_records_block_rule_trigger(): void {
		$rule = $this->a_rule_matching_the_visitor_ip( FraudDecision::Block );
		$this->set_server_variables(
			array(
				'HTTP_X_REAL_IP'       => '198.51.100.1',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.2',
			)
		);
		$captured_body = null;

		$verifier = $this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-6',
				'decision'   => 'allow',
				'risk_score' => 0.13,
			),
			function ( array $request_args, string $body ) use ( &$captured_body ) {
				$captured_body = json_decode( $body, true );
			}
		);

		$decision = $verifier->verify_session( 'integration-session-6', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Block, $decision, 'The merchant block rule must enforce even in learning mode' );

		$row = $this->latest_row_for( 'integration-session-6' );
		$this->assertNotNull( $row );
		$this->assertSame( 'allow', $row['decision'], 'The Blackbox verdict must be recorded as received' );
		$this->assertSame( 'blocked', $row['final_status'] );
		$this->assertSame( 'block_rule', $row['trigger_type'] );
		$this->assertSame( $rule->id, (int) $row['matched_rule_id'] );
		$this->assertSame( '203.0.113.7', $captured_body['visitor_ip'] );
		$this->assertSame( '203.0.113.7', $row['ip'] );
	}

	/**
	 * @testdox A merchant allow rule overrides a Blackbox block and records an allow_rule row with the rule id.
	 */
	public function test_merchant_allow_rule_overrides_block_and_records_allow_rule_trigger(): void {
		add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );

		$rule = $this->a_rule_matching_the_visitor_ip( FraudDecision::Allow );

		$verifier = $this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-7',
				'decision'   => 'block',
				'risk_score' => 0.99,
			)
		);

		$decision = $verifier->verify_session( 'integration-session-7', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Allow, $decision, 'The merchant allow rule must override the Blackbox block' );

		$row = $this->latest_row_for( 'integration-session-7' );
		$this->assertNotNull( $row );
		$this->assertSame( 'block', $row['decision'], 'The Blackbox verdict must be recorded as received' );
		$this->assertSame( 'allowed', $row['final_status'] );
		$this->assertSame( 'allow_rule', $row['trigger_type'] );
		$this->assertSame( $rule->id, (int) $row['matched_rule_id'] );
	}

	/**
	 * @testdox A session matching no merchant rule keeps the blackbox trigger and a null matched rule id.
	 */
	public function test_unmatched_session_records_null_matched_rule_id(): void {
		$this->set_server_variables(
			array(
				'REMOTE_ADDR'    => '203.0.113.7',
				'HTTP_X_REAL_IP' => '198.51.100.1',
			)
		);

		wc_get_container()->get( RuleStore::class )->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '198.51.100.1',
			)
		);

		$verifier = $this->a_session_verifier_receiving(
			array(
				'session_id' => 'integration-session-8',
				'decision'   => 'allow',
			)
		);
		$verifier->verify_session( 'integration-session-8', 'blocks_checkout' );

		$row = $this->latest_row_for( 'integration-session-8' );
		$this->assertNotNull( $row );
		$this->assertSame( 'blackbox', $row['trigger_type'] );
		$this->assertNull( $row['matched_rule_id'] );
		$this->assertSame( '203.0.113.7', $row['ip'] );
	}
}
