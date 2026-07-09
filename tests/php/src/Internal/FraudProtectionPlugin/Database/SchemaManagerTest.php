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
		delete_option( MerchantListsFeature::OPTION_NAME );
		remove_all_filters( 'woocommerce_fraud_protection_merchant_lists_enabled' );
		parent::tearDown();
	}

	/**
	 * @testdox Should not run dbDelta when the feature is disabled.
	 */
	public function test_does_not_install_schema_when_feature_disabled(): void {
		$this->sut->register();

		$this->assertEmpty( $this->db_delta_calls, 'dbDelta must not run while the feature is off' );
		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'The schema version option must not be set' );
	}

	/**
	 * @testdox Should run dbDelta with the sessions schema and store the schema version when the table exists afterwards.
	 */
	public function test_installs_schema_when_feature_enabled(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );
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
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );
		$this->fake_wpdb->get_var_result = null;

		$this->sut->register();

		$this->assertSame( 0, (int) get_option( SchemaManager::DB_VERSION_OPTION, 0 ), 'A failed installation must not be recorded as done' );
		$this->assertLogged( 'error', 'Sessions table creation failed' );
	}

	/**
	 * @testdox Should be idempotent: no dbDelta run once the stored version matches.
	 */
	public function test_register_is_idempotent(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );
		$this->fake_wpdb->get_var_result = 'wp_wc_fraud_protection_sessions';

		$this->sut->register();
		$this->sut->register();

		$this->assertCount( 1, $this->db_delta_calls, 'The second register() must not run dbDelta again' );
	}
}
