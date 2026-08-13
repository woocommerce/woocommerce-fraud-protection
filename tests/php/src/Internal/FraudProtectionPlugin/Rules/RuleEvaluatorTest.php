<?php
/**
 * RuleEvaluatorTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\ConditionOperatorRegistry;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleEvaluator;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\RuleStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the RuleEvaluator class.
 *
 * These are integration-style: rules are created through a real RuleStore
 * over a real rules table, and evaluation runs the real operator registry.
 */
class RuleEvaluatorTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var RuleEvaluator
	 */
	private $sut;

	/**
	 * Real rule store over a real table.
	 *
	 * @var RuleStore
	 */
	private $rule_store;

	/**
	 * Schema manager used to create the table.
	 *
	 * @var SchemaManager
	 */
	private $schema_manager;

	/**
	 * Mock visitor IP resolver.
	 *
	 * @var VisitorIpResolver&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $visitor_ip_resolver;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ) );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_rules_table_schema() );
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );

		$this->rule_store = new RuleStore();
		$this->rule_store->init( $this->schema_manager );

		$this->visitor_ip_resolver = $this->createMock( VisitorIpResolver::class );
		$this->sut                 = new RuleEvaluator();
		$this->sut->init( $this->rule_store, new MerchantListsFeature(), $this->schema_manager, new ConditionOperatorRegistry(), $this->visitor_ip_resolver );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_rules_table_name() );
		delete_option( SchemaManager::DB_VERSION_OPTION );

		parent::tearDown();
	}

	/**
	 * A session data payload as assembled by SessionVerifier.
	 *
	 * @param string $billing_email The billing email in the payload.
	 * @return array
	 */
	private function a_session_data_payload( string $billing_email = 'Customer@Example.COM ' ): array {
		return array(
			'source'   => 'blocks_checkout',
			'session'  => array(
				'wc_identity_id' => 'identity-1',
				'email'          => 'account@example.com',
			),
			'customer' => array( 'billing_email' => $billing_email ),
			'order'    => array( 'order_id' => 456 ),
			'payment'  => array( 'gateway' => 'woocommerce_payments' ),
		);
	}

	/**
	 * An email equals condition document.
	 *
	 * @param string $email The email value.
	 * @return array
	 */
	private function email_condition( string $email ): array {
		return array(
			'field'    => 'email',
			'operator' => 'equals',
			'value'    => $email,
		);
	}

	/**
	 * @testdox Should return null when no rule matches.
	 */
	public function test_returns_null_when_no_rule_matches(): void {
		$this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'other@example.com' ) );

		$this->assertNull( $this->sut->evaluate_for_session( $this->a_session_data_payload() ) );
	}

	/**
	 * @testdox Should match an email rule against the normalized billing email.
	 */
	public function test_matches_email_rule(): void {
		$rule = $this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );

		$matched = $this->sut->evaluate_for_session( $this->a_session_data_payload() );

		$this->assertSame( $rule->id, $matched->id, 'The differently-cased payload email must match the rule' );
	}

	/**
	 * @testdox Should match an IP rule against the canonicalized visitor IP.
	 */
	public function test_matches_ip_rule(): void {
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_address' )
			->willReturn( '2001:db8::1' );

		$rule = $this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '2001:0DB8:0000:0000:0000:0000:0000:0001',
			)
		);

		$matched = $this->sut->evaluate_for_session( $this->a_session_data_payload() );

		$this->assertSame( $rule->id, $matched->id, 'Textual IPv6 variants of the same address must match' );
	}

	/**
	 * @testdox Should not match an IP rule when VisitorIpResolver returns null.
	 */
	public function test_ip_rule_does_not_match_without_resolved_ip(): void {
		$this->visitor_ip_resolver
			->expects( $this->once() )
			->method( 'get_ip_address' )
			->willReturn( null );

		$this->rule_store->create_rule( FraudDecision::Block, $this->ip_condition( '198.51.100.1' ) );

		$this->assertNull( $this->sut->evaluate_for_session( $this->a_session_data_payload() ) );
	}

	/**
	 * An IP equals condition document.
	 *
	 * @param string $ip The IP value.
	 * @return array
	 */
	private function ip_condition( string $ip ): array {
		return array(
			'field'    => 'ip',
			'operator' => 'equals',
			'value'    => $ip,
		);
	}

	/**
	 * @testdox Should return the first matching rule in position order: a seeded allow rule wins over a block rule.
	 */
	public function test_first_match_wins_allow_band_first(): void {
		$this->visitor_ip_resolver->method( 'get_ip_address' )->willReturn( '203.0.113.7' );

		$block = $this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );
		$allow = $this->rule_store->create_rule( FraudDecision::Allow, $this->ip_condition( '203.0.113.7' ) );

		$matched = $this->sut->evaluate_for_session( $this->a_session_data_payload() );

		$this->assertSame( $allow->id, $matched->id, 'The allow rule is seeded above the block rule and must win' );
		$this->assertNotSame( $block->id, $matched->id );
	}

	/**
	 * @testdox Should honor an explicit reorder: a block rule moved above an allow rule wins.
	 */
	public function test_first_match_honors_explicit_reordering(): void {
		$this->visitor_ip_resolver->method( 'get_ip_address' )->willReturn( '203.0.113.7' );

		$block = $this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );
		$allow = $this->rule_store->create_rule( FraudDecision::Allow, $this->ip_condition( '203.0.113.7' ) );

		$this->rule_store->update_rule( $block->id, null, null, null, $allow->position - 1 );

		$matched = $this->sut->evaluate_for_session( $this->a_session_data_payload() );

		$this->assertSame( $block->id, $matched->id, 'The merchant-reordered block rule must win' );
	}

	/**
	 * @testdox Should ignore disabled and soft-deleted rules.
	 */
	public function test_ignores_disabled_and_deleted_rules(): void {
		$this->visitor_ip_resolver->method( 'get_ip_address' )->willReturn( '203.0.113.7' );

		$disabled = $this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );
		$this->rule_store->update_rule( $disabled->id, null, null, RuleStatus::Disabled );

		$deleted = $this->rule_store->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '203.0.113.7',
			)
		);
		$this->rule_store->delete_rule( $deleted->id );

		$this->assertNull( $this->sut->evaluate_for_session( $this->a_session_data_payload() ) );
	}

	/**
	 * @testdox Should not match when the context field is missing from the session.
	 */
	public function test_missing_context_field_is_a_non_match(): void {
		$this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );

		$payload                              = $this->a_session_data_payload( '' );
		$payload['session']['email']          = '';
		$payload['customer']['billing_email'] = '';

		$this->assertNull( $this->sut->evaluate_for_session( $payload ) );
	}

	/**
	 * @testdox Should log and skip a rule with an unsupported conditions shape, still evaluating the rest.
	 */
	public function test_unsupported_conditions_shape_is_logged_and_skipped(): void {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();
		// A future compound rule this engine version does not implement, positioned first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (action, status, position, conditions, condition_hash, created_at) VALUES (%s, %s, %d, %s, %s, %s)", 'block', 'active', 0, '{"operator":"and","checks":[]}', 'hash-compound', gmdate( 'Y-m-d H:i:s' ) ) );

		$rule = $this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );

		$matched = $this->sut->evaluate_for_session( $this->a_session_data_payload() );

		$this->assertSame( $rule->id, $matched->id, 'The malformed rule must be skipped, not abort the evaluation' );
		$this->assertLogged( 'warning', 'unsupported shape' );
	}

	/**
	 * @testdox Should log and treat as non-matching a rule naming an unknown operator.
	 */
	public function test_unknown_operator_is_logged_and_non_matching(): void {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (action, status, position, conditions, condition_hash, created_at) VALUES (%s, %s, %d, %s, %s, %s)", 'block', 'active', 1, '{"field":"email","operator":"wildcard","value":"*@example.com"}', 'hash-wildcard', gmdate( 'Y-m-d H:i:s' ) ) );

		$this->assertNull( $this->sut->evaluate_for_session( $this->a_session_data_payload() ) );
		$this->assertLogged( 'warning', 'Unknown rule condition operator "wildcard"' );
	}

	/**
	 * @testdox Should return null while the merchant lists feature is disabled.
	 */
	public function test_returns_null_when_feature_disabled(): void {
		$this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );

		$disabled_feature = $this->createMock( MerchantListsFeature::class );
		$disabled_feature->method( 'is_enabled' )->willReturn( false );

		$sut = new RuleEvaluator();
		$sut->init( $this->rule_store, $disabled_feature, $this->schema_manager, new ConditionOperatorRegistry(), $this->visitor_ip_resolver );

		$this->assertNull( $sut->evaluate_for_session( $this->a_session_data_payload() ) );
	}

	/**
	 * @testdox Should return null while the schema is not installed.
	 */
	public function test_returns_null_when_schema_not_installed(): void {
		$this->rule_store->create_rule( FraudDecision::Block, $this->email_condition( 'customer@example.com' ) );

		delete_option( SchemaManager::DB_VERSION_OPTION );

		$this->assertNull( $this->sut->evaluate_for_session( $this->a_session_data_payload() ) );
	}

	/**
	 * @testdox Should fail open, logging an error, when the rule store throws.
	 */
	public function test_fails_open_when_store_throws(): void {
		$throwing_store = $this->createMock( RuleStore::class );
		$throwing_store->method( 'get_active_rules' )->willThrowException( new \RuntimeException( 'database exploded' ) );

		$sut = new RuleEvaluator();
		$sut->init( $throwing_store, new MerchantListsFeature(), $this->schema_manager, new ConditionOperatorRegistry(), $this->visitor_ip_resolver );

		$this->assertNull( $sut->evaluate_for_session( $this->a_session_data_payload() ) );
		$this->assertLogged( 'error', 'Rule evaluation failed, no rule applied' );
	}
}
