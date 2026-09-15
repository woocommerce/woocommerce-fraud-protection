<?php
/**
 * SessionsRestControllerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Tests\Support\SessionEventFixtures;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\ConditionOperatorRegistry;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\PaymentMethodTitleResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionRuleFinder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionsRestController;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Tests for SessionsRestController.
 */
class SessionsRestControllerTest extends \WC_REST_Unit_Test_Case {

	private const LIST_ROUTE = '/wc-fraud-protection/v1/sessions';

	private const PAYMENT_METHODS_ROUTE = self::LIST_ROUTE . '/payment-methods';

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private $schema_manager;

	/**
	 * Session event store instance.
	 *
	 * @var SessionEventStore
	 */
	private $event_store;

	/**
	 * Rule store instance.
	 *
	 * @var RuleStore
	 */
	private $rule_store;

	/**
	 * The System Under Test.
	 *
	 * @var SessionsRestController
	 */
	private $sut;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( FraudProtectionLogger::class ) );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_sessions_table_schema() );
		dbDelta( $this->schema_manager->get_rules_table_schema() );
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );

		$this->event_store = new SessionEventStore();
		$this->event_store->init( $this->schema_manager );

		$this->rule_store = new RuleStore();
		$this->rule_store->init( $this->schema_manager );

		$rule_finder = new SessionRuleFinder();
		$rule_finder->init( $this->rule_store, $this->schema_manager, new ConditionOperatorRegistry() );

		$this->sut = new SessionsRestController();
		$this->sut->init( $this->schema_manager, $this->event_store, $rule_finder, new PaymentMethodTitleResolver() );
		$this->sut->register_routes();

		wp_set_current_user( 1 );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_sessions_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_rules_table_name() );
		delete_option( SchemaManager::DB_VERSION_OPTION );
		wp_cache_flush();
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Record an event row.
	 *
	 * @param array<string, mixed> $overrides Fields to override.
	 */
	private function record( array $overrides = array() ): void {
		$this->event_store->record_event( SessionEventFixtures::an_event( $overrides ) );
	}

	/**
	 * @testdox An authorized list request returns prepared rows and pagination headers.
	 */
	public function test_list_returns_prepared_rows(): void {
		$this->record(
			array(
				'session_id'      => 'sess-1',
				'trigger_type'    => 'blackbox',
				'final_status'    => 'allowed',
				'decision'        => 'block',
				'email'           => 'shopper@example.com',
				'ip'              => '203.0.113.9',
				'ip_country'      => 'US',
				'billing_country' => 'US',
				'payment_method'  => 'legacy_gateway',
				'order_id'        => 55,
			)
		);

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', self::LIST_ROUTE ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( '1', $response->get_headers()['X-WP-TotalPages'] );

		$item = $response->get_data()[0];
		$this->assertSame( 'shopper@example.com', $item['email'] );
		$this->assertSame( '203.0.113.9', $item['ip'] );
		$this->assertSame(
			array(
				'code' => 'US',
				'name' => WC()->countries->get_countries()['US'],
			),
			$item['ip_country']
		);
		$this->assertSame( 'flagged_by_fraud_prevention', $item['outcome'] );
		$this->assertSame( 'allowed', $item['final_status'] );
		$this->assertSame( 55, $item['order_id'] );
		$this->assertSame( 'legacy_gateway', $item['payment_method']['id'] );
		$this->assertSame( 'legacy_gateway', $item['payment_method']['title'] );
		$this->assertNull( $item['payment_method']['icon'] );
		$this->assertArrayNotHasKey( 'risk_score', $item );
		$this->assertNull( $item['rules']['email'] );
	}

	/**
	 * @testdox A rule targeting a recorded value is referenced on the row.
	 */
	public function test_list_references_matching_rule(): void {
		$rule = $this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'shopper@example.com',
			)
		);
		$this->record( array( 'email' => 'shopper@example.com' ) );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', self::LIST_ROUTE ) );

		$reference = $response->get_data()[0]['rules']['email'];
		$this->assertSame( $rule->id, $reference['id'] );
		$this->assertSame( 'block', $reference['action'] );
		// Creation time is exposed for the "Rule created" tooltip; a rule that was
		// never edited reports a null update time.
		$this->assertSame( mysql_to_rfc3339( $rule->created_at ), $reference['created_at'] );
		$this->assertNull( $reference['updated_at'] );
	}

	/**
	 * @testdox The list filters by status, outcome, payment method and search.
	 */
	public function test_list_applies_filters(): void {
		$this->record(
			array(
				'session_id'     => 'allowed',
				'trigger_type'   => 'blackbox',
				'final_status'   => 'allowed',
				'decision'       => 'allow',
				'email'          => 'good@example.com',
				'payment_method' => 'stripe',
			)
		);
		$this->record(
			array(
				'session_id'     => 'blocked',
				'trigger_type'   => 'block_rule',
				'final_status'   => 'blocked',
				'decision'       => 'allow',
				'email'          => 'bad@example.com',
				'payment_method' => 'ppcp',
			)
		);

		$by_status = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$by_status->set_param( 'final_status', 'blocked' );
		$this->assertSame( array( 'bad@example.com' ), array_column( $this->server->dispatch( $by_status )->get_data(), 'email' ) );

		$by_outcome = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$by_outcome->set_param( 'outcome', array( 'blocked_by_rules' ) );
		$outcome_rows = $this->server->dispatch( $by_outcome )->get_data();
		$this->assertCount( 1, $outcome_rows );
		$this->assertSame( 'bad@example.com', $outcome_rows[0]['email'] );

		$by_method = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$by_method->set_param( 'payment_method', array( 'stripe' ) );
		$this->assertSame( array( 'good@example.com' ), array_column( $this->server->dispatch( $by_method )->get_data(), 'email' ) );

		$by_search = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$by_search->set_param( 'search', 'bad@' );
		$this->assertSame( array( 'bad@example.com' ), array_column( $this->server->dispatch( $by_search )->get_data(), 'email' ) );
	}

	/**
	 * Create the two demo rules and three attempts (two ruled, one clean).
	 */
	private function seed_rule_filter_data(): void {
		$this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'ruled@example.com',
			)
		);
		$this->rule_store->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '198.51.100.10',
			)
		);

		$this->record(
			array(
				'session_id' => 'ruled-email',
				'email'      => 'ruled@example.com',
				'ip'         => '203.0.113.1',
			)
		);
		$this->record(
			array(
				'session_id' => 'ruled-ip',
				'email'      => 'clean1@example.com',
				'ip'         => '198.51.100.10',
			)
		);
		$this->record(
			array(
				'session_id' => 'clean',
				'email'      => 'clean2@example.com',
				'ip'         => '203.0.113.2',
			)
		);
	}

	/**
	 * @testdox The list can exclude attempts an active rule currently targets.
	 */
	public function test_list_filters_without_rules(): void {
		$this->seed_rule_filter_data();

		$request = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$request->set_param( 'rules', 'without' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '1', $response->get_headers()['X-WP-Total'] );
		$this->assertSame( array( 'clean2@example.com' ), array_column( $response->get_data(), 'email' ) );
	}

	/**
	 * @testdox The list can keep only attempts an active rule currently targets.
	 */
	public function test_list_filters_with_rules(): void {
		$this->seed_rule_filter_data();

		$request = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$request->set_param( 'rules', 'with' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '2', $response->get_headers()['X-WP-Total'] );
		$emails = array_column( $response->get_data(), 'email' );
		sort( $emails );
		$this->assertSame( array( 'clean1@example.com', 'ruled@example.com' ), $emails );
	}

	/**
	 * @testdox The list can be sorted by provider (ordered by the resolved title).
	 */
	public function test_list_sorts_by_provider(): void {
		$this->record(
			array(
				'session_id'     => 'a',
				'payment_method' => 'stripe',
				'email'          => 'a@example.com',
			)
		);
		$this->record(
			array(
				'session_id'     => 'b',
				'payment_method' => 'ppcp',
				'email'          => 'b@example.com',
			)
		);

		$request = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$request->set_param( 'orderby', 'payment_method' );
		$request->set_param( 'order', 'asc' );
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		// Unregistered gateways resolve to their id, so ascending title order is
		// ppcp then stripe.
		$this->assertSame(
			array( 'b@example.com', 'a@example.com' ),
			array_column( $response->get_data(), 'email' )
		);
	}

	/**
	 * @testdox An invalid sort column is rejected.
	 */
	public function test_list_rejects_unknown_sort_column(): void {
		$request = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$request->set_param( 'orderby', 'risk_score' );

		$this->assertSame( 400, $this->server->dispatch( $request )->get_status() );
	}

	/**
	 * @testdox An uninstalled schema returns an empty list.
	 */
	public function test_returns_empty_without_schema(): void {
		delete_option( SchemaManager::DB_VERSION_OPTION );

		$list = $this->server->dispatch( new \WP_REST_Request( 'GET', self::LIST_ROUTE ) );
		$this->assertSame( 200, $list->get_status() );
		$this->assertSame( array(), $list->get_data() );
		$this->assertSame( '0', $list->get_headers()['X-WP-Total'] );
	}

	/**
	 * @testdox Unauthenticated and unauthorized users cannot read the sessions.
	 */
	public function test_permissions_require_woocommerce_management(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->server->dispatch( new \WP_REST_Request( 'GET', self::LIST_ROUTE ) )->get_status() );

		$customer_id = wc_create_new_customer( 'sessions-customer@example.com', 'sessions-customer', 'password' );
		wp_set_current_user( $customer_id );
		$this->assertSame( 403, $this->server->dispatch( new \WP_REST_Request( 'GET', self::LIST_ROUTE ) )->get_status() );
	}

	/**
	 * @testdox The payment methods route lists the providers present in retained attempts, with resolved titles.
	 */
	public function test_payment_methods_lists_providers(): void {
		$this->record( array( 'payment_method' => 'stripe' ) );
		$this->record( array( 'payment_method' => 'ppcp' ) );
		$this->record( array( 'payment_method' => 'stripe' ) );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', self::PAYMENT_METHODS_ROUTE ) );
		$this->assertSame( 200, $response->get_status() );

		$data = $response->get_data();
		$ids  = array_column( $data, 'id' );
		sort( $ids );
		$this->assertSame( array( 'ppcp', 'stripe' ), $ids );
		foreach ( $data as $option ) {
			$this->assertArrayHasKey( 'title', $option );
			// An unregistered gateway resolves to its id.
			$this->assertSame( $option['id'], $option['title'] );
		}
	}

	/**
	 * @testdox The payment methods route returns empty without an installed schema.
	 */
	public function test_payment_methods_empty_without_schema(): void {
		delete_option( SchemaManager::DB_VERSION_OPTION );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', self::PAYMENT_METHODS_ROUTE ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	/**
	 * @testdox Unauthorized users cannot read the payment method options.
	 */
	public function test_payment_methods_permissions_require_woocommerce_management(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 401, $this->server->dispatch( new \WP_REST_Request( 'GET', self::PAYMENT_METHODS_ROUTE ) )->get_status() );
	}

	/**
	 * @testdox The rules filter matches a stored IPv6 address regardless of its text form.
	 */
	public function test_list_filters_rules_normalize_ipv6(): void {
		$this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '2001:db8::1',
			)
		);
		// Stored in an expanded, upper-case — but equivalent — form.
		$this->record(
			array(
				'session_id' => 'ipv6-ruled',
				'email'      => 'ipv6@example.com',
				'ip'         => '2001:0DB8:0000:0000:0000:0000:0000:0001',
			)
		);
		$this->record(
			array(
				'session_id' => 'ipv6-clean',
				'email'      => 'clean@example.com',
				'ip'         => '2001:db8::2',
			)
		);

		$with = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$with->set_param( 'rules', 'with' );
		$this->assertSame(
			array( 'ipv6@example.com' ),
			array_column( $this->server->dispatch( $with )->get_data(), 'email' )
		);

		$without = new \WP_REST_Request( 'GET', self::LIST_ROUTE );
		$without->set_param( 'rules', 'without' );
		$this->assertSame(
			array( 'clean@example.com' ),
			array_column( $this->server->dispatch( $without )->get_data(), 'email' )
		);
	}
}
