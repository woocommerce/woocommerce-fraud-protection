<?php
/**
 * SchemaManagerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Database;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
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
	 * The fake wpdb global.
	 *
	 * @var object
	 */
	private $fake_wpdb;

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
			public $prefix         = 'wp_';
			public $last_error     = '';
			public $get_var_result = null;

			public function prepare( $query, ...$args ) {
				return $query;
			}

			public function esc_like( $text ) {
				return $text;
			}

			public function get_var( $query ) {
				return $this->get_var_result;
			}

			public function get_charset_collate() {
				return '';
			}
		};

		$this->register_legacy_proxy_global_mocks( array( 'wpdb' => $this->fake_wpdb ) );
		$this->register_legacy_proxy_function_mocks(
			array(
				'dbDelta' => function ( $schema ) {
					$this->db_delta_calls[] = $schema;
					return array();
				},
			)
		);

		$this->sut = new SchemaManager();
		$this->sut->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ) );
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
		$sut->init( $disabled_feature, wc_get_container()->get( LegacyProxy::class ) );

		$sut->register();

		$this->assertEmpty( $this->db_delta_calls, 'dbDelta must not run while the feature is off' );
		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'The schema version option must not be set' );
	}

	/**
	 * @testdox Should run dbDelta with the sessions schema and store the schema version when the table exists afterwards.
	 */
	public function test_installs_schema_when_feature_enabled(): void {
		$this->fake_wpdb->get_var_result = 'wp_wc_fraud_protection_sessions';

		$this->sut->register();

		$this->assertCount( 1, $this->db_delta_calls );
		$this->assertStringContainsString( 'CREATE TABLE wp_wc_fraud_protection_sessions', $this->db_delta_calls[0] );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, (int) get_option( SchemaManager::DB_VERSION_OPTION ) );
	}

	/**
	 * @testdox Should log an error and not store the schema version when the table does not exist after dbDelta.
	 */
	public function test_does_not_store_version_when_table_creation_fails(): void {
		$this->fake_wpdb->get_var_result = null;

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed installation must not be recorded as done' );
		$this->assertLogged( 'error', 'Sessions table creation failed' );
	}

	/**
	 * @testdox Should be idempotent: no dbDelta run once the stored version matches.
	 */
	public function test_register_is_idempotent(): void {
		$this->fake_wpdb->get_var_result = 'wp_wc_fraud_protection_sessions';

		$this->sut->register();
		$this->sut->register();

		$this->assertCount( 1, $this->db_delta_calls, 'The second register() must not run dbDelta again' );
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
	 * @testdox Should record the attempt count and the last database error in the retry state when installation fails.
	 */
	public function test_failed_install_records_retry_state(): void {
		$this->fake_wpdb->get_var_result = null;
		$this->fake_wpdb->last_error     = 'Specified key was too long';

		$this->sut->register();

		$state = get_option( SchemaManager::DB_INSTALL_STATE_OPTION );
		$this->assertIsArray( $state );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, $state['schema_version'] );
		$this->assertSame( 1, $state['attempts'] );
		$this->assertSame( 'Specified key was too long', $state['last_error'] );
		$this->assertEqualsWithDelta( time(), $state['last_attempt'], 5 );
	}

	/**
	 * @testdox Should not retry a failed installation within the retry interval.
	 */
	public function test_failed_install_is_not_retried_within_the_interval(): void {
		$this->fake_wpdb->get_var_result = null;

		$this->sut->register();
		$this->sut->register();

		$this->assertCount( 1, $this->db_delta_calls, 'The second register() must be throttled' );
	}

	/**
	 * @testdox Should retry a failed installation once the retry interval has elapsed.
	 */
	public function test_failed_install_is_retried_after_the_interval(): void {
		$this->fake_wpdb->get_var_result = null;
		$this->seed_install_state( array( 'last_attempt' => time() - HOUR_IN_SECONDS - 1 ) );

		$this->sut->register();

		$this->assertCount( 1, $this->db_delta_calls );
		$state = get_option( SchemaManager::DB_INSTALL_STATE_OPTION );
		$this->assertSame( 2, $state['attempts'] );
	}

	/**
	 * @testdox Should stop attempting after the maximum number of failed attempts.
	 */
	public function test_gives_up_after_max_attempts(): void {
		$this->fake_wpdb->get_var_result = null;
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
		$this->fake_wpdb->get_var_result = 'wp_wc_fraud_protection_sessions';
		$this->seed_install_state(
			array(
				'schema_version' => 999,
				'attempts'       => 24,
				'last_attempt'   => time(),
			)
		);

		$this->sut->register();

		$this->assertCount( 1, $this->db_delta_calls, 'A version bump must start a fresh round of attempts' );
		$this->assertSame( SchemaManager::SCHEMA_VERSION, (int) get_option( SchemaManager::DB_VERSION_OPTION ) );
	}

	/**
	 * @testdox Should delete the retry state when installation succeeds.
	 */
	public function test_successful_install_clears_retry_state(): void {
		$this->fake_wpdb->get_var_result = 'wp_wc_fraud_protection_sessions';
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

		$this->fake_wpdb->get_var_result = 'wp_wc_fraud_protection_sessions';
		$this->sut->register();

		$this->assertTrue( $this->sut->is_schema_installed() );
	}
}
