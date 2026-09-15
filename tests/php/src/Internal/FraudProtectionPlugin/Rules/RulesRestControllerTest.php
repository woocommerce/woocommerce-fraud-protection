<?php
/**
 * RulesRestControllerTest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RulesRestController;
use Automattic\WooCommerce\Proxies\LegacyProxy;

/**
 * Tests for the merchant rules read endpoint.
 */
class RulesRestControllerTest extends \WC_REST_Unit_Test_Case {

	/** @var SchemaManager */
	private SchemaManager $schema_manager;

	/** @var RuleStore */
	private RuleStore $rule_store;

	/** @var RulesRestController */
	private RulesRestController $sut;

	/** Set up test fixtures. */
	public function setUp(): void {
		parent::setUp();
		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( FraudProtectionLogger::class ) );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_rules_table_schema() );
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );
		$this->rule_store = new RuleStore();
		$this->rule_store->init( $this->schema_manager );
		$this->sut = new RulesRestController();
		$this->sut->init( $this->rule_store, $this->schema_manager );
		$this->sut->register_routes();
		wp_set_current_user( 1 );
	}

	/** Tear down test fixtures. */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_rules_table_name() );
		delete_option( SchemaManager::DB_VERSION_OPTION );
		parent::tearDown();
	}

	/**
	 * @testdox An authorized read returns only normalized public active rule fields with totals.
	 */
	public function test_get_rules_returns_public_page(): void {
		$rule = $this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => ' Shopper@Example.com ',
			),
			'private-session'
		);
		$this->set_created_at( $rule->id, '2026-09-14 12:00:00' );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['totalItems'] );
		$this->assertSame( array( 'id', 'action', 'value', 'type', 'created_at' ), array_keys( $response->get_data()['data'][0] ) );
		$this->assertSame( 'shopper@example.com', $response->get_data()['data'][0]['value'] );
		$this->assertSame( '2026-09-14T12:00:00Z', $response->get_data()['data'][0]['created_at'] );
	}

	/**
	 * @testdox Date filters use the site timezone and include both date boundaries.
	 */
	public function test_get_rules_date_filters_are_site_local_and_inclusive(): void {
		$original_timezone = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/Sao_Paulo' );
		$before       = $this->rule_store->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'before@example.com',
			)
		);
		$inside_start = $this->rule_store->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'start@example.com',
			)
		);
		$inside_end   = $this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'end@example.com',
			)
		);
		$after        = $this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'after@example.com',
			)
		);
		$this->set_created_at( $before->id, '2026-09-13 02:59:59' );
		$this->set_created_at( $inside_start->id, '2026-09-13 03:00:00' );
		$this->set_created_at( $inside_end->id, '2026-09-14 02:59:59' );
		$this->set_created_at( $after->id, '2026-09-14 03:00:00' );

		$request = new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' );
		$request->set_query_params(
			array(
				'from'     => '2026-09-13',
				'to'       => '2026-09-13',
				'per_page' => 1,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['totalItems'] );
		$this->assertSame( 2, $response->get_data()['totalPages'] );
		$this->assertSame( '2026-09-14T02:59:59Z', $response->get_data()['data'][0]['created_at'] );
		update_option( 'timezone_string', $original_timezone );
	}

	/**
	 * Set the creation time for a stored rule.
	 *
	 * @param int    $id Rule ID.
	 * @param string $created_at UTC timestamp.
	 */
	private function set_created_at( int $id, string $created_at ): void {
		global $wpdb;
		$table = $this->schema_manager->get_rules_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE id = %d", $created_at, $id ) );
	}

	/**
	 * @testdox An exact value and action filter uses the active rule set.
	 */
	public function test_get_rules_filters_exact_value(): void {
		$this->rule_store->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'allow@example.com',
			)
		);
		$this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'block@example.com',
			)
		);

		$request = new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' );
		$request->set_query_params(
			array(
				'action' => 'block',
				'type'   => 'email',
				'value'  => 'BLOCK@EXAMPLE.COM',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['totalItems'] );
		$this->assertSame( 'block@example.com', $response->get_data()['data'][0]['value'] );
	}

	/**
	 * @testdox The rules endpoint accepts the supported server sort fields and direction.
	 */
	public function test_get_rules_sorts_on_the_server(): void {
		$this->rule_store->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'zulu@example.com',
			)
		);
		$this->rule_store->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'alpha@example.com',
			)
		);

		$request = new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' );
		$request->set_query_params(
			array(
				'orderby' => 'value',
				'order'   => 'asc',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'alpha@example.com', $response->get_data()['data'][0]['value'] );
		$this->assertSame( 'zulu@example.com', $response->get_data()['data'][1]['value'] );
	}

	/**
	 * @testdox A missing rules schema returns a generic 503 error.
	 */
	public function test_get_rules_returns_503_when_schema_is_unavailable(): void {
		update_option( SchemaManager::DB_VERSION_OPTION, 0 );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' ) );
		$error    = $response->as_error();

		$this->assertSame( 503, $response->get_status() );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'woocommerce_fraud_protection_rules_not_loaded', $error->get_error_code() );
		$this->assertSame( 'The fraud prevention rules could not be loaded.', $error->get_error_message() );
		$this->assertSame( 503, $error->get_error_data()['status'] );
	}

	/**
	 * @testdox A rules query failure returns a generic 500 error.
	 */
	public function test_get_rules_returns_500_when_query_fails(): void {
		$rule_store = $this->createMock( RuleStore::class );
		$rule_store->expects( $this->once() )->method( 'get_active_rules_page' )->willThrowException( new \RuntimeException( 'database details' ) );
		$this->sut->init( $rule_store, $this->schema_manager );

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' ) );
		$error    = $response->as_error();

		$this->assertSame( 500, $response->get_status() );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'woocommerce_fraud_protection_rules_not_loaded', $error->get_error_code() );
		$this->assertSame( 'The fraud prevention rules could not be loaded.', $error->get_error_message() );
		$this->assertSame( 500, $error->get_error_data()['status'] );
	}

	/**
	 * @testdox Unauthenticated and unauthorized users cannot read rules.
	 */
	public function test_permissions_require_woocommerce_management(): void {
		wp_set_current_user( 0 );
		$unauthenticated = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' ) );
		$customer_id     = wc_create_new_customer( 'rules-customer@example.com', 'rules-customer', 'password' );
		wp_set_current_user( $customer_id );
		$unauthorized = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' ) );

		$this->assertSame( 401, $unauthenticated->get_status() );
		$this->assertSame( 403, $unauthorized->get_status() );
	}
}
