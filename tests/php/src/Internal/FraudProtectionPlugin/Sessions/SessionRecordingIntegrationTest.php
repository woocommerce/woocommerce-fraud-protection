<?php
/**
 * SessionRecordingIntegrationTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\DecisionHandler;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PaymentDataResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Integration test for the session recording pipeline: a verify call whose
 * transport returns a block decision must produce a row in the sessions table,
 * exercising ApiClient parsing, DecisionHandler, SessionEventRecorder and
 * SessionEventStore together.
 */
class SessionRecordingIntegrationTest extends FraudProtectionUnitTestCase {

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$schema_manager = wc_get_container()->get( SchemaManager::class );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $schema_manager->get_sessions_table_schema() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . wc_get_container()->get( SchemaManager::class )->get_sessions_table_name() );
		delete_option( SchemaManager::DB_VERSION_OPTION );
		remove_all_filters( 'woocommerce_fraud_protection_learning_mode' );
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
	 * @param array $response_data The `data` object of the API response body.
	 * @return SessionVerifier
	 */
	private function a_session_verifier_receiving( array $response_data ): SessionVerifier {
		$api_client = $this->getMockBuilder( ApiClient::class )
			->onlyMethods( array( 'jetpack_remote_request' ) )
			->getMock();

		$api_client->method( 'jetpack_remote_request' )->willReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => wp_json_encode( array( 'data' => $response_data ) ),
			)
		);

		$container = wc_get_container();
		$verifier  = new SessionVerifier();
		$verifier->init(
			$container->get( SessionDataCollector::class ),
			$api_client,
			$container->get( DecisionHandler::class ),
			$container->get( PaymentDataResolver::class )
		);

		return $verifier;
	}

	/**
	 * @testdox A block decision suppressed by learning mode is recorded as received block with final status allowed, with its risk score.
	 */
	public function test_suppressed_block_decision_is_recorded(): void {
		$verifier = $this->a_session_verifier_receiving(
			array(
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

		$verifier = $this->a_session_verifier_receiving( array( 'decision' => 'block' ) );

		$decision = $verifier->verify_session( 'integration-session-2', 'blocks_checkout' );

		$this->assertSame( FraudDecision::Block, $decision );

		$row = $this->latest_row_for( 'integration-session-2' );
		$this->assertNotNull( $row );
		$this->assertSame( 'blocked', $row['final_status'] );
		$this->assertNull( $row['risk_score'], 'No risk score in the response should record as null' );
	}

	/**
	 * @testdox An allow decision is recorded as allowed.
	 */
	public function test_allow_decision_is_recorded_as_allowed(): void {
		$verifier = $this->a_session_verifier_receiving(
			array(
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
	 * @testdox Each verify event of a repeated session ID gets its own row.
	 */
	public function test_repeated_verifies_record_one_row_each(): void {
		$this->a_session_verifier_receiving( array( 'decision' => 'allow' ) )
			->verify_session( 'integration-session-4', 'blocks_checkout' );
		$this->a_session_verifier_receiving( array( 'decision' => 'block' ) )
			->verify_session( 'integration-session-4', 'blocks_checkout' );

		$this->assertSame( 2, $this->count_rows() );
		$this->assertSame( 'block', $this->latest_row_for( 'integration-session-4' )['decision'] );
	}
}
