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
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;
use Automattic\WooCommerce\FraudProtection\SessionIdNormalizer;
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

	/** @var SessionEventStore */
	private SessionEventStore $event_store;

	/** Set up test fixtures. */
	public function setUp(): void {
		parent::setUp();
		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( FraudProtectionLogger::class ) );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_rules_table_schema() );
		dbDelta( $this->schema_manager->get_sessions_table_schema() );
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );
		$this->rule_store = new RuleStore();
		$this->rule_store->init( $this->schema_manager );
		$this->event_store = new SessionEventStore();
		$this->event_store->init( $this->schema_manager );
		$this->sut = new RulesRestController();
		$this->sut->init(
			$this->rule_store,
			$this->schema_manager,
			wc_get_container()->get( SessionEventStore::class ),
			wc_get_container()->get( ApiClient::class ),
			new SessionIdNormalizer(),
			wc_get_container()->get( SettingsTelemetry::class )
		);
		$this->sut->register_routes();
		wp_set_current_user( 1 );
	}

	/** Tear down test fixtures. */
	public function tearDown(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_rules_table_name() );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_sessions_table_name() );
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
	 * @testdox Date filters accept UTC user-date bounds and include both boundaries.
	 */
	public function test_get_rules_date_filters_are_utc_and_inclusive(): void {
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
		$this->set_created_at( $before->id, '2026-09-15 02:59:59' );
		$this->set_created_at( $inside_start->id, '2026-09-15 03:00:00' );
		$this->set_created_at( $inside_end->id, '2026-09-16 02:59:59' );
		$this->set_created_at( $after->id, '2026-09-16 03:00:00' );

		$request = new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' );
		$request->set_query_params(
			array(
				'from'     => '2026-09-15T03:00:00Z',
				'to'       => '2026-09-16T02:59:59Z',
				'per_page' => 1,
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 2, $response->get_data()['totalItems'] );
		$this->assertSame( 2, $response->get_data()['totalPages'] );
		$this->assertSame( '2026-09-16T02:59:59Z', $response->get_data()['data'][0]['created_at'] );
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
	 * Record a representative checkout attempt and return its row ID.
	 *
	 * @param array<string, mixed> $overrides Event values to replace.
	 * @return int
	 */
	private function record_event( array $overrides = array() ): int {
		$event = array_merge(
			array(
				'session_id'       => 'context-session',
				'source'           => 'blocks_checkout',
				'decision'         => 'block',
				'final_status'     => 'blocked',
				'trigger_type'     => 'blackbox',
				'risk_score'       => 0.9,
				'email'            => 'customer@example.com',
				'ip'               => '203.0.113.9',
				'ip_country'       => 'US',
				'billing_country'  => 'US',
				'billing_state'    => 'CA',
				'billing_city'     => 'San Francisco',
				'billing_postcode' => '94110',
				'billing_name'     => 'Test shopper',
				'order_id'         => 0,
				'payment_method'   => 'card',
			),
			$overrides
		);
		$this->event_store->record_event( $event );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . $this->schema_manager->get_sessions_table_name() );
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
	 * @testdox Exact value filters preserve supported email characters.
	 */
	public function test_get_rules_exact_value_filter_preserves_supported_email_characters(): void {
		$this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'buyer%20tag@example.com',
			)
		);

		$request = new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' );
		$request->set_query_params(
			array(
				'type'  => 'email',
				'value' => 'buyer%20tag@example.com',
			)
		);
		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['totalItems'] );
		$this->assertSame( 'buyer%20tag@example.com', $response->get_data()['data'][0]['value'] );
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
		$this->sut->init(
			$rule_store,
			$this->schema_manager,
			wc_get_container()->get( SessionEventStore::class ),
			wc_get_container()->get( ApiClient::class ),
			new SessionIdNormalizer(),
			wc_get_container()->get( SettingsTelemetry::class )
		);

		$response = $this->server->dispatch( new \WP_REST_Request( 'GET', '/wc-fraud-protection/v1/rules' ) );
		$error    = $response->as_error();

		$this->assertSame( 500, $response->get_status() );
		$this->assertInstanceOf( \WP_Error::class, $error );
		$this->assertSame( 'woocommerce_fraud_protection_rules_not_loaded', $error->get_error_code() );
		$this->assertSame( 'The fraud prevention rules could not be loaded.', $error->get_error_message() );
		$this->assertSame( 500, $error->get_error_data()['status'] );
	}

	/**
	 * @testdox An authorized create normalizes the exact value and returns the public rule.
	 */
	public function test_create_rule_normalizes_value_and_returns_public_rule(): void {
		$request = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/rules' );
		$request->set_body_params(
			array(
				'action' => 'allow',
				'type'   => 'email',
				'value'  => ' Shopper@Example.com ',
				'origin' => 'rules',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'shopper@example.com', $response->get_data()['value'] );
		$this->assertSame( 'allow', $response->get_data()['action'] );
		$this->assertArrayNotHasKey( 'source_session_id', $response->get_data() );
	}

	/**
	 * @testdox A manual create does not send contextual feedback and records its origin.
	 */
	public function test_manual_create_does_not_report_and_tracks_rules_origin(): void {
		$api_client = $this->createMock( ApiClient::class );
		$api_client->expects( $this->never() )->method( 'report' );
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->once() )->method( 'record_rule_change' )->with( 'create', FraudDecision::Allow, 'email', 'rules' );
		$this->sut->init(
			$this->rule_store,
			$this->schema_manager,
			$this->event_store,
			$api_client,
			new SessionIdNormalizer(),
			$telemetry
		);

		$request = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/rules' );
		$request->set_body_params(
			array(
				'action' => 'allow',
				'type'   => 'email',
				'value'  => 'manual@example.com',
				'origin' => 'rules',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$rule = $this->rule_store->get_rule( (int) $response->get_data()['id'] );
		$this->assertSame( 'rules', $rule->source_meta['origin'] );
	}

	/**
	 * @testdox Contextual creation rejects an event without a stored session ID.
	 */
	public function test_contextual_create_requires_stored_session_id(): void {
		$api_client = $this->createMock( ApiClient::class );
		$api_client->expects( $this->never() )->method( 'report' );
		$this->sut->init(
			$this->rule_store,
			$this->schema_manager,
			$this->event_store,
			$api_client,
			new SessionIdNormalizer(),
			$this->createMock( SettingsTelemetry::class )
		);
		$event_id = $this->record_event( array( 'session_id' => '' ) );

		$request = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/rules' );
		$request->set_body_params(
			array(
				'action'              => 'allow',
				'type'                => 'email',
				'value'               => 'customer@example.com',
				'recorded_attempt_id' => $event_id,
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_fraud_protection_recorded_attempt_invalid', $response->as_error()->get_error_code() );
	}

	/**
	 * @testdox Contextual creation rejects a value that does not match the stored event.
	 */
	public function test_contextual_create_requires_exact_event_value(): void {
		$api_client = $this->createMock( ApiClient::class );
		$api_client->expects( $this->never() )->method( 'report' );
		$this->sut->init(
			$this->rule_store,
			$this->schema_manager,
			$this->event_store,
			$api_client,
			new SessionIdNormalizer(),
			$this->createMock( SettingsTelemetry::class )
		);
		$event_id = $this->record_event();

		$request = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/rules' );
		$request->set_body_params(
			array(
				'action'              => 'allow',
				'type'                => 'email',
				'value'               => 'other@example.com',
				'recorded_attempt_id' => $event_id,
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'woocommerce_fraud_protection_recorded_attempt_mismatch', $response->as_error()->get_error_code() );
	}

	/**
	 * @testdox A duplicate create returns the active rule ID and action.
	 */
	public function test_create_rule_duplicate_returns_existing_rule_details(): void {
		$existing = $this->rule_store->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '203.0.113.10',
			)
		);
		$request  = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/rules' );
		$request->set_body_params(
			array(
				'action' => 'allow',
				'type'   => 'ip',
				'value'  => '203.0.113.10',
			)
		);

		$response = $this->server->dispatch( $request );
		$error    = $response->as_error();

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'woocommerce_fraud_protection_duplicate_rule', $error->get_error_code() );
		$this->assertSame( $existing->id, $error->get_error_data()['rule_id'] );
		$this->assertSame( 'block', $error->get_error_data()['action'] );
	}

	/**
	 * @testdox A contextual create uses the stored event and sends feedback after writing.
	 */
	public function test_create_rule_context_uses_stored_event(): void {
		$api_client = $this->createMock( ApiClient::class );
		$api_client->expects( $this->once() )
			->method( 'report' )
			->with(
				'stored-session',
				$this->callback(
					static function ( array $payload ): bool {
						return str_starts_with( (string) ( $payload['report_id'] ?? '' ), 'wc-fraud-protection-rule-' ) && 'good' === ( $payload['asserted_label'] ?? null );
					}
				)
			)
			->willThrowException( new \RuntimeException( 'transport failed' ) );
		$this->sut->init(
			$this->rule_store,
			$this->schema_manager,
			$this->event_store,
			$api_client,
			new SessionIdNormalizer(),
			wc_get_container()->get( SettingsTelemetry::class )
		);
		$this->event_store->record_event(
			array(
				'session_id'       => 'stored-session',
				'source'           => 'blocks_checkout',
				'decision'         => 'block',
				'final_status'     => 'blocked',
				'trigger_type'     => 'blackbox',
				'risk_score'       => 0.9,
				'email'            => 'customer@example.com',
				'ip'               => '203.0.113.9',
				'ip_country'       => 'US',
				'billing_country'  => 'US',
				'billing_state'    => 'CA',
				'billing_city'     => 'San Francisco',
				'billing_postcode' => '94110',
				'billing_name'     => 'Test shopper',
				'order_id'         => 0,
				'payment_method'   => 'card',
			)
		);
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$event_id = (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . $this->schema_manager->get_sessions_table_name() );
		$request  = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/rules' );
		$request->set_body_params(
			array(
				'action'              => 'allow',
				'type'                => 'email',
				'value'               => 'customer@example.com',
				'recorded_attempt_id' => $event_id,
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$rule = $this->rule_store->get_rule( (int) $response->get_data()['id'] );
		$this->assertSame( 'stored-session', $rule->source_session_id );
	}

	/**
	 * @testdox An allowed contextual outcome suggests Block and sends bad feedback.
	 */
	public function test_create_rule_allowed_context_reports_bad_feedback(): void {
		$api_client = $this->createMock( ApiClient::class );
		$api_client->expects( $this->once() )
			->method( 'report' )
			->with(
				'context-session',
				$this->callback(
					static function ( array $payload ): bool {
						return 'bad' === ( $payload['asserted_label'] ?? null );
					}
				)
			);
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->once() )->method( 'record_rule_change' )->with( 'create', FraudDecision::Block, 'ip', 'checkout_attempts' );
		$this->sut->init(
			$this->rule_store,
			$this->schema_manager,
			$this->event_store,
			$api_client,
			new SessionIdNormalizer(),
			$telemetry
		);
		$event_id = $this->record_event( array( 'final_status' => 'allowed' ) );

		$request = new \WP_REST_Request( 'POST', '/wc-fraud-protection/v1/rules' );
		$request->set_body_params(
			array(
				'action'              => 'block',
				'type'                => 'ip',
				'value'               => '203.0.113.9',
				'recorded_attempt_id' => $event_id,
				'origin'              => 'checkout_attempts',
			)
		);

		$response = $this->server->dispatch( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'block', $response->get_data()['action'] );
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
