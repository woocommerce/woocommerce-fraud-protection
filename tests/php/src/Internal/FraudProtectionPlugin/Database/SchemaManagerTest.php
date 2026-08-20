<?php
/**
 * SchemaManagerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Database;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the SchemaManager class.
 *
 * These are pure unit tests: `dbDelta` and `$wpdb` are reached through the
 * LegacyProxy and mocked, so no real DDL runs. The schema string itself is
 * exercised against the database by the SessionEventStore and integration
 * tests, which create the table from it.
 */
class SchemaManagerTest extends FraudProtectionUnitTestCase {

	/**
	 * The columns of the sessions table schema.
	 *
	 * @var array
	 */
	private const SESSIONS_COLUMNS = array( 'id', 'session_id', 'recorded_at', 'source', 'decision', 'final_status', 'trigger_type', 'risk_score', 'email', 'ip', 'ip_country', 'billing_country', 'billing_state', 'billing_city', 'billing_postcode', 'billing_name', 'order_id', 'payment_method', 'matched_rule_id', 'metadata', 'reported_at' );

	/**
	 * The columns of the rules table schema.
	 *
	 * @var array
	 */
	private const RULES_COLUMNS = array( 'id', 'action', 'status', 'position', 'conditions', 'condition_hash', 'action_meta', 'source_meta', 'created_at', 'created_by', 'updated_at', 'updated_by', 'source_session_id' );

	/**
	 * The indexes of the sessions table schema, as SHOW INDEX reports them.
	 *
	 * @var array
	 */
	private const SESSIONS_INDEXES = array( 'PRIMARY', 'session_id', 'email', 'recorded_at', 'matched_rule_id' );

	/**
	 * The indexes of the rules table schema, as SHOW INDEX reports them.
	 *
	 * @var array
	 */
	private const RULES_INDEXES = array( 'PRIMARY', 'condition_hash', 'status_position' );

	/**
	 * The System Under Test.
	 *
	 * @var SchemaManager
	 */
	private $sut;

	/**
	 * The schemas passed to the mocked dbDelta calls.
	 *
	 * @var array
	 */
	private $db_delta_calls = array();

	/**
	 * Error strings the mocked dbDelta should simulate, keyed by call index:
	 * each one is appended to the mocked `$EZSQL_ERROR` global during that
	 * dbDelta call, like a failed query would in real wpdb.
	 *
	 * @var array
	 */
	private $db_delta_errors_by_call = array();

	/**
	 * The simulated content of the `$EZSQL_ERROR` global.
	 *
	 * @var array
	 */
	private $simulated_query_errors = array();

	/**
	 * The fake wpdb global.
	 *
	 * @var object
	 */
	private $fake_wpdb;

