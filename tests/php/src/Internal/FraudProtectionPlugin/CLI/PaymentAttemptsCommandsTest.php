<?php
/**
 * PaymentAttemptsCommandsTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\SessionEventFixtures;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\CommandOutput;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\PaymentAttemptsCommands;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\ConditionOperatorRegistry;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\PaymentMethodTitleResolver;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionRuleFinder;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WP_CLI;
use WP_CLI\Formatter;

require_once __DIR__ . '/../../../../stubs/wp-cli-formatter.php';

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch
/**
 * Exception used to model WP-CLI exits in the payment attempts command tests.
 */
class PaymentAttemptsCommandExit extends \RuntimeException {}
// phpcs:enable Squiz.Classes.ClassFileName.NoMatch

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Tests for PaymentAttemptsCommands.
 */
class PaymentAttemptsCommandsTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var PaymentAttemptsCommands
	 */
	private $sut;

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
	 * Registered commands and their registration arguments.
	 *
	 * @var array<string, array>
	 */
	private array $commands = array();

	/**
	 * WP-CLI output, as [type, message] pairs.
	 *
	 * @var array<int, array{string, string}>
	 */
	private array $output = array();

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		Formatter::$calls = array();
		$this->register_legacy_proxy_static_mocks(
			array(
				WP_CLI::class => array(
					'add_command' => function ( string $name, callable $callback, array $args = array() ): void {
						unset( $callback );
						$this->commands[ $name ] = $args;
					},
					'line'        => function ( string $message ): void {
						$this->output[] = array( 'line', $message );
					},
					'error'       => function ( string $message ): void {
						$this->output[] = array( 'error', $message );
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test models the WP-CLI exit.
						throw new PaymentAttemptsCommandExit( $message );
					},
				),
			)
		);

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
		$output = new CommandOutput();
		$output->init( wc_get_container()->get( LegacyProxy::class ) );

		$this->sut = new PaymentAttemptsCommands();
		$this->sut->init( $this->event_store, $rule_finder, new PaymentMethodTitleResolver(), $this->schema_manager, $output );
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
		Formatter::$calls = array();

		parent::tearDown();
	}

	/**
	 * Run a command, capturing its WP-CLI exit.
	 *
	 * @param string               $method     Command method.
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return bool False when the command exited through WP_CLI::error().
	 */
	private function run_command( string $method, array $args = array(), array $assoc_args = array() ): bool {
		try {
			$this->sut->$method( $args, $assoc_args );
		} catch ( PaymentAttemptsCommandExit $exit ) {
			return false;
		}

		return true;
	}

	/**
	 * Get the output messages of a type.
	 *
	 * @param string $type Output type.
	 * @return string[]
	 */
	private function messages( string $type ): array {
		return array_column( array_filter( $this->output, fn( array $entry ): bool => $type === $entry[0] ), 1 );
	}

	/**
	 * Record an event and return its ID.
	 *
	 * @param array<string, mixed> $overrides Event fields to override.
	 * @return int
	 */
	private function an_attempt( array $overrides = array() ): int {
		global $wpdb;

		$this->event_store->record_event( SessionEventFixtures::an_event( $overrides ) );

		return (int) $wpdb->insert_id;
	}

	/**
	 * @testdox Registers the list and get command leaves.
	 */
	public function test_registers_command_leaves(): void {
		$this->sut->register_commands();

		$this->assertSame(
			array( 'wc fraud-protection payment-attempts list', 'wc fraud-protection payment-attempts get' ),
			array_keys( $this->commands )
		);
		$list = array_column( $this->commands['wc fraud-protection payment-attempts list']['synopsis'], null, 'name' );
		$this->assertSame( SessionEventStore::SORTABLE_COLUMNS, $list['orderby']['options'] );
		$this->assertSame( '30', $list['days']['default'] );
	}

	/**
	 * @testdox Lists attempts with their outcome and the active rules that target their values.
	 */
	public function test_list_shows_outcome_and_rule_ids(): void {
		$rule = $this->rule_store->create_rule( FraudDecision::Block, RuleManager::build_conditions( 'email', 'customer@example.com' ) );
		$id   = $this->an_attempt( array( 'payment_method' => '' ) );

		$this->assertTrue( $this->run_command( 'list_attempts' ) );

		$this->assertSame(
			array( 'id', 'recorded_at', 'outcome', 'email', 'ip', 'ip_country', 'payment_method', 'order_id', 'email_rule_id', 'ip_rule_id' ),
			Formatter::$calls[0]['fields']
		);
		$item = Formatter::$calls[0]['data'][0];
		$this->assertSame( $id, $item['id'] );
		$this->assertSame( 'flagged_by_fraud_prevention', $item['outcome'], 'A received block that was allowed is flagged' );
		$this->assertSame( $rule->id, $item['email_rule_id'] );
		$this->assertNull( $item['ip_rule_id'] );
		$this->assertNull( $item['payment_method'] );
		$this->assertNull( $item['payment_method_title'] );
		$this->assertSame( 123, $item['order_id'] );
	}

	/**
	 * @testdox Applies the filters, order and limit.
	 */
	public function test_list_filters_sorts_and_limits(): void {
		$this->an_attempt( array( 'email' => 'b@example.com' ) );
		$this->an_attempt( array( 'email' => 'a@example.com' ) );
		$this->an_attempt( array( 'email' => 'c@example.com' ) );
		$this->an_attempt(
			array(
				'email'        => 'blocked@example.com',
				'final_status' => 'blocked',
			)
		);

		$this->run_command(
			'list_attempts',
			array(),
			array(
				'outcome' => 'flagged_by_fraud_prevention',
				'orderby' => 'email',
				'order'   => 'asc',
				'limit'   => '2',
			)
		);
		$this->run_command( 'list_attempts', array(), array( 'final_status' => 'blocked' ) );
		$this->run_command( 'list_attempts', array(), array( 'search' => 'c@' ) );

		$this->assertSame( array( 'a@example.com', 'b@example.com' ), array_column( Formatter::$calls[0]['data'], 'email' ) );
		$this->assertSame( array( 'blocked@example.com' ), array_column( Formatter::$calls[1]['data'], 'email' ) );
		$this->assertSame( array( 'c@example.com' ), array_column( Formatter::$calls[2]['data'], 'email' ) );
	}

	/**
	 * @testdox Lists only attempts with or without a matching rule.
	 */
	public function test_list_rules_filter(): void {
		$this->rule_store->create_rule( FraudDecision::Block, RuleManager::build_conditions( 'ip', '192.0.2.10' ) );
		$with    = $this->an_attempt( array( 'ip' => '192.0.2.10' ) );
		$without = $this->an_attempt( array( 'ip' => '192.0.2.11' ) );

		$this->run_command( 'list_attempts', array(), array( 'rules' => 'with' ) );
		$this->run_command( 'list_attempts', array(), array( 'rules' => 'without' ) );

		$this->assertSame( array( $with ), array_column( Formatter::$calls[0]['data'], 'id' ) );
		$this->assertSame( array( $without ), array_column( Formatter::$calls[1]['data'], 'id' ) );
	}

	/**
	 * @testdox The count format reports the total matching attempts regardless of the limit.
	 */
	public function test_list_count_format(): void {
		$this->an_attempt();
		$this->an_attempt();
		$this->an_attempt();

		$this->run_command(
			'list_attempts',
			array(),
			array(
				'format' => 'count',
				'limit'  => '1',
			)
		);

		$this->assertSame( array( '3' ), $this->messages( 'line' ) );
	}

	/**
	 * @testdox Invalid filter values are rejected.
	 * @dataProvider invalid_filter_provider
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $expected   Expected error substring.
	 */
	public function test_list_rejects_invalid_filters( array $assoc_args, string $expected ): void {
		$this->assertFalse( $this->run_command( 'list_attempts', array(), $assoc_args ) );

		$this->assertStringContainsString( $expected, $this->messages( 'error' )[0] );
	}

	/**
	 * Invalid filter values.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function invalid_filter_provider(): array {
		return array(
			'unknown outcome'      => array( array( 'outcome' => 'allowed,refunded' ), 'Unknown outcome "refunded"' ),
			'unknown final status' => array( array( 'final_status' => 'pending' ), 'Use one of: allowed or blocked.' ),
			'invalid days'         => array( array( 'days' => '-1' ), 'The number of days must be a positive whole number.' ),
			'invalid limit'        => array( array( 'limit' => 'all' ), 'The limit must be a positive whole number.' ),
		);
	}

	/**
	 * @testdox Shows every field of one attempt, and errors for an unknown attempt.
	 */
	public function test_get_attempt(): void {
		$id = $this->an_attempt();

		$this->assertTrue( $this->run_command( 'get_attempt', array( (string) $id ) ) );
		$this->assertFalse( $this->run_command( 'get_attempt', array( (string) ( $id + 1 ) ) ) );

		$item = Formatter::$calls[0]['data'];
		$this->assertSame( 'display_item', Formatter::$calls[0]['method'] );
		$this->assertSame( $id, $item['id'] );
		$this->assertSame( 'San Francisco', $item['billing_city'] );
		$this->assertSame( 'woocommerce_payments', $item['payment_method'] );
		$this->assertSame( array( 'The payment attempt could not be found.' ), $this->messages( 'error' ) );
	}

	/**
	 * @testdox Commands stop when the database schema is not installed.
	 */
	public function test_commands_require_schema(): void {
		update_option( SchemaManager::DB_VERSION_OPTION, 0 );

		$this->assertFalse( $this->run_command( 'list_attempts' ) );
		$this->assertFalse( $this->run_command( 'get_attempt', array( '1' ) ) );

		$this->assertCount( 2, $this->messages( 'error' ) );
		$this->assertStringContainsString( 'database schema is incomplete', $this->messages( 'error' )[0] );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
