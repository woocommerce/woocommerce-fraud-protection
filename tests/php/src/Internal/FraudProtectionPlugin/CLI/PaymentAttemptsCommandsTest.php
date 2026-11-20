<?php
/**
 * PaymentAttemptsCommandsTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\FailingRuleQuery;
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
	 * Arguments passed to WP_CLI::confirm(), or null when it was not called.
	 *
	 * @var ?array
	 */
	private ?array $confirmed = null;

	/**
	 * Whether WP_CLI::confirm() answers no.
	 *
	 * @var bool
	 */
	private bool $decline = false;

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
					'success'     => function ( string $message ): void {
						$this->output[] = array( 'success', $message );
					},
					'warning'     => function ( string $message ): void {
						$this->output[] = array( 'warning', $message );
					},
					'error'       => function ( string $message ): void {
						$this->output[] = array( 'error', $message );
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test models the WP-CLI exit.
						throw new PaymentAttemptsCommandExit( $message );
					},
					'confirm'     => function ( string $question, array $assoc_args ): void {
						$this->confirmed = array( $question, $assoc_args );
						if ( $this->decline && empty( $assoc_args['yes'] ) ) {
							throw new PaymentAttemptsCommandExit( 'declined' );
						}
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
		$output->init( wc_get_container()->get( LegacyProxy::class ), $this->schema_manager );

		$this->sut = new PaymentAttemptsCommands();
		$this->sut->init( $this->event_store, $rule_finder, new PaymentMethodTitleResolver(), $output );
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
	 * @testdox Registers the list, get, and delete command leaves.
	 */
	public function test_registers_command_leaves(): void {
		$this->sut->register_commands();

		$this->assertSame(
			array( 'wc fraud-protection payment-attempts list', 'wc fraud-protection payment-attempts get', 'wc fraud-protection payment-attempts delete' ),
			array_keys( $this->commands )
		);
		$list = array_column( $this->commands['wc fraud-protection payment-attempts list']['synopsis'], null, 'name' );
		$this->assertSame( SessionEventStore::SORTABLE_COLUMNS, $list['orderby']['options'] );
		$this->assertSame( '30', $list['days']['default'] );
		$delete = array_column( $this->commands['wc fraud-protection payment-attempts delete']['synopsis'], null, 'name' );
		$this->assertTrue( $delete['attempt']['repeating'] );
		$this->assertSame( array( 'id', 'email', 'ip' ), $delete['type']['options'] );
		$this->assertSame( 'flag', $delete['yes']['type'], 'Confirmation is skipped with the --yes flag' );
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
	 * @testdox Lists the attempts that delete matches for an exact email address or IP address.
	 */
	public function test_list_exact_value_filters_match_delete(): void {
		$email  = $this->an_attempt( array( 'email' => 'shopper@example.com' ) );
		$others = array(
			$this->an_attempt( array( 'email' => 'other.shopper@example.com' ) ),
			$this->an_attempt( array( 'email' => 'shopper@exámple.com' ) ),
			$this->an_attempt( array( 'ip' => '2001:db8::10' ) ),
		);
		$ip     = $this->an_attempt( array( 'ip' => '2001:db8::1' ) );

		$this->run_command( 'list_attempts', array(), array( 'email' => ' shopper@EXAMPLE.com' ) );
		$this->run_command( 'list_attempts', array(), array( 'ip' => '2001:DB8:0::1' ) );
		$this->assertTrue( $this->run_command( 'delete_attempts', array( 'shopper@EXAMPLE.com', '2001:DB8:0::1' ), array( 'yes' => true ) ) );

		$this->assertSame( array( $email ), array_column( Formatter::$calls[0]['data'], 'id' ), 'Addresses that contain the value or differ by accents are not listed' );
		$this->assertSame( array( $ip ), array_column( Formatter::$calls[1]['data'], 'id' ) );
		$this->assertSame( array( 'Delete 2 payment attempts?', array( 'yes' => true ) ), $this->confirmed, 'Delete matches the listed attempts' );
		$this->assertSame( $others, $this->remaining_ids(), 'Delete keeps the attempts that were not listed' );
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
	 * @testdox Reports an error instead of a wrong result when the active rules cannot be loaded.
	 * @dataProvider rule_load_failure_provider
	 *
	 * @param string               $method     Command method.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function test_reports_rule_load_failures( string $method, array $assoc_args ): void {
		$this->rule_store->create_rule( FraudDecision::Block, RuleManager::build_conditions( 'ip', '192.0.2.10' ) );
		$id = $this->an_attempt( array( 'ip' => '192.0.2.10' ) );

		$completed = FailingRuleQuery::active_ruleset(
			$this->schema_manager,
			fn() => $this->run_command( $method, 'get_attempt' === $method ? array( (string) $id ) : array(), $assoc_args )
		);

		$this->assertFalse( $completed, 'The command must exit with an error' );
		$this->assertSame( array( 'The payment activity could not be loaded.' ), $this->messages( 'error' ) );
		$this->assertSame( array(), Formatter::$calls, 'No attempts must be shown' );
	}

	/**
	 * Commands that read the active rules.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public function rule_load_failure_provider(): array {
		return array(
			'list with rules'    => array( 'list_attempts', array( 'rules' => 'with' ) ),
			'list without rules' => array( 'list_attempts', array( 'rules' => 'without' ) ),
			'list rule columns'  => array( 'list_attempts', array() ),
			'get rule columns'   => array( 'get_attempt', array() ),
		);
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
	 * @testdox An attempt recorded while the pages are read does not repeat or omit listed attempts.
	 */
	public function test_list_is_stable_when_attempts_are_recorded_between_pages(): void {
		$ids = array();
		for ( $i = 0; $i < SessionEventStore::MAX_PER_PAGE + 1; $i++ ) {
			$ids[] = $this->an_attempt();
		}

		// Records one more attempt right after the first page is read.
		$event_store = new class() extends SessionEventStore {
			/**
			 * Callback run once after the first page query.
			 *
			 * @var ?callable
			 */
			public $after_first_page = null;

			/**
			 * Query a page, then run the callback once.
			 *
			 * @param array<string, mixed> $args Query arguments.
			 * @return array{items: array<int, array<string, mixed>>, total: int}
			 */
			public function query_events( array $args = array() ): array {
				$result = parent::query_events( $args );
				if ( is_callable( $this->after_first_page ) ) {
					( $this->after_first_page )();
					$this->after_first_page = null;
				}

				return $result;
			}
		};
		$event_store->init( $this->schema_manager );
		$event_store->after_first_page = fn() => $this->an_attempt();
		$rule_finder                   = new SessionRuleFinder();
		$rule_finder->init( $this->rule_store, $this->schema_manager, new ConditionOperatorRegistry() );
		$output = new CommandOutput();
		$output->init( wc_get_container()->get( LegacyProxy::class ), $this->schema_manager );
		$this->sut = new PaymentAttemptsCommands();
		$this->sut->init( $event_store, $rule_finder, new PaymentMethodTitleResolver(), $output );

		$this->run_command( 'list_attempts', array(), array( 'limit' => (string) count( $ids ) ) );

		$listed = array_column( Formatter::$calls[0]['data'], 'id' );
		sort( $listed );
		$this->assertSame( $ids, $listed, 'The list holds each attempt that existed when it started, once' );
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
			'invalid email'        => array( array( 'email' => 'shopper' ), 'Enter a complete email address.' ),
			'invalid IP'           => array( array( 'ip' => '192.0.2' ), 'Enter a complete IP address.' ),
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
		$this->assertFalse( $this->run_command( 'delete_attempts', array( '1' ), array( 'yes' => true ) ) );

		$this->assertCount( 3, $this->messages( 'error' ) );
		$this->assertStringContainsString( 'database schema is incomplete', $this->messages( 'error' )[0] );
		$this->assertNull( $this->confirmed );
	}

	/**
	 * Get the IDs of the recorded attempts.
	 *
	 * @return int[]
	 */
	private function remaining_ids(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', $wpdb->get_col( 'SELECT id FROM ' . $this->schema_manager->get_sessions_table_name() . ' ORDER BY id' ) );
	}

	/**
	 * @testdox Delete detects IDs, email addresses and IP addresses, confirms with the number of matching attempts, and deletes each attempt once.
	 */
	public function test_delete_detects_argument_types(): void {
		$by_id    = $this->an_attempt( array( 'email' => 'other@example.com' ) );
		$by_email = $this->an_attempt( array( 'email' => 'shopper@example.com' ) );
		$by_both  = $this->an_attempt(
			array(
				'email' => 'shopper@example.com',
				'ip'    => '2001:db8::1',
			)
		);
		$kept     = $this->an_attempt( array( 'email' => 'kept@example.com' ) );
		$by_ip    = $this->an_attempt(
			array(
				'email' => 'ipv6@example.com',
				'ip'    => '2001:db8::1',
			)
		);

		$this->assertTrue( $this->run_command( 'delete_attempts', array( (string) $by_id, 'Shopper@Example.com', '2001:DB8:0::1', (string) $by_both ), array( 'yes' => true ) ) );

		$this->assertSame( array( 'Delete 4 payment attempts?', array( 'yes' => true ) ), $this->confirmed, 'An attempt matched by several arguments is counted once' );
		$this->assertSame( array(), Formatter::$calls, 'The attempts are not listed' );
		$this->assertSame( array( 'Deleted 4 payment attempts.' ), $this->messages( 'success' ) );
		$this->assertSame( array( $kept ), $this->remaining_ids() );
		$this->assertNotContains( $by_email, $this->remaining_ids() );
		$this->assertNotContains( $by_ip, $this->remaining_ids() );
	}

	/**
	 * @testdox Delete accepts every argument only as the type given with --type.
	 */
	public function test_delete_with_explicit_type(): void {
		$this->an_attempt( array( 'email' => 'shopper@example.com' ) );
		$kept = $this->an_attempt( array( 'ip' => '192.0.2.10' ) );

		$this->assertFalse( $this->run_command( 'delete_attempts', array( 'shopper@example.com', '192.0.2.10', (string) $kept ), array( 'type' => 'email' ) ) );

		$this->assertSame(
			array(
				'"192.0.2.10" is not an email address.',
				'"' . $kept . '" is not an email address.',
			),
			$this->messages( 'warning' )
		);
		$this->assertSame( array( 'Deleted 1 payment attempt. Some arguments matched no payment attempts.' ), $this->messages( 'error' ) );
		$this->assertSame( array( $kept ), $this->remaining_ids(), 'Only the attempt matched as an email address is deleted' );
	}

	/**
	 * @testdox Delete reports each argument that is not valid for its type.
	 * @dataProvider invalid_delete_argument_provider
	 *
	 * @param ?string $type     The --type value, if any.
	 * @param string  $expected Expected warning.
	 */
	public function test_delete_reports_invalid_arguments( ?string $type, string $expected ): void {
		$assoc_args = is_null( $type ) ? array() : array( 'type' => $type );

		$this->assertFalse( $this->run_command( 'delete_attempts', array( 'not-a-value' ), $assoc_args ) );

		$this->assertSame( array( $expected ), $this->messages( 'warning' ) );
		$this->assertSame( array( 'No matching payment attempts were found.' ), $this->messages( 'error' ) );
		$this->assertNull( $this->confirmed, 'Nothing is confirmed when nothing matches' );
	}

	/**
	 * Delete argument types and their warning for an invalid value.
	 *
	 * @return array<string, array{?string, string}>
	 */
	public function invalid_delete_argument_provider(): array {
		return array(
			'detected' => array( null, '"not-a-value" is not a payment attempt ID, an email address, or an IP address.' ),
			'id'       => array( 'id', '"not-a-value" is not a payment attempt ID.' ),
			'email'    => array( 'email', '"not-a-value" is not an email address.' ),
			'ip'       => array( 'ip', '"not-a-value" is not an IP address.' ),
		);
	}

	/**
	 * @testdox Delete rejects an unknown --type.
	 */
	public function test_delete_rejects_unknown_type(): void {
		$id = $this->an_attempt();

		$this->assertFalse( $this->run_command( 'delete_attempts', array( (string) $id ), array( 'type' => 'session' ) ) );

		$this->assertSame( array( 'Use one of: id, email, or ip.' ), $this->messages( 'error' ) );
		$this->assertSame( array( $id ), $this->remaining_ids() );
	}

	/**
	 * @testdox Declining the confirmation deletes nothing.
	 */
	public function test_delete_declined(): void {
		$id            = $this->an_attempt();
		$this->decline = true;

		$this->assertFalse( $this->run_command( 'delete_attempts', array( (string) $id ) ) );

		$this->assertSame( array( 'Delete 1 payment attempt?', array() ), $this->confirmed );
		$this->assertSame( array( $id ), $this->remaining_ids() );
	}

	/**
	 * @testdox Delete warns about unmatched arguments, deletes the matches, and exits with an error summary.
	 */
	public function test_delete_reports_unmatched_arguments(): void {
		$id = $this->an_attempt();

		$this->assertFalse( $this->run_command( 'delete_attempts', array( (string) $id, (string) ( $id + 1 ), 'nobody@example.com' ), array( 'yes' => true ) ) );

		$this->assertSame(
			array(
				'No payment attempt matches "' . ( $id + 1 ) . '".',
				'No payment attempt matches "nobody@example.com".',
			),
			$this->messages( 'warning' )
		);
		$this->assertSame( array( 'Deleted 1 payment attempt. Some arguments matched no payment attempts.' ), $this->messages( 'error' ) );
		$this->assertSame( array(), $this->remaining_ids() );
	}

	/**
	 * @testdox Delete continues past a failed batch and reports how many attempts were deleted.
	 */
	public function test_delete_reports_failed_batches(): void {
		$ids = array();
		for ( $i = 0; $i < 101; $i++ ) {
			$ids[] = $this->an_attempt();
		}

		// Fails the first delete query.
		$event_store = new class() extends SessionEventStore {
			/**
			 * Whether the next delete query fails.
			 *
			 * @var bool
			 */
			public bool $fail_next = true;

			/**
			 * Delete events, failing once.
			 *
			 * @param int[] $ids Event IDs.
			 * @return int
			 * @throws \RuntimeException On the first call.
			 */
			public function delete_events( array $ids ): int {
				if ( $this->fail_next ) {
					$this->fail_next = false;
					throw new \RuntimeException( 'Session event deletion failed.' );
				}

				return parent::delete_events( $ids );
			}
		};
		$event_store->init( $this->schema_manager );
		$rule_finder = new SessionRuleFinder();
		$rule_finder->init( $this->rule_store, $this->schema_manager, new ConditionOperatorRegistry() );
		$output = new CommandOutput();
		$output->init( wc_get_container()->get( LegacyProxy::class ), $this->schema_manager );
		$this->sut = new PaymentAttemptsCommands();
		$this->sut->init( $event_store, $rule_finder, new PaymentMethodTitleResolver(), $output );

		$this->assertFalse( $this->run_command( 'delete_attempts', array( 'customer@example.com' ), array( 'yes' => true ) ) );

		$this->assertSame( array( 'Deleted 1 of 101 payment attempts.' ), $this->messages( 'error' ) );
		$this->assertSame( array_slice( $ids, 0, 100 ), $this->remaining_ids(), 'Only the failed batch is kept' );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
