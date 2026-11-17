<?php
/**
 * CustomRulesCommandsTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\CommandOutput;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\CustomRulesCommands;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\RuleStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WP_CLI;
use WP_CLI\Formatter;

require_once __DIR__ . '/../../../../stubs/wp-cli-formatter.php';

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch
/**
 * Exception used to model WP-CLI exits in the custom rules command tests.
 */
class CustomRulesCommandExit extends \RuntimeException {}
// phpcs:enable Squiz.Classes.ClassFileName.NoMatch

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
/**
 * Tests for CustomRulesCommands.
 */
class CustomRulesCommandsTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var CustomRulesCommands
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
						throw new CustomRulesCommandExit( $message );
					},
					'confirm'     => function ( string $question, array $assoc_args ): void {
						$this->confirmed = array( $question, $assoc_args );
						if ( $this->decline && empty( $assoc_args['yes'] ) ) {
							throw new CustomRulesCommandExit( 'declined' );
						}
					},
				),
			)
		);

		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( FraudProtectionLogger::class ) );
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_rules_table_schema() );
		update_option( SchemaManager::DB_VERSION_OPTION, SchemaManager::SCHEMA_VERSION );

		$this->rule_store = new RuleStore();
		$this->rule_store->init( $this->schema_manager );
		$this->telemetry = $this->createMock( SettingsTelemetry::class );
		$rule_manager    = new RuleManager();
		$rule_manager->init( $this->rule_store, $this->telemetry );
		$output = new CommandOutput();
		$output->init( wc_get_container()->get( LegacyProxy::class ) );

		$this->sut = new CustomRulesCommands();
		$this->sut->init( $this->rule_store, $rule_manager, $this->schema_manager, $output );
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
		Formatter::$calls = array();

		parent::tearDown();
	}

	/**
	 * Run a command, capturing its WP-CLI exit.
	 *
	 * @param string               $method     Command method.
	 * @param string[]             $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return bool False when the command exited through WP_CLI::error() or a declined confirmation.
	 */
	private function run_command( string $method, array $args = array(), array $assoc_args = array() ): bool {
		try {
			$this->sut->$method( $args, $assoc_args );
		} catch ( CustomRulesCommandExit $exit ) {
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
	 * Create a rule directly in the store.
	 *
	 * @param FraudDecision $action Rule action.
	 * @param string        $type   Rule type.
	 * @param string        $value  Rule value.
	 * @return Rule
	 */
	private function a_rule( FraudDecision $action, string $type, string $value ): Rule {
		return $this->rule_store->create_rule( $action, RuleManager::build_conditions( $type, $value ) );
	}

	/**
	 * @testdox Registers the custom rules command leaves with the documented arguments.
	 */
	public function test_registers_command_leaves(): void {
		$this->sut->register_commands();

		$this->assertSame(
			array(
				'wc fraud-protection custom-rules list',
				'wc fraud-protection custom-rules get',
				'wc fraud-protection custom-rules create',
				'wc fraud-protection custom-rules update',
				'wc fraud-protection custom-rules delete',
			),
			array_keys( $this->commands )
		);

		$create = array_column( $this->commands['wc fraud-protection custom-rules create']['synopsis'], null, 'name' );
		$this->assertSame( array( 'allow', 'block' ), $create['action']['options'] );
		$this->assertSame( 'flag', $create['porcelain']['type'] );

		$delete = array_column( $this->commands['wc fraud-protection custom-rules delete']['synopsis'], null, 'name' );
		$this->assertTrue( $delete['rule']['repeating'] );
		$this->assertSame( 'flag', $delete['yes']['type'], 'Confirmation is skipped with the standard --yes flag' );

		$list = array_column( $this->commands['wc fraud-protection custom-rules list']['synopsis'], null, 'name' );
		$this->assertSame( '20', $list['limit']['default'] );
		$this->assertSame( CommandOutput::LIST_FORMATS, $list['format']['options'] );
	}

	/**
	 * @testdox Lists active rules with the requested filters, order and limit.
	 */
	public function test_list_filters_sorts_and_limits(): void {
		$this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );
		$this->a_rule( FraudDecision::Block, 'email', 'bob@example.com' );
		$this->a_rule( FraudDecision::Block, 'email', 'alice@example.com' );
		$this->a_rule( FraudDecision::Allow, 'email', 'carol@example.com' );

		$this->assertTrue(
			$this->run_command(
				'list_rules',
				array(),
				array(
					'action'  => 'block',
					'type'    => 'email',
					'orderby' => 'value',
					'order'   => 'asc',
					'limit'   => '1',
				)
			)
		);

		$this->assertCount( 1, Formatter::$calls );
		$this->assertSame( 'display_items', Formatter::$calls[0]['method'] );
		$this->assertSame( array( 'id', 'action', 'type', 'value', 'created_at', 'updated_at' ), Formatter::$calls[0]['fields'] );
		$this->assertSame( array( 'alice@example.com' ), array_column( Formatter::$calls[0]['data'], 'value' ) );
	}

	/**
	 * @testdox Lists more rules than fit in one store page.
	 */
	public function test_list_reads_past_one_store_page(): void {
		for ( $i = 1; $i <= 101; $i++ ) {
			$this->a_rule( FraudDecision::Block, 'email', "shopper{$i}@example.com" );
		}

		$this->run_command( 'list_rules', array(), array( 'limit' => '101' ) );

		$this->assertCount( 101, Formatter::$calls[0]['data'] );
	}

	/**
	 * @testdox The count and ids formats report the total and the listed IDs.
	 */
	public function test_list_count_and_ids_formats(): void {
		$first  = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );
		$second = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.11' );
		$this->a_rule( FraudDecision::Block, 'ip', '192.0.2.12' );

		$this->run_command(
			'list_rules',
			array(),
			array(
				'format' => 'count',
				'limit'  => '1',
			)
		);
		$this->run_command(
			'list_rules',
			array(),
			array(
				'format'  => 'ids',
				'limit'   => '2',
				'orderby' => 'value',
				'order'   => 'asc',
			)
		);

		$this->assertSame( array( '3', $first->id . ' ' . $second->id ), $this->messages( 'line' ), 'Count ignores the limit; ids honors it' );
		$this->assertEmpty( Formatter::$calls );
	}

	/**
	 * @testdox Date filters accept a day or a full timestamp and reject other formats.
	 */
	public function test_list_date_filters(): void {
		$this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );
		$today = gmdate( 'Y-m-d' );

		$this->run_command(
			'list_rules',
			array(),
			array(
				'from' => $today,
				'to'   => $today,
			)
		);
		$this->run_command( 'list_rules', array(), array( 'to' => gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );

		$this->assertCount( 1, Formatter::$calls[0]['data'], 'A day includes all of its times' );
		$this->assertCount( 0, Formatter::$calls[1]['data'] );
		$this->assertFalse( $this->run_command( 'list_rules', array(), array( 'from' => '2026-02-30' ) ) );
		$this->assertSame( array( 'Enter dates as YYYY-MM-DD or "YYYY-MM-DD HH:MM:SS".' ), $this->messages( 'error' ) );
	}

	/**
	 * @testdox An invalid limit is rejected.
	 */
	public function test_list_rejects_invalid_limit(): void {
		$this->assertFalse( $this->run_command( 'list_rules', array(), array( 'limit' => '0' ) ) );
		$this->assertSame( array( 'The limit must be a positive whole number.' ), $this->messages( 'error' ) );
	}

	/**
	 * @testdox Every command stops when the database schema is not installed.
	 * @dataProvider command_provider
	 *
	 * @param string   $method Command method.
	 * @param string[] $args   Positional arguments.
	 */
	public function test_commands_require_schema( string $method, array $args ): void {
		update_option( SchemaManager::DB_VERSION_OPTION, 0 );

		$this->assertFalse( $this->run_command( $method, $args, array( 'yes' => true ) ) );
		$this->assertStringContainsString( 'database schema is incomplete', $this->messages( 'error' )[0] );
	}

	/**
	 * Command methods with valid positional arguments.
	 *
	 * @return array<string, array{string, string[]}>
	 */
	public function command_provider(): array {
		return array(
			'list'   => array( 'list_rules', array() ),
			'get'    => array( 'get_rule', array( '1' ) ),
			'create' => array( 'create_rule', array( 'block', '192.0.2.10' ) ),
			'update' => array( 'update_rule', array( '1' ) ),
			'delete' => array( 'delete_rules', array( '1' ) ),
		);
	}

	/**
	 * @testdox Shows an active rule, and errors for a deleted or unknown rule.
	 */
	public function test_get_rule(): void {
		$rule    = $this->a_rule( FraudDecision::Allow, 'email', 'shopper@example.com' );
		$deleted = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );
		$this->rule_store->delete_rule( $deleted->id );

		$this->assertTrue( $this->run_command( 'get_rule', array( (string) $rule->id ), array( 'format' => 'json' ) ) );
		$this->assertFalse( $this->run_command( 'get_rule', array( (string) $deleted->id ) ) );
		$this->assertFalse( $this->run_command( 'get_rule', array( 'abc' ) ) );

		$this->assertSame( 'display_item', Formatter::$calls[0]['method'] );
		$this->assertSame( 'json', Formatter::$calls[0]['assoc_args']['format'] );
		$this->assertSame( 'shopper@example.com', Formatter::$calls[0]['data']['value'] );
		$this->assertSame( array( 'The rule could not be found.', 'The rule ID must be a positive whole number.' ), $this->messages( 'error' ) );
	}

	/**
	 * @testdox Creates a rule with the type detected from the value and records it as a CLI change.
	 * @dataProvider detected_value_provider
	 *
	 * @param string $value      Value argument.
	 * @param string $type       Expected rule type.
	 * @param string $normalized Expected stored value.
	 */
	public function test_create_detects_type( string $value, string $type, string $normalized ): void {
		$this->telemetry->expects( $this->once() )->method( 'record_rule_change' )->with( 'created', FraudDecision::Block, $type, 'cli' );

		$this->assertTrue( $this->run_command( 'create_rule', array( 'block', $value ) ) );

		$rules = $this->rule_store->get_active_rules();
		$this->assertCount( 1, $rules );
		$this->assertSame( $type, $rules[0]->conditions['field'] );
		$this->assertSame( $normalized, $rules[0]->conditions['value'] );
		$this->assertSame( array( 'origin' => 'cli' ), $rules[0]->source_meta );
		$this->assertSame( array( "Created custom rule {$rules[0]->id}." ), $this->messages( 'success' ) );
	}

	/**
	 * Values whose type is detected.
	 *
	 * @return array<string, array{string, string, string}>
	 */
	public function detected_value_provider(): array {
		return array(
			'email' => array( ' Shopper@Example.com ', 'email', 'shopper@example.com' ),
			'IPv4'  => array( '192.0.2.10', 'ip', '192.0.2.10' ),
			'IPv6'  => array( '2001:DB8:0::1', 'ip', '2001:db8::1' ),
		);
	}

	/**
	 * @testdox Create rejects a value that does not fit the requested or detected type.
	 * @dataProvider invalid_create_provider
	 *
	 * @param string               $value      Value argument.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 */
	public function test_create_rejects_invalid_value( string $value, array $assoc_args ): void {
		$this->telemetry->expects( $this->never() )->method( 'record_rule_change' );

		$this->assertFalse( $this->run_command( 'create_rule', array( 'block', $value ), $assoc_args ) );

		$this->assertSame( array( 'Enter a complete email address or IP address.' ), $this->messages( 'error' ) );
		$this->assertSame( array(), $this->rule_store->get_active_rules() );
	}

	/**
	 * Invalid create values.
	 *
	 * @return array<string, array{string, array<string, mixed>}>
	 */
	public function invalid_create_provider(): array {
		return array(
			'incomplete email'  => array( 'shopper@', array() ),
			'incomplete IP'     => array( '192.0.2', array() ),
			'email given as IP' => array( 'shopper@example.com', array( 'type' => 'ip' ) ),
			'IP given as email' => array( '192.0.2.10', array( 'type' => 'email' ) ),
		);
	}

	/**
	 * @testdox Create with --porcelain prints only the new rule ID.
	 */
	public function test_create_porcelain(): void {
		$this->run_command( 'create_rule', array( 'allow', '192.0.2.10' ), array( 'porcelain' => true ) );

		$rules = $this->rule_store->get_active_rules();
		$this->assertSame( array( (string) $rules[0]->id ), $this->messages( 'line' ) );
		$this->assertSame( array(), $this->messages( 'success' ) );
	}

	/**
	 * @testdox A duplicate create reports the existing rule and its action.
	 */
	public function test_create_duplicate_reports_existing_rule(): void {
		$existing = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );

		$this->assertFalse( $this->run_command( 'create_rule', array( 'allow', '192.0.2.10' ) ) );

		$this->assertSame( array( "You already have a rule that blocks this IP address. Rule ID: {$existing->id}." ), $this->messages( 'error' ) );
	}

	/**
	 * @testdox Update changes only the given action and keeps the value.
	 */
	public function test_update_action_only(): void {
		$rule = $this->a_rule( FraudDecision::Block, 'email', 'shopper@example.com' );
		$this->telemetry->expects( $this->once() )->method( 'record_rule_change' )->with( 'updated', FraudDecision::Allow, 'email', 'cli' );

		$this->assertTrue( $this->run_command( 'update_rule', array( (string) $rule->id ), array( 'action' => 'allow' ) ) );

		$updated = $this->rule_store->get_rule( $rule->id );
		$this->assertSame( FraudDecision::Allow, $updated->action );
		$this->assertSame( 'shopper@example.com', $updated->conditions['value'] );
		$this->assertSame( array( "Updated custom rule {$rule->id}." ), $this->messages( 'success' ) );
	}

	/**
	 * @testdox Update changes only the given value, detecting its type, and keeps the action.
	 */
	public function test_update_value_only(): void {
		$rule = $this->a_rule( FraudDecision::Block, 'email', 'shopper@example.com' );

		$this->assertTrue( $this->run_command( 'update_rule', array( (string) $rule->id ), array( 'value' => '192.0.2.10' ) ) );

		$updated = $this->rule_store->get_rule( $rule->id );
		$this->assertSame( FraudDecision::Block, $updated->action );
		$this->assertSame( 'ip', $updated->conditions['field'] );
		$this->assertSame( '192.0.2.10', $updated->conditions['value'] );
	}

	/**
	 * @testdox Update reports an unchanged rule without recording a change.
	 */
	public function test_update_unchanged_rule(): void {
		$rule = $this->a_rule( FraudDecision::Block, 'email', 'shopper@example.com' );
		$this->telemetry->expects( $this->never() )->method( 'record_rule_change' );

		$this->assertTrue(
			$this->run_command(
				'update_rule',
				array( (string) $rule->id ),
				array(
					'action' => 'block',
					'value'  => 'Shopper@Example.com',
				)
			)
		);

		$this->assertSame( array( "Custom rule {$rule->id} is already up to date." ), $this->messages( 'success' ) );
	}

	/**
	 * @testdox Update rejects missing or inconsistent arguments without changing the rule.
	 * @dataProvider invalid_update_provider
	 *
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @param string               $expected   Expected error.
	 */
	public function test_update_rejects_invalid_arguments( array $assoc_args, string $expected ): void {
		$rule = $this->a_rule( FraudDecision::Block, 'email', 'shopper@example.com' );

		$this->assertFalse( $this->run_command( 'update_rule', array( (string) $rule->id ), $assoc_args ) );

		$this->assertSame( array( $expected ), $this->messages( 'error' ) );
		$this->assertNull( $this->rule_store->get_rule( $rule->id )->updated_at );
	}

	/**
	 * Invalid update arguments.
	 *
	 * @return array<string, array{array<string, mixed>, string}>
	 */
	public function invalid_update_provider(): array {
		return array(
			'nothing to change'  => array( array(), 'Use --action, --value, or both.' ),
			'type without value' => array( array( 'type' => 'ip' ), 'Use --type only together with --value.' ),
			'invalid value'      => array( array( 'value' => 'shopper@' ), 'Enter a complete email address or IP address.' ),
		);
	}

	/**
	 * @testdox Update reports a value that another rule already has.
	 */
	public function test_update_duplicate_reports_existing_rule(): void {
		$rule     = $this->a_rule( FraudDecision::Block, 'email', 'shopper@example.com' );
		$existing = $this->a_rule( FraudDecision::Allow, 'email', 'other@example.com' );

		$this->assertFalse( $this->run_command( 'update_rule', array( (string) $rule->id ), array( 'value' => 'other@example.com' ) ) );

		$this->assertSame( array( "You already have a rule that allows this email address. Rule ID: {$existing->id}." ), $this->messages( 'error' ) );
	}

	/**
	 * @testdox Update errors for a rule that is not active.
	 */
	public function test_update_missing_rule(): void {
		$this->assertFalse( $this->run_command( 'update_rule', array( '999' ), array( 'action' => 'allow' ) ) );

		$this->assertSame( array( 'The rule could not be found.' ), $this->messages( 'error' ) );
	}

	/**
	 * @testdox Delete resolves IDs and values, confirms with the matching rules, and deletes each rule once.
	 */
	public function test_delete_by_id_and_value(): void {
		$by_id    = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );
		$by_email = $this->a_rule( FraudDecision::Allow, 'email', 'shopper@example.com' );
		$by_ipv6  = $this->a_rule( FraudDecision::Block, 'ip', '2001:db8::1' );
		$kept     = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.11' );
		$this->telemetry->expects( $this->exactly( 3 ) )->method( 'record_rule_change' )->with( 'deleted', $this->anything(), $this->anything(), 'cli' );

		$this->assertTrue( $this->run_command( 'delete_rules', array( (string) $by_id->id, 'Shopper@Example.com', '2001:DB8::1', '192.0.2.10' ), array( 'yes' => true ) ) );

		$this->assertSame( array( 'Delete 3 custom rules?', array( 'yes' => true ) ), $this->confirmed );
		$this->assertSame( array( $by_id->id, $by_email->id, $by_ipv6->id ), array_column( Formatter::$calls[0]['data'], 'id' ), 'The rules are listed before confirming' );
		$this->assertSame( array( 'Deleted 3 custom rules.' ), $this->messages( 'success' ) );
		$this->assertSame( array( $kept->id ), array_map( fn( Rule $rule ): int => $rule->id, $this->rule_store->get_active_rules() ) );
	}

	/**
	 * @testdox Declining the confirmation deletes nothing.
	 */
	public function test_delete_declined(): void {
		$rule          = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );
		$this->decline = true;
		$this->telemetry->expects( $this->never() )->method( 'record_rule_change' );

		$this->assertFalse( $this->run_command( 'delete_rules', array( (string) $rule->id ) ) );

		$this->assertSame( RuleStatus::Active, $this->rule_store->get_rule( $rule->id )->status );
	}

	/**
	 * @testdox Delete warns about unmatched arguments, deletes the matches, and exits with an error summary.
	 */
	public function test_delete_reports_unmatched_arguments(): void {
		$rule = $this->a_rule( FraudDecision::Block, 'ip', '192.0.2.10' );

		$this->assertFalse( $this->run_command( 'delete_rules', array( (string) $rule->id, '999', 'nobody@example.com', 'not-a-value' ), array( 'yes' => true ) ) );

		$this->assertSame(
			array(
				'No custom rule matches "999".',
				'No custom rule matches "nobody@example.com".',
				'"not-a-value" is not a rule ID, an email address, or an IP address.',
			),
			$this->messages( 'warning' )
		);
		$this->assertSame( array( 'Deleted 1 of 4 custom rules.' ), $this->messages( 'error' ) );
		$this->assertSame( RuleStatus::Deleted, $this->rule_store->get_rule( $rule->id )->status );
	}

	/**
	 * @testdox Delete errors without asking for confirmation when nothing matches.
	 */
	public function test_delete_without_matches(): void {
		$this->assertFalse( $this->run_command( 'delete_rules', array( '999' ) ) );

		$this->assertNull( $this->confirmed );
		$this->assertSame( array( 'No matching custom rules were found.' ), $this->messages( 'error' ) );
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