	/**
	 * Mock logger.
	 *
	 * @var FraudProtectionLogger&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// The feature gate is always on, so the plugin installs the schema for real
		// during the test bootstrap; clear the recorded version so each test starts
		// from a not-yet-installed state.
		delete_option( SchemaManager::DB_VERSION_OPTION );
		delete_option( SchemaManager::DB_INSTALL_STATE_OPTION );

		// phpcs:ignore Squiz.Commenting -- test double.
		$this->fake_wpdb = new class() {
			public $prefix          = 'wp_';
			public $existing_tables = array();
			public $table_columns   = array();
			public $table_indexes   = array();

			public function prepare( $query, ...$args ) {
				return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $query ), $args );
			}

			public function esc_like( $text ) {
				return $text;
			}

			public function get_var( $query ) {
				foreach ( $this->existing_tables as $table ) {
					if ( false !== strpos( $query, $table ) ) {
						return $table;
					}
				}
				return null;
			}

			public function get_col( $query ) {
				foreach ( $this->table_columns as $table => $columns ) {
					if ( false !== strpos( $query, $table ) ) {
						return $columns;
					}
				}
				return array();
			}

			public function get_results( $query, $output = null ) {
				foreach ( $this->table_indexes as $table => $indexes ) {
					if ( false !== strpos( $query, $table ) ) {
						return array_map(
							function ( $name ) {
								return array( 'Key_name' => $name );
							},
							$indexes
						);
					}
				}
				return array();
			}

			public function get_charset_collate() {
				return '';
			}
		};

		$this->register_legacy_proxy_global_mocks(
			array(
				'wpdb'        => $this->fake_wpdb,
				'EZSQL_ERROR' => array(),
			)
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'dbDelta' => function ( $schema ) {
					$call_index             = count( $this->db_delta_calls );
					$this->db_delta_calls[] = $schema;
					foreach ( $this->db_delta_errors_by_call[ $call_index ] ?? array() as $error ) {
						$this->simulated_query_errors[] = array(
							'query'     => 'ALTER TABLE x ADD COLUMN y',
							'error_str' => $error,
						);
					}
					$this->register_legacy_proxy_global_mocks( array( 'EZSQL_ERROR' => $this->simulated_query_errors ) );
					return array();
				},
			)
		);
		$this->logger = $this->createMock( FraudProtectionLogger::class );
		$this->logger->method( 'log' )->willReturnCallback( array( FraudProtectionController::class, 'log' ) );

		$this->sut = new SchemaManager();
		$this->sut->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), $this->logger );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( SchemaManager::DB_VERSION_OPTION );
		delete_option( SchemaManager::DB_INSTALL_STATE_OPTION );
		parent::tearDown();
	}

	/**
	 * @testdox Should not run dbDelta when the feature gate is off.
	 */
	public function test_does_not_install_schema_when_feature_disabled(): void {
		$disabled_feature = $this->createMock( MerchantListsFeature::class );
		$disabled_feature->method( 'is_enabled' )->willReturn( false );

		$sut = new SchemaManager();
		$sut->init( $disabled_feature, wc_get_container()->get( LegacyProxy::class ), $this->logger );

		$sut->register();

		$this->assertEmpty( $this->db_delta_calls, 'dbDelta must not run while the feature is off' );
		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'The schema version option must not be set' );
	}

	/**
	 * Mark both plugin tables as existing in the fake wpdb, with all of their
	 * schema columns and indexes present.
	 */
	private function mark_tables_as_existing(): void {
		$this->fake_wpdb->existing_tables = array( 'wp_wc_fraud_protection_sessions', 'wp_wc_fraud_protection_rules' );
		$this->fake_wpdb->table_columns   = array(
			'wp_wc_fraud_protection_sessions' => self::SESSIONS_COLUMNS,
			'wp_wc_fraud_protection_rules'    => self::RULES_COLUMNS,
		);
		$this->fake_wpdb->table_indexes   = array(
			'wp_wc_fraud_protection_sessions' => self::SESSIONS_INDEXES,
			'wp_wc_fraud_protection_rules'    => self::RULES_INDEXES,
		);
	}

	/**
	 * @testdox Should run dbDelta with the sessions and rules schemas and store the schema version when the tables exist afterwards.
	 */
	public function test_installs_schema_when_feature_enabled(): void {
		$this->mark_tables_as_existing();

		$this->sut->register();

		$this->assertCount( 2, $this->db_delta_calls );
		$this->assertStringContainsString( 'CREATE TABLE wp_wc_fraud_protection_sessions', $this->db_delta_calls[0] );
		$this->assertStringContainsString( 'CREATE TABLE wp_wc_fraud_protection_rules', $this->db_delta_calls[1] );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, (int) get_option( SchemaManager::DB_VERSION_OPTION ) );
	}

	/**
	 * @testdox Should log an error and not store the schema version when the sessions table does not exist after dbDelta.
	 */
	public function test_does_not_store_version_when_table_creation_fails(): void {
		$this->fake_wpdb->existing_tables = array();

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed installation must not be recorded as done' );
		$this->assertLogged( 'error', 'Table creation failed: wp_wc_fraud_protection_sessions' );
	}

	/**
	 * @testdox Should log an error and not store the schema version when the rules table does not exist after dbDelta.
	 */
	public function test_does_not_store_version_when_rules_table_creation_fails(): void {
		$this->fake_wpdb->existing_tables = array( 'wp_wc_fraud_protection_sessions' );
		$this->fake_wpdb->table_columns   = array( 'wp_wc_fraud_protection_sessions' => self::SESSIONS_COLUMNS );
		$this->fake_wpdb->table_indexes   = array( 'wp_wc_fraud_protection_sessions' => self::SESSIONS_INDEXES );

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed installation must not be recorded as done' );
		$this->assertLogged( 'error', 'Table creation failed: wp_wc_fraud_protection_rules' );
	}

	/**
	 * @testdox Should log an error and not store the schema version when a sessions table column is missing after dbDelta.
	 */
	public function test_does_not_store_version_when_a_column_is_missing_after_upgrade(): void {
		$this->mark_tables_as_existing();
		$this->fake_wpdb->table_columns['wp_wc_fraud_protection_sessions'] = array_values( array_diff( self::SESSIONS_COLUMNS, array( 'matched_rule_id' ) ) );

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed upgrade must not be recorded as done' );
		$this->assertLogged( 'error', 'Table upgrade failed: wp_wc_fraud_protection_sessions is missing columns after dbDelta: matched_rule_id.' );

		$state = get_option( SchemaManager::DB_INSTALL_STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertSame( 1, $state['attempts'] );
	}

	/**
	 * @testdox Should log an error and not store the schema version when a rules table column is missing after dbDelta.
	 */
	public function test_does_not_store_version_when_a_rules_table_column_is_missing(): void {
		$this->mark_tables_as_existing();
		$this->fake_wpdb->table_columns['wp_wc_fraud_protection_rules'] = array_values( array_diff( self::RULES_COLUMNS, array( 'condition_hash', 'source_meta' ) ) );

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed upgrade must not be recorded as done' );
		$this->assertLogged( 'error', 'Table upgrade failed: wp_wc_fraud_protection_rules is missing columns after dbDelta: condition_hash, source_meta.' );
	}

	/**
	 * @testdox Should log an error and not store the schema version when a sessions table index is missing after dbDelta.
	 */
	public function test_does_not_store_version_when_an_index_is_missing_after_upgrade(): void {
		$this->mark_tables_as_existing();
		$this->fake_wpdb->table_indexes['wp_wc_fraud_protection_sessions'] = array_values( array_diff( self::SESSIONS_INDEXES, array( 'matched_rule_id' ) ) );

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed upgrade must not be recorded as done' );
		$this->assertLogged( 'error', 'Table upgrade failed: wp_wc_fraud_protection_sessions is missing indexes after dbDelta: matched_rule_id.' );
	}

	/**
	 * @testdox Should log an error and not store the schema version when the rules table unique index is missing after dbDelta.
	 */
	public function test_does_not_store_version_when_the_rules_table_unique_index_is_missing(): void {
		$this->mark_tables_as_existing();
		$this->fake_wpdb->table_indexes['wp_wc_fraud_protection_rules'] = array_values( array_diff( self::RULES_INDEXES, array( 'condition_hash' ) ) );

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed upgrade must not be recorded as done' );
		$this->assertLogged( 'error', 'Table upgrade failed: wp_wc_fraud_protection_rules is missing indexes after dbDelta: condition_hash.' );
	}

	/**
	 * @testdox Should be idempotent: no dbDelta run once the stored version matches.
	 */
	public function test_register_is_idempotent(): void {
		$this->mark_tables_as_existing();

		$this->sut->register();
		$this->sut->register();

		$this->assertCount( 2, $this->db_delta_calls, 'The second register() must not run dbDelta again' );
	}

	/**
	 * Seed the installation retry state option.
	 *
	 * @param array $overrides Values overriding the default seeded state.
	 */
	private function seed_install_state( array $overrides = array() ): void {
		update_option(
			SchemaManager::DB_INSTALL_STATE_OPTION,
			array_merge(
				array(
					'schema_version' => SchemaManager::SCHEMA_VERSION,
					'attempts'       => 1,
					'last_attempt'   => time(),
					'last_error'     => '',
				),
				$overrides
			),
			false
		);
	}

	/**
	 * @testdox Should record the attempt count and the failed queries' database errors in the retry state when installation fails.
	 */
	public function test_failed_install_records_retry_state(): void {
		$this->fake_wpdb->existing_tables = array();
		$this->db_delta_errors_by_call    = array( 0 => array( 'Specified key was too long' ) );

		$this->sut->register();

		$state = get_option( SchemaManager::DB_INSTALL_STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, $state['schema_version'] );
		$this->assertSame( 1, $state['attempts'] );
		$this->assertSame( 'Specified key was too long', $state['last_error'] );
		$this->assertEqualsWithDelta( time(), $state['last_attempt'], 5 );
	}

	/**
	 * @testdox Should attribute each table's database errors to that table, unaffected by the queries that run afterwards.
	 */
	public function test_records_the_failing_tables_own_errors_not_the_last_query_error(): void {
		// The sessions upgrade ALTERs fail (leaving the column missing) while
		// the rules table dbDelta that runs afterwards succeeds. In real wpdb
		// those later queries reset `$wpdb->last_error`, so this asserts the
		// errors are captured per dbDelta call instead of read at the end.
		$this->mark_tables_as_existing();
		$this->fake_wpdb->table_columns['wp_wc_fraud_protection_sessions'] = array_values( array_diff( self::SESSIONS_COLUMNS, array( 'matched_rule_id' ) ) );
		$this->db_delta_errors_by_call = array( 0 => array( 'Lock wait timeout exceeded', "Key column 'matched_rule_id' doesn't exist in table" ) );

		$this->sut->register();

		$expected_errors = "Lock wait timeout exceeded | Key column 'matched_rule_id' doesn't exist in table";

		$state = get_option( SchemaManager::DB_INSTALL_STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertSame( $expected_errors, $state['last_error'] );
		$this->assertLogged(
			'error',
			'Table upgrade failed: wp_wc_fraud_protection_sessions is missing columns after dbDelta: matched_rule_id.',
			array( 'schema_db_error' => $expected_errors ),
			true
		);
	}

	/**
	 * @testdox Should not retry a failed installation within the retry interval.
	 */
	public function test_failed_install_is_not_retried_within_the_interval(): void {
		$this->fake_wpdb->existing_tables = array();

		$this->sut->register();
		$this->sut->register();

		$this->assertCount( 2, $this->db_delta_calls, 'The second register() must be throttled' );
	}

	/**
	 * @testdox Should retry a failed installation once the retry interval has elapsed.
	 */
	public function test_failed_install_is_retried_after_the_interval(): void {
		$this->fake_wpdb->existing_tables = array();
		$this->seed_install_state( array( 'last_attempt' => time() - HOUR_IN_SECONDS - 1 ) );

		$this->sut->register();

		$this->assertCount( 2, $this->db_delta_calls );
		$state = get_option( SchemaManager::DB_INSTALL_STATE_OPTION );
		$this->assertSame( 2, $state['attempts'] );
	}

	/**
	 * @testdox Should stop attempting after the maximum number of failed attempts.
	 */
	public function test_gives_up_after_max_attempts(): void {
		$this->fake_wpdb->existing_tables = array();
		$this->seed_install_state(
			array(
				'attempts'     => 24,
				'last_attempt' => time() - 2 * HOUR_IN_SECONDS,
			)
		);

		$this->sut->register();

		$this->assertEmpty( $this->db_delta_calls, 'No further attempts must run after giving up' );
	}

	/**
	 * @testdox Should reset the retry state, given-up included, when the schema version is bumped.
	 */
	public function test_schema_version_bump_resets_the_retry_state(): void {
		$this->mark_tables_as_existing();
		$this->seed_install_state(
			array(
				'schema_version' => 999,
				'attempts'       => 24,
				'last_attempt'   => time(),
			)
		);

		$this->sut->register();

		$this->assertCount( 2, $this->db_delta_calls, 'A version bump must start a fresh round of attempts' );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, (int) get_option( SchemaManager::DB_VERSION_OPTION ) );
	}

	/**
	 * @testdox Should delete the retry state when installation succeeds.
	 */
	public function test_successful_install_clears_retry_state(): void {
		$this->mark_tables_as_existing();
		$this->seed_install_state(
			array(
				'attempts'     => 3,
				'last_attempt' => time() - 2 * HOUR_IN_SECONDS,
			)
		);

		$this->sut->register();

		$this->assertSame( SchemaManager::SCHEMA_VERSION, (int) get_option( SchemaManager::DB_VERSION_OPTION ) );
		$this->assertFalse( get_option( SchemaManager::DB_INSTALL_STATE_OPTION ), 'The retry state must be cleared on success' );
	}

	/**
	 * @testdox is_schema_installed() should report whether the stored version matches the current one.
	 */
	public function test_is_schema_installed(): void {
		$this->assertFalse( $this->sut->is_schema_installed() );

		$this->mark_tables_as_existing();
		$this->sut->register();

		$this->assertTrue( $this->sut->is_schema_installed() );
	}

	/**
	 * @testdox Should treat a newer stored schema version as installed and leave it untouched (rollback scenario).
	 */
	public function test_newer_stored_schema_version_counts_as_installed(): void {
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION + 1 );

		$this->assertTrue( $this->sut->is_schema_installed() );

		$this->sut->register();

		$this->assertEmpty( $this->db_delta_calls, 'A rolled-back build must not re-run dbDelta against a newer schema' );
		$this->assertSame( SchemaManager::SCHEMA_VERSION + 1, (int) get_option( SchemaManager::DB_VERSION_OPTION ), 'The newer version stamp must be left untouched' );
	}
}
