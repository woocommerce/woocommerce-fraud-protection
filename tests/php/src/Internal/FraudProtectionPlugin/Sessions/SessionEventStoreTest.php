<?php
/**
 * SessionEventStoreTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionOutcome;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the SessionEventStore class.
 */
class SessionEventStoreTest extends FraudProtectionUnitTestCase {

	/**
	 * Transient holding performance outcome counts.
	 */
	private const PERFORMANCE_COUNTS_TRANSIENT = 'wc_fraud_protection_performance_counts';

	/**
	 * Transient holding Tracker-only session counts.
	 */
	private const TRACKER_COUNTS_TRANSIENT = 'wc_fraud_protection_tracker_counts';

	/**
	 * The System Under Test.
	 *
	 * @var SessionEventStore
	 */
	private $sut;

	/**
	 * Schema manager used to create the table.
	 *
	 * @var SchemaManager
	 */
	private $schema_manager;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( FraudProtectionLogger::class ) );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_sessions_table_schema() );

		$this->sut = new SessionEventStore();
		$this->sut->init( $this->schema_manager );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;

		delete_transient( self::PERFORMANCE_COUNTS_TRANSIENT );
		delete_transient( self::TRACKER_COUNTS_TRANSIENT );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_sessions_table_name() );
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

		$table = $this->schema_manager->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->schema_manager->get_sessions_table_name() );
	}

	/**
	 * Set the recording time for a stored session event.
	 *
	 * @param string $session_id Session ID to update.
	 * @param string $recorded_at UTC database timestamp.
	 */
	private function set_recorded_at( string $session_id, string $recorded_at ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->schema_manager->get_sessions_table_name() . ' SET recorded_at = %s WHERE session_id = %s', $recorded_at, $session_id ) );
	}

	/**
	 * A complete event row with overridable fields.
	 *
	 * @param array $overrides Fields to override.
	 * @return array
	 */
	private function an_event( array $overrides = array() ): array {
		return array_merge(
			array(
				'session_id'       => 'session-abc',
				'source'           => 'blocks_checkout',
				'decision'         => 'block',
				'final_status'     => 'allowed',
				'trigger_type'     => 'blackbox',
				'risk_score'       => 0.91,
				'email'            => 'customer@example.com',
				'ip'               => '203.0.113.9',
				'ip_country'       => 'ES',
				'billing_country'  => 'US',
				'billing_state'    => 'CA',
				'billing_city'     => 'San Francisco',
				'billing_postcode' => '94110',
				'billing_name'     => 'Jane Doe',
				'order_id'         => 123,
				'payment_method'   => 'woocommerce_payments',
			),
			$overrides
		);
	}

	/**
	 * @testdox Should insert a new row with the event data and a recording timestamp.
	 */
	public function test_records_new_event(): void {
		$result = $this->sut->record_event( $this->an_event() );

		$this->assertTrue( $result );
		$row = $this->latest_row_for( 'session-abc' );
		$this->assertNotNull( $row, 'The recorded row should be retrievable by session ID' );
		$this->assertNotEmpty( $row['recorded_at'] );
		$this->assertSame( 'block', $row['decision'] );
		$this->assertSame( 'allowed', $row['final_status'] );
		$this->assertSame( 'blackbox', $row['trigger_type'] );
		$this->assertSame( 0.91, (float) $row['risk_score'] );
		$this->assertSame( 'customer@example.com', $row['email'] );
		$this->assertSame( '123', (string) $row['order_id'] );
	}

	/**
	 * @testdox Ordering by payment method follows the provided provider sequence (used to sort by display title).
	 */
	public function test_query_events_orders_by_provided_payment_method_sequence(): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 's-alpha',
					'payment_method' => 'alpha',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 's-beta',
					'payment_method' => 'beta',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 's-gamma',
					'payment_method' => 'gamma',
				)
			)
		);

		$result = $this->sut->query_events(
			array(
				'orderby'              => 'payment_method',
				'payment_method_order' => array( 'gamma', 'alpha', 'beta' ),
			)
		);

		$this->assertSame(
			array( 'gamma', 'alpha', 'beta' ),
			array_column( $result['items'], 'payment_method' )
		);
	}

	/**
	 * @testdox Should read only the contextual fields for a recorded event ID.
	 */
	public function test_get_event_returns_contextual_fields_by_id(): void {
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'context-session' ) ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$event_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . $this->schema_manager->get_sessions_table_name() );

		$this->assertSame(
			array(
				'id'           => $event_id,
				'session_id'   => 'context-session',
				'final_status' => 'allowed',
				'email'        => 'customer@example.com',
				'ip'           => '203.0.113.9',
			),
			$this->sut->get_event( $event_id )
		);
		$this->assertNull( $this->sut->get_event( $event_id + 1 ) );
	}

	/**
	 * @testdox Should insert a separate row for each event with the same session ID, preserving both decisions.
	 */
	public function test_repeated_session_ids_insert_separate_rows(): void {
		$this->sut->record_event( $this->an_event( array( 'decision' => 'allow' ) ) );
		$this->sut->record_event(
			$this->an_event(
				array(
					'decision'     => 'block',
					'final_status' => 'blocked',
				)
			)
		);

		$this->assertSame( 2, $this->count_rows(), 'Repeated session IDs must keep one row per event' );
		$latest = $this->latest_row_for( 'session-abc' );
		$this->assertSame( 'block', $latest['decision'], 'The latest row should carry the latest decision' );
		$this->assertSame( 'blocked', $latest['final_status'] );
	}

	/**
	 * @testdox Should record null when the event has no risk score or order ID.
	 */
	public function test_records_null_risk_score_and_order_id(): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'risk_score' => null,
					'order_id'   => 0,
				)
			)
		);

		$row = $this->latest_row_for( 'session-abc' );
		$this->assertNull( $row['risk_score'] );
		$this->assertNull( $row['order_id'] );
	}

	/**
	 * @testdox Should record the matched rule id, and null when the event carries none.
	 */
	public function test_records_matched_rule_id(): void {
		$this->sut->record_event( $this->an_event( array( 'matched_rule_id' => 42 ) ) );
		$this->assertSame( '42', (string) $this->latest_row_for( 'session-abc' )['matched_rule_id'] );

		$this->sut->record_event( $this->an_event() );
		$this->assertNull( $this->latest_row_for( 'session-abc' )['matched_rule_id'], 'An event without a matched rule must record NULL' );
	}

	/**
	 * @testdox Should insert separate rows for events with no session ID.
	 */
	public function test_events_without_session_id_insert_separate_rows(): void {
		global $wpdb;

		$this->sut->record_event( $this->an_event( array( 'session_id' => '' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => '' ) ) );

		$this->assertSame( 2, $this->count_rows() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( 2, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->schema_manager->get_sessions_table_name() . ' WHERE session_id IS NULL' ) );
	}

	/**
	 * @testdox Should return zeroes when no performance outcomes were recorded.
	 */
	public function test_performance_counts_return_zeroes_without_events(): void {
		$expected = array(
			'flagged_by_fraud_prevention' => 0,
			'blocked_automatically'       => 0,
			'allowed_by_rules'            => 0,
			'blocked_by_rules'            => 0,
		);

		$this->assertSame(
			$expected,
			$this->sut->get_performance_counts()
		);
		$this->assertSame( $expected, get_transient( self::PERFORMANCE_COUNTS_TRANSIENT ) );
	}

	/**
	 * @testdox Should return cached performance counts without querying the database.
	 */
	public function test_performance_counts_return_cached_values(): void {
		global $wpdb;

		$cached_counts = array(
			'flagged_by_fraud_prevention' => 12,
			'blocked_automatically'       => 3,
			'allowed_by_rules'            => 4,
			'blocked_by_rules'            => 5,
			'unapproved_value'            => 6,
		);
		$expected      = array_diff_key( $cached_counts, array( 'unapproved_value' => true ) );
		set_transient( self::PERFORMANCE_COUNTS_TRANSIENT, $cached_counts, 5 * MINUTE_IN_SECONDS );

		$original_wpdb = $wpdb;
		$wpdb          = $this->createMock( \wpdb::class ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Verify that a cache hit skips the database.
		$wpdb->expects( $this->never() )->method( 'prepare' );
		$wpdb->expects( $this->never() )->method( 'get_row' );

		try {
			$this->assertSame( $expected, $this->sut->get_performance_counts() );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test database.
		}
	}

	/**
	 * @testdox Should replace invalid cached performance counts.
	 */
	public function test_performance_counts_replace_invalid_cached_values(): void {
		set_transient(
			self::PERFORMANCE_COUNTS_TRANSIENT,
			array(
				'flagged_by_fraud_prevention' => '12',
			),
			5 * MINUTE_IN_SECONDS
		);

		$expected = array(
			'flagged_by_fraud_prevention' => 0,
			'blocked_automatically'       => 0,
			'allowed_by_rules'            => 0,
			'blocked_by_rules'            => 0,
		);

		$this->assertSame( $expected, $this->sut->get_performance_counts() );
		$this->assertSame( $expected, get_transient( self::PERFORMANCE_COUNTS_TRANSIENT ) );
	}

	/**
	 * @testdox Should use the checkout-attempt outcome definitions for performance counts.
	 */
	public function test_performance_counts_map_all_supported_outcomes(): void {
		$events = array(
			array(
				'session_id'     => 'recommended-blackbox',
				'source'         => 'blocks_checkout',
				'payment_method' => 'woocommerce_payments',
			),
			array(
				'session_id'     => 'recommended-rejected',
				'source'         => 'paypal_setup_token',
				'payment_method' => 'ppcp-gateway',
				'trigger_type'   => 'request_rejected',
			),
			array(
				'session_id'     => 'blocked-blackbox',
				'source'         => 'shortcode_checkout',
				'payment_method' => 'stripe',
				'final_status'   => 'blocked',
			),
			array(
				'session_id'     => 'blocked-rejected',
				'source'         => 'change_payment_method',
				'payment_method' => 'ppcp-gateway',
				'trigger_type'   => 'request_rejected',
				'final_status'   => 'blocked',
			),
			array(
				'session_id'     => 'allowed-rule',
				'source'         => 'add_payment_method',
				'payment_method' => 'bacs',
				'decision'       => 'allow',
				'trigger_type'   => 'allow_rule',
			),
			array(
				'session_id'     => 'blocked-rule',
				'source'         => 'pay_for_order',
				'payment_method' => 'cod',
				'final_status'   => 'blocked',
				'trigger_type'   => 'block_rule',
			),
			array(
				'session_id' => 'normal-allow',
				'decision'   => 'allow',
			),
			array(
				'session_id'   => 'verify-error',
				'decision'     => 'allow',
				'trigger_type' => 'verify_error',
			),
			array(
				'session_id' => 'challenge',
				'decision'   => 'challenge',
			),
			array(
				'session_id'   => 'unmatched-status',
				'final_status' => 'challenge',
			),
			array( 'session_id' => 'too-old' ),
		);

		foreach ( $events as $event ) {
			$this->assertTrue( $this->sut->record_event( $this->an_event( $event ) ) );
		}
		$this->set_recorded_at( 'too-old', gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) ) );

		$this->assertSame(
			array(
				'flagged_by_fraud_prevention' => 3,
				'blocked_automatically'       => 2,
				'allowed_by_rules'            => 1,
				'blocked_by_rules'            => 1,
			),
			$this->sut->get_performance_counts()
		);
	}

	/**
	 * @testdox Should throw when the performance aggregate query fails.
	 */
	public function test_performance_counts_throw_on_database_failure(): void {
		global $wpdb;

		$this->assertFalse( get_transient( self::PERFORMANCE_COUNTS_TRANSIENT ) );

		$original_wpdb = $wpdb;
		$wpdb          = $this->createMock( \wpdb::class ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Direct database failure boundary.
		$wpdb->method( 'prepare' )->willReturn( 'SELECT failed' );
		$wpdb->method( 'get_row' )->willReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Session event performance query failed.' );

		try {
			$this->sut->get_performance_counts();
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test database.
			$this->assertFalse( get_transient( self::PERFORMANCE_COUNTS_TRANSIENT ) );
		}
	}

	/**
	 * @testdox Should count applied automatic blocks in cumulative recent windows.
	 */
	public function test_automatic_block_counts_use_cumulative_windows(): void {
		global $wpdb;

		$events = array(
			array( 'session_id' => 'block-1d' ),
			array(
				'session_id'   => 'rejected-block-7d',
				'trigger_type' => 'request_rejected',
			),
			array( 'session_id' => 'block-30d' ),
			array( 'session_id' => 'block-too-old' ),
			array(
				'session_id'   => 'suppressed-block',
				'final_status' => 'allowed',
			),
			array(
				'session_id' => 'automatic-allow',
				'decision'   => 'allow',
			),
			array(
				'session_id'   => 'rule-block',
				'trigger_type' => 'block_rule',
			),
		);

		foreach ( $events as $event ) {
			$this->assertTrue( $this->sut->record_event( $this->an_event( array_merge( array( 'final_status' => 'blocked' ), $event ) ) ) );
		}
		$this->set_recorded_at( 'rejected-block-7d', gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) ) );
		$this->set_recorded_at( 'block-30d', gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ) );
		$this->set_recorded_at( 'block-too-old', gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) ) );

		$queries_before = $wpdb->num_queries;
		$result         = $this->sut->get_automatic_block_counts();

		$this->assertSame( 1, $wpdb->num_queries - $queries_before, 'Automatic block counts must use one query' );
		$this->assertSame(
			array(
				'automatic_blocks_applied_1d'  => 1,
				'automatic_blocks_applied_7d'  => 2,
				'automatic_blocks_applied_30d' => 3,
			),
			$result
		);
	}

	/**
	 * @testdox Should return zero automatic-block counts when no sessions exist.
	 */
	public function test_automatic_block_counts_return_zeroes_without_sessions(): void {
		$this->assertSame(
			array(
				'automatic_blocks_applied_1d'  => 0,
				'automatic_blocks_applied_7d'  => 0,
				'automatic_blocks_applied_30d' => 0,
			),
			$this->sut->get_automatic_block_counts()
		);
	}

	/**
	 * @testdox Should map the separate Tracker-only counts without changing performance counts.
	 */
	public function test_tracker_counts_map_supported_outcomes(): void {
		$events = array(
			array(
				'session_id'   => 'automatic-allow',
				'decision'     => 'allow',
				'final_status' => 'allowed',
			),
			array(
				'session_id'   => 'automatic-allow-blocked',
				'decision'     => 'allow',
				'final_status' => 'blocked',
			),
			array(
				'session_id'   => 'rule-allow',
				'decision'     => 'allow',
				'final_status' => 'allowed',
				'trigger_type' => 'allow_rule',
			),
			array(
				'session_id'   => 'verify-error',
				'decision'     => 'allow',
				'final_status' => 'allowed',
				'trigger_type' => 'verify_error',
			),
			array(
				'session_id'   => 'request-rejected',
				'final_status' => 'blocked',
				'trigger_type' => 'request_rejected',
			),
			array(
				'session_id'   => 'automatic-block',
				'final_status' => 'blocked',
			),
			array(
				'session_id'   => 'too-old',
				'decision'     => 'allow',
				'final_status' => 'allowed',
			),
		);

		foreach ( $events as $event ) {
			$this->assertTrue( $this->sut->record_event( $this->an_event( $event ) ) );
		}
		$this->set_recorded_at( 'too-old', gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) ) );

		$expected = array(
			'sessions_total_30d'           => 6,
			'automatic_allows_applied_30d' => 1,
			'verify_errors_30d'            => 1,
			'requests_rejected_30d'        => 1,
		);

		$this->assertSame( $expected, $this->sut->get_tracker_counts() );
		$this->assertSame( $expected, get_transient( self::TRACKER_COUNTS_TRANSIENT ) );
	}

	/**
	 * @testdox Should return cached Tracker counts without querying the database.
	 */
	public function test_tracker_counts_return_cached_values(): void {
		global $wpdb;

		$cached_counts = array(
			'sessions_total_30d'           => 31,
			'automatic_allows_applied_30d' => 32,
			'verify_errors_30d'            => 33,
			'requests_rejected_30d'        => 34,
			'unapproved_value'             => 35,
		);
		$expected      = array_diff_key( $cached_counts, array( 'unapproved_value' => true ) );
		set_transient( self::TRACKER_COUNTS_TRANSIENT, $cached_counts, 5 * MINUTE_IN_SECONDS );

		$original_wpdb = $wpdb;
		$wpdb          = $this->createMock( \wpdb::class ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Verify that a cache hit skips the database.
		$wpdb->expects( $this->never() )->method( 'prepare' );
		$wpdb->expects( $this->never() )->method( 'get_row' );

		try {
			$this->assertSame( $expected, $this->sut->get_tracker_counts() );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test database.
		}
	}

	/**
	 * @testdox Should replace invalid Tracker cache data and return only approved counters.
	 */
	public function test_tracker_counts_replace_invalid_cached_values(): void {
		set_transient(
			self::TRACKER_COUNTS_TRANSIENT,
			array(
				'sessions_total_30d'           => -1,
				'automatic_allows_applied_30d' => 2,
				'verify_errors_30d'            => 3,
				'requests_rejected_30d'        => 4,
				'unapproved_value'             => 'remove-me',
			),
			5 * MINUTE_IN_SECONDS
		);

		$expected = array(
			'sessions_total_30d'           => 0,
			'automatic_allows_applied_30d' => 0,
			'verify_errors_30d'            => 0,
			'requests_rejected_30d'        => 0,
		);

		$this->assertSame( $expected, $this->sut->get_tracker_counts() );
		$this->assertSame( $expected, get_transient( self::TRACKER_COUNTS_TRANSIENT ) );
	}

	/**
	 * @testdox Should throw when a new session aggregate query fails.
	 *
	 * @dataProvider failed_session_aggregate_provider
	 *
	 * @param string $method  Aggregate method.
	 * @param string $message Expected exception message.
	 */
	public function test_new_aggregates_throw_on_database_failure( string $method, string $message ): void {
		global $wpdb;

		delete_transient( self::TRACKER_COUNTS_TRANSIENT );
		$this->assertFalse( get_transient( self::TRACKER_COUNTS_TRANSIENT ) );
		$original_wpdb = $wpdb;
		$wpdb          = $this->createMock( \wpdb::class ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Direct database failure boundary.
		$wpdb->method( 'prepare' )->willReturn( 'SELECT failed' );
		$wpdb->expects( $this->once() )->method( 'get_row' )->willReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( $message );

		try {
			$this->sut->{$method}();
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test database.
		}
	}

	/**
	 * Provide failed session aggregate methods.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function failed_session_aggregate_provider(): array {
		return array(
			'automatic blocks' => array( 'get_automatic_block_counts', 'Automatic block count query failed.' ),
			'Tracker counts'   => array( 'get_tracker_counts', 'Session event Tracker query failed.' ),
		);
	}

	/**
	 * @testdox Should prune only the rows older than the retention period.
	 */
	public function test_prunes_only_old_rows(): void {
		global $wpdb;

		$this->sut->record_event( $this->an_event( array( 'session_id' => 'old-session' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'fresh-session' ) ) );

		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->schema_manager->get_sessions_table_name() . ' SET recorded_at = %s WHERE session_id = %s', $old_date, 'old-session' ) );

		$deleted = $this->sut->prune_older_than( 30 );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->latest_row_for( 'old-session' ), 'The old row should have been pruned' );
		$this->assertNotNull( $this->latest_row_for( 'fresh-session' ), 'The fresh row should have been kept' );
	}

	/**
	 * @testdox Should report the partial count when a later prune query fails.
	 */
	public function test_prune_throws_on_database_failure(): void {
		global $wpdb;

		$original_wpdb = $wpdb;
		$wpdb          = $this->createMock( \wpdb::class ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Direct database failure boundary.
		$wpdb->method( 'query' )->willReturnOnConsecutiveCalls( 1000, false );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Session event pruning failed after deleting 1000 row(s).' );

		try {
			$this->sut->prune_older_than( 30 );
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test database.
		}
	}

	/**
	 * Record an event with the columns that decide a given outcome.
	 *
	 * @param string $session_id   The session ID.
	 * @param string $trigger_type The trigger_type column.
	 * @param string $final_status The final_status column.
	 * @param string $decision     The decision column.
	 */
	private function record_outcome( string $session_id, string $trigger_type, string $final_status, string $decision ): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'   => $session_id,
					'trigger_type' => $trigger_type,
					'final_status' => $final_status,
					'decision'     => $decision,
				)
			)
		);
	}

	/**
	 * @testdox Should return matching rows newest first with the total and typed ids.
	 */
	public function test_query_events_orders_newest_first_and_paginates(): void {
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'old' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'mid' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'new' ) ) );
		$this->set_recorded_at( 'old', gmdate( 'Y-m-d H:i:s', time() - 3000 ) );
		$this->set_recorded_at( 'mid', gmdate( 'Y-m-d H:i:s', time() - 2000 ) );
		$this->set_recorded_at( 'new', gmdate( 'Y-m-d H:i:s', time() - 1000 ) );

		$result = $this->sut->query_events( array( 'per_page' => 2 ) );

		$this->assertSame( 3, $result['total'] );
		$this->assertCount( 2, $result['items'] );
		$this->assertSame( array( 'new', 'mid' ), array_column( $result['items'], 'session_id' ) );
		$this->assertIsInt( $result['items'][0]['id'] );
		$this->assertIsInt( $result['items'][0]['order_id'] );

		$page_two = $this->sut->query_events(
			array(
				'per_page' => 2,
				'page'     => 2,
			)
		);
		$this->assertSame( array( 'old' ), array_column( $page_two['items'], 'session_id' ) );
	}

	/**
	 * @testdox Should not expose the risk score in the queried rows.
	 */
	public function test_query_events_omits_risk_score(): void {
		$this->sut->record_event( $this->an_event() );

		$result = $this->sut->query_events();

		$this->assertArrayNotHasKey( 'risk_score', $result['items'][0] );
	}

	/**
	 * @testdox Should filter by the enforced final status.
	 */
	public function test_query_events_filters_by_final_status(): void {
		$this->record_outcome( 'allowed-1', 'blackbox', 'allowed', 'allow' );
		$this->record_outcome( 'blocked-1', 'blackbox', 'blocked', 'block' );

		$result = $this->sut->query_events( array( 'final_status' => SessionFinalStatus::Blocked ) );

		$this->assertSame( array( 'blocked-1' ), array_column( $result['items'], 'session_id' ) );
	}

	/**
	 * @testdox Should filter by the derived merchant outcome.
	 */
	public function test_query_events_filters_by_outcome(): void {
		$this->record_outcome( 'recommended', 'blackbox', 'allowed', 'block' );
		$this->record_outcome( 'auto-blocked', 'blackbox', 'blocked', 'block' );
		$this->record_outcome( 'allowed', 'blackbox', 'allowed', 'allow' );

		$result = $this->sut->query_events(
			array(
				'outcomes' => array(
					SessionOutcome::FlaggedByFraudPrevention,
					SessionOutcome::BlockedAutomatically,
				),
			)
		);

		$this->assertSame(
			array( 'auto-blocked', 'recommended' ),
			array_column( $result['items'], 'session_id' )
		);
	}

	/**
	 * @testdox Should filter by payment method id.
	 */
	public function test_query_events_filters_by_payment_method(): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 'stripe-1',
					'payment_method' => 'stripe',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 'ppcp-1',
					'payment_method' => 'ppcp',
				)
			)
		);

		$result = $this->sut->query_events( array( 'payment_methods' => array( 'ppcp' ) ) );

		$this->assertSame( array( 'ppcp-1' ), array_column( $result['items'], 'session_id' ) );
	}

	/**
	 * @testdox Should search the email and IP columns.
	 */
	public function test_query_events_searches_email_and_ip(): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'by-email',
					'email'      => 'fraudster@example.com',
					'ip'         => '198.51.100.1',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'by-ip',
					'email'      => 'buyer@example.com',
					'ip'         => '203.0.113.42',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'no-match',
					'email'      => 'buyer@example.com',
					'ip'         => '10.0.0.1',
				)
			)
		);

		$this->assertSame( array( 'by-email' ), array_column( $this->sut->query_events( array( 'search' => 'fraudster' ) )['items'], 'session_id' ) );
		$this->assertSame( array( 'by-ip' ), array_column( $this->sut->query_events( array( 'search' => '203.0.113' ) )['items'], 'session_id' ) );
	}

	/**
	 * The event set and rule values shared by the rule-filter tests.
	 */
	private function seed_rule_filter_events(): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'ruled-email',
					'email'      => 'Blocked@Example.com',
					'ip'         => '203.0.113.1',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'ruled-ip',
					'email'      => 'buyer@example.com',
					'ip'         => '198.51.100.10',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'clean',
					'email'      => 'clean@example.com',
					'ip'         => '203.0.113.2',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'no-values',
					'email'      => '',
					'ip'         => '',
				)
			)
		);
	}

	/**
	 * @return array{email: string[], ip: string[]}
	 */
	private function rule_filter_values(): array {
		return array(
			// The email value is normalized (lowercased), matching a stored mixed-case address.
			'email' => array( 'blocked@example.com' ),
			'ip'    => array( '198.51.100.10' ),
		);
	}

	/**
	 * @testdox Should exclude attempts whose email or IP an active rule targets.
	 */
	public function test_query_events_filters_without_rules(): void {
		$this->seed_rule_filter_events();

		$result = $this->sut->query_events(
			array(
				'rules'       => 'without',
				'rule_values' => $this->rule_filter_values(),
			)
		);

		$sessions = array_column( $result['items'], 'session_id' );
		sort( $sessions );
		$this->assertSame( array( 'clean', 'no-values' ), $sessions );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * @testdox Should keep only attempts whose email or IP an active rule targets.
	 */
	public function test_query_events_filters_with_rules(): void {
		$this->seed_rule_filter_events();

		$result = $this->sut->query_events(
			array(
				'rules'       => 'with',
				'rule_values' => $this->rule_filter_values(),
			)
		);

		$sessions = array_column( $result['items'], 'session_id' );
		sort( $sessions );
		$this->assertSame( array( 'ruled-email', 'ruled-ip' ), $sessions );
		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * @testdox Should return every attempt for "without" when no rule values exist.
	 */
	public function test_query_events_without_rules_with_no_values_returns_all(): void {
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'a' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'b' ) ) );

		$result = $this->sut->query_events(
			array(
				'rules'       => 'without',
				'rule_values' => array(),
			)
		);

		$this->assertSame( 2, $result['total'] );
	}

	/**
	 * @testdox Should return no attempt for "with" when no rule values exist.
	 */
	public function test_query_events_with_rules_with_no_values_returns_none(): void {
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'a' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'b' ) ) );

		$result = $this->sut->query_events(
			array(
				'rules'       => 'with',
				'rule_values' => array(),
			)
		);

		$this->assertSame( 0, $result['total'] );
	}

	/**
	 * @testdox Should sort by an allowlisted column and direction.
	 */
	public function test_query_events_sorts_by_column(): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'c',
					'email'      => 'charlie@example.com',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'a',
					'email'      => 'alice@example.com',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id' => 'b',
					'email'      => 'bob@example.com',
				)
			)
		);

		$result = $this->sut->query_events(
			array(
				'orderby' => 'email',
				'order'   => 'asc',
			)
		);

		$this->assertSame(
			array( 'alice@example.com', 'bob@example.com', 'charlie@example.com' ),
			array_column( $result['items'], 'email' )
		);
	}

	/**
	 * @testdox Should only return rows within the retention window.
	 */
	public function test_query_events_respects_days_window(): void {
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'fresh' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'stale' ) ) );
		$this->set_recorded_at( 'stale', gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) ) );

		$result = $this->sut->query_events( array( 'days' => 30 ) );

		$this->assertSame( array( 'fresh' ), array_column( $result['items'], 'session_id' ) );
	}

	/**
	 * @testdox Should list the distinct payment methods within the window, sorted.
	 */
	public function test_get_payment_methods_returns_distinct_sorted(): void {
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 's1',
					'payment_method' => 'stripe',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 's2',
					'payment_method' => 'ppcp',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 's3',
					'payment_method' => 'stripe',
				)
			)
		);
		$this->sut->record_event(
			$this->an_event(
				array(
					'session_id'     => 's4',
					'payment_method' => '',
				)
			)
		);

		$this->assertSame( array( 'ppcp', 'stripe' ), $this->sut->get_payment_methods( 30 ) );
	}
}
