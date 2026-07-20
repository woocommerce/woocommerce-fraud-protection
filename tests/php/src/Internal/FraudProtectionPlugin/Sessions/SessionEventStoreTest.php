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
	 * @testdox Should insert a new row with attempts = 1 on first record.
	 */
	public function test_records_new_event(): void {
		$result = $this->sut->record_event( $this->an_event() );

		$this->assertTrue( $result );
		$row = $this->sut->get_by_session_id( 'session-abc' );
		$this->assertNotNull( $row, 'The recorded row should be retrievable by session ID' );
		$this->assertSame( '1', (string) $row['attempts'] );
		$this->assertSame( 'block', $row['decision'] );
		$this->assertSame( 'allowed', $row['final_status'] );
		$this->assertSame( 'blackbox', $row['trigger_type'] );
		$this->assertSame( 0.91, (float) $row['risk_score'] );
		$this->assertSame( 'customer@example.com', $row['email'] );
		$this->assertSame( '123', (string) $row['order_id'] );
	}

	/**
	 * @testdox Should upsert on a repeated session ID: attempts incremented, volatile fields updated, first_seen preserved.
	 */
	public function test_upserts_on_repeated_session_id(): void {
		$this->sut->record_event( $this->an_event() );
		$first = $this->sut->get_by_session_id( 'session-abc' );

		$this->sut->record_event( $this->an_event( array( 'final_status' => 'blocked' ) ) );
		$second = $this->sut->get_by_session_id( 'session-abc' );

		$this->assertSame( 1, $this->sut->count_events(), 'Repeated session IDs must not create new rows' );
		$this->assertSame( '2', (string) $second['attempts'] );
		$this->assertSame( 'blocked', $second['final_status'] );
		$this->assertSame( $first['first_seen'], $second['first_seen'], 'first_seen must be preserved on upsert' );
	}

	/**
	 * @testdox Should not overwrite an existing risk score or order ID with null values on upsert.
	 */
	public function test_null_values_do_not_overwrite_on_upsert(): void {
		$this->sut->record_event( $this->an_event() );
		$this->sut->record_event(
			$this->an_event(
				array(
					'risk_score' => null,
					'order_id'   => 0,
				)
			)
		);

		$row = $this->sut->get_by_session_id( 'session-abc' );
		$this->assertSame( 0.91, (float) $row['risk_score'], 'A null risk score must not erase the recorded one' );
		$this->assertSame( '123', (string) $row['order_id'], 'A missing order ID must not erase the recorded one' );
	}

	/**
	 * @testdox Should insert separate rows for events with no session ID.
	 */
	public function test_events_without_session_id_insert_separate_rows(): void {
		$this->sut->record_event( $this->an_event( array( 'session_id' => '' ) ) );
		$this->sut->record_event( $this->an_event( array( 'session_id' => '' ) ) );

		$this->assertSame( 2, $this->sut->count_events(), 'Events without a session ID cannot be deduplicated' );
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
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . $this->schema_manager->get_sessions_table_name() . ' SET last_seen = %s WHERE session_id = %s', $old_date, 'old-session' ) );

		$deleted = $this->sut->prune_older_than( 30 );

		$this->assertSame( 1, $deleted );
		$this->assertNull( $this->sut->get_by_session_id( 'old-session' ), 'The old row should have been pruned' );
		$this->assertNotNull( $this->sut->get_by_session_id( 'fresh-session' ), 'The fresh row should have been kept' );
	}
}
