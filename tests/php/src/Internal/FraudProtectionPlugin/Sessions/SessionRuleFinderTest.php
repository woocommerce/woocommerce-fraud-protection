<?php
/**
 * SessionRuleFinderTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\ConditionOperatorRegistry;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\RuleStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionRuleFinder;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Tests for the SessionRuleFinder class.
 */
class SessionRuleFinderTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionRuleFinder
	 */
	private $sut;

	/**
	 * Rule store instance.
	 *
	 * @var RuleStore
	 */
	private $rule_store;

	/**
	 * Schema manager instance.
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
		dbDelta( $this->schema_manager->get_rules_table_schema() );
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );

		$this->rule_store = new RuleStore();
		$this->rule_store->init( $this->schema_manager );

		$this->sut = new SessionRuleFinder();
		$this->sut->init( $this->rule_store, $this->schema_manager, new ConditionOperatorRegistry() );
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
	 * Create an active rule for a field value.
	 *
	 * @param FraudDecision $action The rule action.
	 * @param string        $field  The condition field.
	 * @param string        $value  The condition value.
	 * @return Rule
	 */
	private function create_rule( FraudDecision $action, string $field, string $value ): Rule {
		return $this->rule_store->create_rule(
			$action,
			array(
				'field'    => $field,
				'operator' => 'equals',
				'value'    => $value,
			)
		);
	}

	/**
	 * @testdox Should return no rule for a value with no matching active rule.
	 */
	public function test_returns_no_rule_when_none_matches(): void {
		$matches = $this->sut->find_for_events(
			array(
				array(
					'id'    => 5,
					'email' => 'shopper@example.com',
					'ip'    => '203.0.113.9',
				),
			)
		);

		$this->assertNull( $matches[5]['email'] );
		$this->assertNull( $matches[5]['ip'] );
	}

	/**
	 * @testdox Should match the active email and IP rules that target the event values.
	 */
	public function test_matches_email_and_ip_rules(): void {
		$email_rule = $this->create_rule( FraudDecision::Block, 'email', 'shopper@example.com' );
		$ip_rule    = $this->create_rule( FraudDecision::Allow, 'ip', '203.0.113.9' );

		$matches = $this->sut->find_for_events(
			array(
				array(
					'id'    => 5,
					'email' => 'shopper@example.com',
					'ip'    => '203.0.113.9',
				),
			)
		);

		$this->assertInstanceOf( Rule::class, $matches[5]['email'] );
		$this->assertSame( $email_rule->id, $matches[5]['email']->id );
		$this->assertSame( $ip_rule->id, $matches[5]['ip']->id );
	}

	/**
	 * @testdox Should match emails case-insensitively and IPs by canonical form.
	 */
	public function test_matches_normalized_values(): void {
		$email_rule = $this->create_rule( FraudDecision::Block, 'email', 'shopper@example.com' );
		$ip_rule    = $this->create_rule( FraudDecision::Block, 'ip', '2001:db8::1' );

		$matches = $this->sut->find_for_events(
			array(
				array(
					'id'    => 9,
					'email' => 'SHOPPER@EXAMPLE.COM',
					'ip'    => '2001:0DB8:0000:0000:0000:0000:0000:0001',
				),
			)
		);

		$this->assertSame( $email_rule->id, $matches[9]['email']->id );
		$this->assertSame( $ip_rule->id, $matches[9]['ip']->id );
	}

	/**
	 * @testdox Should return the normalized values active rules target, per field.
	 */
	public function test_returns_targeted_values(): void {
		$this->create_rule( FraudDecision::Block, 'email', 'Shopper@Example.com' );
		$this->create_rule( FraudDecision::Allow, 'ip', '2001:0DB8::1' );

		$values = $this->sut->get_targeted_values();

		$this->assertSame( array( 'shopper@example.com' ), $values['email'] );
		$this->assertSame( array( '2001:db8::1' ), $values['ip'] );
	}

	/**
	 * @testdox Should return empty targeted values when no active rule exists.
	 */
	public function test_returns_empty_targeted_values_without_rules(): void {
		$values = $this->sut->get_targeted_values();

		$this->assertSame( array(), $values['email'] );
		$this->assertSame( array(), $values['ip'] );
	}

	/**
	 * @testdox Should ignore rules that are no longer active.
	 */
	public function test_ignores_inactive_rules(): void {
		$rule = $this->create_rule( FraudDecision::Block, 'email', 'shopper@example.com' );
		$this->rule_store->update_rule( $rule->id, null, null, RuleStatus::Disabled );

		$matches = $this->sut->find_for_events(
			array(
				array(
					'id'    => 1,
					'email' => 'shopper@example.com',
					'ip'    => '',
				),
			)
		);

		$this->assertNull( $matches[1]['email'] );
	}

	/**
	 * @testdox Should not match when the event has no value for the field.
	 */
	public function test_no_match_for_missing_value(): void {
		$this->create_rule( FraudDecision::Block, 'email', 'shopper@example.com' );

		$matches = $this->sut->find_for_events(
			array(
				array(
					'id'    => 2,
					'email' => '',
					'ip'    => 'not-an-ip',
				),
			)
		);

		$this->assertNull( $matches[2]['email'] );
		$this->assertNull( $matches[2]['ip'] );
	}

	/**
	 * @testdox Should return empty matches while the schema is not installed.
	 */
	public function test_returns_empty_matches_without_schema(): void {
		delete_option( SchemaManager::DB_VERSION_OPTION );

		$matches = $this->sut->find_for_events(
			array(
				array(
					'id'    => 3,
					'email' => 'shopper@example.com',
					'ip'    => '203.0.113.9',
				),
			)
		);

		$this->assertNull( $matches[3]['email'] );
		$this->assertNull( $matches[3]['ip'] );
	}
}
