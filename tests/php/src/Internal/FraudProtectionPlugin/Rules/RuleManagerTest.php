<?php
/**
 * RuleManagerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\DuplicateRuleException;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\RuleStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Tests for RuleManager.
 */
class RuleManagerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var RuleManager
	 */
	private $sut;

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private $schema_manager;

	/**
	 * Rule store instance.
	 *
	 * @var RuleStore
	 */
	private $rule_store;

	/**
	 * Mock settings telemetry.
	 *
	 * @var SettingsTelemetry&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $telemetry;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( FraudProtectionLogger::class ) );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_rules_table_schema() );
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );

		$this->rule_store = new RuleStore();
		$this->rule_store->init( $this->schema_manager );
		$this->telemetry = $this->createMock( SettingsTelemetry::class );

		$this->sut = new RuleManager();
		$this->sut->init( $this->rule_store, $this->telemetry );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_rules_table_name() );
		delete_option( SchemaManager::DB_VERSION_OPTION );
		wp_cache_flush();

		parent::tearDown();
	}

	/**
	 * @testdox Detects the rule type of a value, or null when it is neither an IP address nor an email address.
	 * @dataProvider detect_type_provider
	 *
	 * @param string  $value    The value.
	 * @param ?string $expected Expected type.
	 */
	public function test_detect_type( string $value, ?string $expected ): void {
		$this->assertSame( $expected, RuleManager::detect_type( $value ) );
	}

	/**
	 * Values and their detected types.
	 *
	 * @return array<string, array{string, ?string}>
	 */
	public function detect_type_provider(): array {
		return array(
			'IPv4'       => array( '192.0.2.10', 'ip' ),
			'IPv6'       => array( '2001:DB8::1', 'ip' ),
			'email'      => array( 'Shopper@Example.com', 'email' ),
			'digits'     => array( '12', null ),
			'incomplete' => array( 'shopper@', null ),
			'empty'      => array( '', null ),
		);
	}

	/**
	 * @testdox Builds normalized exact-value conditions and rejects values that do not fit the type.
	 */
	public function test_build_conditions(): void {
		$this->assertSame(
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'shopper@example.com',
			),
			RuleManager::build_conditions( 'email', ' Shopper@Example.com ' )
		);
		$this->assertNull( RuleManager::build_conditions( 'ip', 'shopper@example.com' ), 'An email address is not a valid IP value' );
		$this->assertNull( RuleManager::build_conditions( 'country', 'ES' ), 'Unknown types are rejected' );
	}

	/**
	 * @testdox Creating a rule records the change with its origin.
	 */
	public function test_create_rule_records_telemetry(): void {
		$this->telemetry->expects( $this->once() )->method( 'record_rule_change' )->with( 'created', FraudDecision::Block, 'ip', 'cli' );

		$rule = $this->sut->create_rule( FraudDecision::Block, RuleManager::build_conditions( 'ip', '192.0.2.10' ), 'cli' );

		$this->assertSame( RuleStatus::Active, $rule->status );
		$this->assertSame( '192.0.2.10', $rule->conditions['value'] );
	}

	/**
	 * @testdox A duplicate create throws without recording a change.
	 */
	public function test_duplicate_create_records_no_telemetry(): void {
		$conditions = RuleManager::build_conditions( 'ip', '192.0.2.10' );
		$existing   = $this->rule_store->create_rule( FraudDecision::Block, $conditions );
		$this->telemetry->expects( $this->never() )->method( 'record_rule_change' );

		try {
			$this->sut->create_rule( FraudDecision::Allow, $conditions, 'cli' );
			$this->fail( 'A duplicate rule must not be created' );
		} catch ( DuplicateRuleException $error ) {
			$this->assertSame( $existing->id, $error->existing_rule_id );
		}
	}

	/**
	 * @testdox Updating a rule records a change only when something changed.
	 */
	public function test_update_rule_records_only_real_changes(): void {
		$conditions = RuleManager::build_conditions( 'email', 'shopper@example.com' );
		$rule       = $this->rule_store->create_rule( FraudDecision::Block, $conditions );
		$this->telemetry->expects( $this->once() )->method( 'record_rule_change' )->with( 'updated', FraudDecision::Allow, 'email', 'cli' );

		$unchanged = $this->sut->update_rule( $rule->id, FraudDecision::Block, $conditions, 'cli' );
		$changed   = $this->sut->update_rule( $rule->id, FraudDecision::Allow, $conditions, 'cli' );

		$this->assertFalse( $unchanged['changed'] );
		$this->assertTrue( $changed['changed'] );
		$this->assertSame( FraudDecision::Allow, $changed['rule']->action );
	}

	/**
	 * @testdox Updating or deleting a rule that is not active returns null without recording a change.
	 */
	public function test_inactive_rule_is_not_updated_or_deleted(): void {
		$conditions = RuleManager::build_conditions( 'email', 'shopper@example.com' );
		$rule       = $this->rule_store->create_rule( FraudDecision::Block, $conditions );
		$this->rule_store->delete_rule( $rule->id );
		$this->telemetry->expects( $this->never() )->method( 'record_rule_change' );

		$this->assertNull( $this->sut->update_rule( $rule->id, FraudDecision::Allow, $conditions, 'cli' ) );
		$this->assertNull( $this->sut->delete_rule( $rule->id, 'cli' ) );
		$this->assertNull( $this->sut->get_active_rule( $rule->id ) );
	}

	/**
	 * @testdox Deleting a rule returns its previous state and records the change.
	 */
	public function test_delete_rule_records_telemetry(): void {
		$rule = $this->rule_store->create_rule( FraudDecision::Allow, RuleManager::build_conditions( 'ip', '192.0.2.10' ) );
		$this->telemetry->expects( $this->once() )->method( 'record_rule_change' )->with( 'deleted', FraudDecision::Allow, 'ip', 'rules' );

		$deleted = $this->sut->delete_rule( $rule->id, 'rules' );

		$this->assertSame( $rule->id, $deleted?->id );
		$this->assertNull( $this->sut->get_active_rule( $rule->id ) );
	}

	/**
	 * @testdox Finds the active rule for a value after normalizing it.
	 */
	public function test_find_active_rule_by_value_normalizes_value(): void {
		$rule = $this->rule_store->create_rule( FraudDecision::Block, RuleManager::build_conditions( 'ip', '2001:db8::1' ) );

		$this->assertSame( $rule->id, $this->sut->find_active_rule_by_value( 'ip', '2001:DB8:0::1' )?->id );
		$this->assertNull( $this->sut->find_active_rule_by_value( 'ip', '2001:db8::2' ) );
	}

	/**
	 * @testdox Duplicate messages describe known combinations and use a generic fallback for unknown data.
	 * @dataProvider duplicate_message_provider
	 *
	 * @param string         $type            Rule type.
	 * @param ?FraudDecision $existing_action Existing action.
	 * @param string         $expected        Expected message.
	 */
	public function test_duplicate_rule_messages( string $type, ?FraudDecision $existing_action, string $expected ): void {
		$this->assertSame( $expected, RuleManager::duplicate_rule_message( $type, $existing_action ) );
	}

	/**
	 * Duplicate rule message cases.
	 *
	 * @return array<string, array{string, ?FraudDecision, string}>
	 */
	public function duplicate_message_provider(): array {
		$generic = 'You already have a rule with this value.';

		return array(
			'email allow'    => array( 'email', FraudDecision::Allow, 'You already have a rule that allows this email address.' ),
			'email block'    => array( 'email', FraudDecision::Block, 'You already have a rule that blocks this email address.' ),
			'IP allow'       => array( 'ip', FraudDecision::Allow, 'You already have a rule that allows this IP address.' ),
			'IP block'       => array( 'ip', FraudDecision::Block, 'You already have a rule that blocks this IP address.' ),
			'unknown field'  => array( 'country', FraudDecision::Allow, $generic ),
			'unknown action' => array( 'email', FraudDecision::Challenge, $generic ),
			'null action'    => array( 'email', null, $generic ),
		);
	}
}
