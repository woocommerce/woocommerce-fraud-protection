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
		$this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => ' Shopper@Example.com ',
			),
			'private-session'
		);

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['totalItems'] );
		$this->assertSame( array( 'id', 'action', 'value', 'type', 'created_at' ), array_keys( $response->get_data()['data'][0] ) );
		$this->assertSame( 'shopper@example.com', $response->get_data()['data'][0]['value'] );
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
