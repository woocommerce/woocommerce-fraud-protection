<?php
/**
 * SessionEventStoreTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the SessionEventStore class.
 */
class SessionEventStoreTest extends FraudProtectionUnitTestCase {

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
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ) );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->schema_manager->get_sessions_table_name() );
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
		$this->sut->record_event( $this->an_event( array( 'session_id' => '' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => '' ) ) );

		$this->assertSame( 2, $this->count_rows() );
	}

	/**
	 * @testdox Should prune only the rows older than the retention period.
	 */
	public function test_prunes_only_old_rows(): void {
		global $wpdb;

		$this->sut->record_event( $this->an_event( array( 'session_id' => 'old-session' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => 'fresh-session' ) ) );

		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 40 * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->schema_manager->get_sessions_table_name() . ' SET recorded_at = %s WHERE session_id = %s', $old_date, 'old-session' ) );

		$deleted = $this->sut->prune_older_than( 30 );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->latest_row_for( 'old-session' ), 'The old row should have been pruned' );
		$this->assertNotNull( $this->latest_row_for( 'fresh-session' ), 'The fresh row should have been kept' );
	}
}
