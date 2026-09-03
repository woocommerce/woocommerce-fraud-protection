<?php
/**
 * FraudProtectionCommandsTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\FraudProtectionCommands;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventPruner;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSource;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSettingUpdater;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantExperienceFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsChangeChannel;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WP_CLI;

/**
 * Exception used to model WP-CLI error exits.
 */
class WPCLIErrorException extends \RuntimeException {}

/**
 * Tests for FraudProtectionCommands.
 */
class FraudProtectionCommandsTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var FraudProtectionCommands
	 */
	private $sut;

	/**
	 * Schema manager mock.
	 *
	 * @var SchemaManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $schema_manager;

	/**
	 * Session pruner mock.
	 *
	 * @var SessionEventPruner&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $session_event_pruner;

	/**
	 * Merchant experience feature.
	 *
	 * @var MerchantExperienceFeature&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $merchant_experience;

	/**
	 * Automatic protection setting.
	 *
	 * @var AutomaticProtectionSetting&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $automatic_protection;

	/** @var AutomaticProtectionSettingUpdater&\PHPUnit\Framework\MockObject\MockObject */
	private $automatic_protection_updater;

	/**
	 * Registered commands.
	 *
	 * @var array<string, callable>
	 */
	private array $wp_cli_hooks;

	/**
	 * Registered command callbacks.
	 *
	 * @var array<string, callable>
	 */
	private array $wp_cli_commands;

	/**
	 * Successful command messages.
	 *
	 * @var string[]
	 */
	private array $wp_cli_lines;

	/**
	 * Command errors.
	 *
	 * @var string[]
	 */
	private array $wp_cli_successes;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->wp_cli_hooks     = array();
		$this->wp_cli_commands  = array();
		$this->wp_cli_lines     = array();
		$this->wp_cli_successes = array();

		$this->register_legacy_proxy_static_mocks(
			array(
				WP_CLI::class => array(
					'add_hook'    => function ( string $name, callable $callback ): void {
						$this->wp_cli_hooks[ $name ] = $callback;
					},
					'add_command' => function ( string $name, callable $callback, array $args = array() ): void {
						unset( $args );
						$this->wp_cli_commands[ $name ] = $callback;
					},
					'line'        => function ( string $message ): void {
						$this->wp_cli_lines[] = $message;
					},
					'success'     => function ( string $message ): void {
						$this->wp_cli_successes[] = $message;
					},
					'error'       => function ( mixed $message ): void {
						if ( $message instanceof \Throwable ) {
							$message = $message::class . ': ' . $message->getMessage();
						}

						throw new WPCLIErrorException( (string) $message );
					},
				),
			)
		);

		$this->schema_manager               = $this->createMock( SchemaManager::class );
		$this->session_event_pruner         = $this->createMock( SessionEventPruner::class );
		$this->merchant_experience          = $this->createMock( MerchantExperienceFeature::class );
		$this->automatic_protection         = $this->createMock( AutomaticProtectionSetting::class );
		$this->automatic_protection_updater = $this->createMock( AutomaticProtectionSettingUpdater::class );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );
		$this->sut                          = new FraudProtectionCommands();
		$this->sut->init( $this->schema_manager, $this->session_event_pruner, wc_get_container()->get( LegacyProxy::class ), $this->merchant_experience, $this->automatic_protection, $this->automatic_protection_updater );
	}

	/**
	 * Get a complete schema status fixture.
	 *
	 * @param array<string, mixed> $overrides Values to override.
	 * @return array{required_version: int, installed_version: int, install_state: array{attempts: int, last_attempt: int, last_error: string}, complete: bool, tables: array<int, array{name: string, exists: bool}>}
	 */
	private static function schema_status( array $overrides = array() ): array {
		return array_merge(
			array(
				'required_version'  => SchemaManager::SCHEMA_VERSION,
				'installed_version' => SchemaManager::SCHEMA_VERSION,
				'install_state'     => array(
					'attempts'       => 0,
					'last_attempt'   => 0,
					'last_error'     => '',
				),
				'complete'          => true,
				'tables'            => array(
					array(
						'name'   => 'wp_wc_fraud_protection_sessions',
						'exists' => true,
					),
					array(
						'name'   => 'wp_wc_fraud_protection_rules',
						'exists' => true,
					),
				),
			),
			$overrides
		);
	}

	/**
	 * @testdox Should register only the accepted Fraud Protection command leaves.
	 */
	public function test_registers_accepted_command_leaves(): void {
		$this->sut->register();
		$this->assertArrayHasKey( 'after_wp_load', $this->wp_cli_hooks );
		$this->wp_cli_hooks['after_wp_load']();

		$this->assertSame(
			array(
				'wc fraud-protection merchant-experience set',
				'wc fraud-protection automatic-protection set',
				'wc fraud-protection status',
				'wc fraud-protection database install',
				'wc fraud-protection sessions prune',
			),
			array_keys( $this->wp_cli_commands )
		);
	}

	/**
	 * @testdox Explicit automatic-protection CLI values are passed to the updater.
	 *
	 * @dataProvider automatic_protection_value_provider
	 *
	 * @param string $value   Requested setting value.
	 * @param bool   $enabled Requested enabled state.
	 */
	public function test_automatic_protection_set_routes_explicit_values( string $value, bool $enabled ): void {
		$this->automatic_protection_updater->expects( $this->once() )
			->method( 'set_enabled' )
			->with( $enabled, SettingsChangeChannel::Cli )
			->willReturn( true );
		$this->automatic_protection_updater->expects( $this->never() )->method( 'reset' );

		$this->sut->automatic_protection_set( array( $value ) );

		$this->assertSame( array( 'The automatic-protection setting was updated.' ), $this->wp_cli_successes );
	}

	/**
	 * @testdox The default automatic-protection CLI value resets the setting.
	 */
	public function test_automatic_protection_set_routes_default_to_reset(): void {
		$this->automatic_protection_updater->expects( $this->never() )->method( 'set_enabled' );
		$this->automatic_protection_updater->expects( $this->once() )
			->method( 'reset' )
			->with( SettingsChangeChannel::Cli )
			->willReturn( true );

		$this->sut->automatic_protection_set( array( 'default' ) );

		$this->assertSame( array( 'The automatic-protection setting was updated.' ), $this->wp_cli_successes );
	}

	/**
	 * @testdox Invalid automatic-protection values do not change stored state.
	 */
	public function test_automatic_protection_set_rejects_invalid_values(): void {
		$this->automatic_protection_updater->expects( $this->never() )->method( 'set_enabled' );
		$this->automatic_protection_updater->expects( $this->never() )->method( 'reset' );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'enabled, disabled, or default' );
		$this->sut->automatic_protection_set( array( 'invalid' ) );
	}

	/**
	 * @testdox Automatic-protection CLI changes report failed setting writes.
	 *
	 * @dataProvider automatic_protection_value_provider
	 *
	 * @param string $value   Requested setting value.
	 * @param bool   $enabled Requested enabled state for explicit values.
	 */
	public function test_automatic_protection_set_reports_failed_explicit_updates( string $value, bool $enabled ): void {
		$this->automatic_protection_updater->expects( $this->once() )
			->method( 'set_enabled' )
			->with( $enabled, SettingsChangeChannel::Cli )
			->willReturn( false );
		$this->automatic_protection_updater->expects( $this->never() )->method( 'reset' );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'The automatic-protection setting could not be saved.' );
		$this->sut->automatic_protection_set( array( $value ) );
	}

	/**
	 * @testdox A failed automatic-protection reset is reported as a CLI error.
	 */
	public function test_automatic_protection_set_reports_failed_reset(): void {
		$this->automatic_protection_updater->expects( $this->never() )->method( 'set_enabled' );
		$this->automatic_protection_updater->expects( $this->once() )
			->method( 'reset' )
			->with( SettingsChangeChannel::Cli )
			->willReturn( false );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'The automatic-protection setting could not be saved.' );
		$this->sut->automatic_protection_set( array( 'default' ) );
	}

	/**
	 * Provide explicit automatic-protection values.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function automatic_protection_value_provider(): array {
		return array(
			'enable'  => array( 'enabled', true ),
			'disable' => array( 'disabled', false ),
		);
	}

	/**
	 * @testdox Status should report all local diagnostic groups without mutation.
	 */
	public function test_status_reports_local_state_without_mutation(): void {
		$this->mock_default_settings_status();
		$this->schema_manager->method( 'get_schema_status' )->willReturn(
			self::schema_status(
				array(
					'install_state' => array(
						'attempts'       => 3,
						'last_attempt'   => 1724112000,
						'last_error'     => 'Database error',
					),
				)
			)
		);
		$this->session_event_pruner->method( 'get_next_scheduled_action' )->willReturn( 1724198400 );
		update_option( SchemaManager::DB_VERSION_OPTION, 91 );
		update_option( SchemaManager::DB_INSTALL_STATE_OPTION, array( 'sentinel' => true ) );

		$this->sut->status();

		$this->assertSame( 91, (int) get_option( SchemaManager::DB_VERSION_OPTION ), 'Status must not change the schema version' );
		$this->assertSame( array( 'sentinel' => true ), get_option( SchemaManager::DB_INSTALL_STATE_OPTION ), 'Status must not change the install state' );
		$output = implode( "\n", $this->wp_cli_lines );
		$this->assertStringContainsString( 'Plugin version:', $output );
		$this->assertStringContainsString( 'Merchant experience status: default_disabled', $output );
		$this->assertStringContainsString( 'Automatic protection status: default_disabled', $output );
		$this->assertStringContainsString( 'Automatic protection source: none', $output );
		$this->assertStringNotContainsString( 'code default', $output );
		$this->assertStringNotContainsString( 'stored state', $output );
		$this->assertMatchesRegularExpression( '/Jetpack blog ID: (?:[1-9][0-9]*|Unavailable)/', $output );
		$this->assertStringContainsString( 'Required schema version:', $output );
		$this->assertStringContainsString( 'Installed schema version:', $output );
		$this->assertStringContainsString( 'Schema installation attempts: 3', $output );
		$this->assertStringContainsString( 'Last schema installation attempt: 2024-08-20 00:00:00 UTC', $output );
		$this->assertStringContainsString( 'Last schema installation error: Database error', $output );
		$this->assertStringContainsString( 'Tables: wp_wc_fraud_protection_sessions, wp_wc_fraud_protection_rules', $output );
		$this->assertStringNotContainsString( 'Missing tables:', $output );
		$this->assertStringNotContainsString( 'Columns for', $output );
		$this->assertStringNotContainsString( 'Indexes for', $output );
		$this->assertStringContainsString( 'WordPress database charset:', $output );
		$this->assertStringContainsString( 'WordPress database collation:', $output );
		$this->assertStringContainsString( 'Database default charset:', $output );
		$this->assertStringContainsString( 'Database default collation:', $output );
		$this->assertStringContainsString( 'Next session pruning action:', $output );
		$this->assertEmpty( $this->wp_cli_successes );
	}

	/**
	 * @testdox Status reports explicit setting states and the manual enabled source.
	 */
	public function test_status_reports_explicit_setting_states(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->session_event_pruner->method( 'get_next_scheduled_action' )->willReturn( false );
		$this->merchant_experience->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$this->automatic_protection->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::Enabled, SettingStatus::Disabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::Manual );

		$this->sut->status();

		$output = implode( "\n", $this->wp_cli_lines );
		$this->assertStringContainsString( 'Merchant experience status: enabled', $output );
		$this->assertStringContainsString( 'Automatic protection status: enabled', $output );
		$this->assertStringContainsString( 'Automatic protection source: manual', $output );

		$this->wp_cli_lines = array();
		$this->sut->status();

		$output = implode( "\n", $this->wp_cli_lines );
		$this->assertStringContainsString( 'Automatic protection status: disabled', $output );
		$this->assertStringContainsString( 'Automatic protection source: manual', $output );
	}

	/**
	 * @testdox A failed automatic-protection set reports an error.
	 */
	public function test_automatic_protection_set_failure_reports_error(): void {
		$this->automatic_protection_updater->expects( $this->once() )
			->method( 'set_enabled' )
			->with( true, SettingsChangeChannel::Cli )
			->willReturn( false );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'could not be saved' );
		$this->sut->automatic_protection_set( array( 'enabled' ) );
	}

	/**
	 * @testdox A failed automatic-protection reset reports an error.
	 */
	public function test_automatic_protection_reset_failure_reports_error(): void {
		$this->automatic_protection_updater->expects( $this->once() )
			->method( 'reset' )
			->with( SettingsChangeChannel::Cli )
			->willReturn( false );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'could not be saved' );
		$this->sut->automatic_protection_set( array( 'default' ) );
	}

	/**
	 * @testdox Merchant-experience CLI changes write and reset the shared override.
	 */
	public function test_merchant_experience_set_updates_shared_override(): void {
		$this->merchant_experience->expects( $this->once() )->method( 'set_enabled' )->with( true )->willReturn( true );
		$this->merchant_experience->expects( $this->once() )->method( 'reset' )->willReturn( true );

		$this->sut->merchant_experience_set( array( 'enabled' ) );
		$this->sut->merchant_experience_set( array( 'default' ) );

		$this->assertCount( 2, $this->wp_cli_successes );
	}

	/**
	 * @testdox Status should report the numeric Jetpack ID and missing tables.
	 */
	public function test_status_reports_jetpack_id_and_missing_tables(): void {
		$this->mock_default_settings_status();
		$this->schema_manager->method( 'get_schema_status' )->willReturn(
			self::schema_status(
				array(
					'complete' => false,
					'tables'   => array(
						array(
							'name'   => 'wp_wc_fraud_protection_sessions',
							'exists' => true,
						),
						array(
							'name'   => 'wp_wc_fraud_protection_rules',
							'exists' => false,
						),
					),
				)
			)
		);
		$this->session_event_pruner->method( 'get_next_scheduled_action' )->willReturn( false );
		add_filter(
			'pre_option_jetpack_options',
			static function () {
				return array( 'id' => 12345 );
			}
		);

		$this->sut->status();

		$output = implode( "\n", $this->wp_cli_lines );
		$this->assertStringContainsString( 'Jetpack blog ID: 12345', $output );
		$this->assertStringContainsString( 'Tables: wp_wc_fraud_protection_sessions, wp_wc_fraud_protection_rules', $output );
		$this->assertStringContainsString( 'Missing tables: wp_wc_fraud_protection_rules', $output );
		$this->assertContains( 'Next session pruning action: Not scheduled', $this->wp_cli_lines );
	}

	/**
	 * @testdox Status should hide empty schema installation retry state.
	 */
	public function test_status_hides_empty_schema_installation_retry_state(): void {
		$this->mock_default_settings_status();
		$this->schema_manager->method( 'get_schema_status' )->willReturn(
			self::schema_status(
				array(
					'install_state' => array(
						'attempts'     => 1,
						'last_attempt' => 1724112000,
						'last_error'   => '',
					),
				)
			)
		);
		$this->session_event_pruner->method( 'get_next_scheduled_action' )->willReturn( false );

		$this->sut->status();

		$output = implode( "\n", $this->wp_cli_lines );
		$this->assertStringNotContainsString( 'Schema installation attempts:', $output );
		$this->assertStringNotContainsString( 'Last schema installation attempt:', $output );
		$this->assertStringNotContainsString( 'Last schema installation error:', $output );
	}

	/**
	 * @testdox Status should report an in-progress pruning action.
	 */
	public function test_status_reports_in_progress_pruning_action(): void {
		$this->mock_default_settings_status();
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->session_event_pruner->method( 'get_next_scheduled_action' )->willReturn( true );

		$this->sut->status();

		$this->assertContains( 'Next session pruning action: In progress', $this->wp_cli_lines );
	}

	/**
	 * Configure the default setting states used by status tests.
	 */
	private function mock_default_settings_status(): void {
		$this->merchant_experience->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );
	}

	/**
	 * @testdox Database install should report an already current schema without installing.
	 */
	public function test_database_install_reports_already_current(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->schema_manager->expects( $this->never() )->method( 'maybe_install_schema' );

		$this->sut->database_install();

		$this->assertSame( array( 'Fraud Protection database schema is already current.' ), $this->wp_cli_successes );
	}

	/**
	 * @testdox Database install should run immediately and report success when repair completes.
	 */
	public function test_database_install_repairs_incomplete_schema(): void {
		$incomplete = self::schema_status(
			array(
				'installed_version' => 0,
				'complete'          => false,
			)
		);
		$this->schema_manager->expects( $this->exactly( 2 ) )->method( 'get_schema_status' )->willReturnOnConsecutiveCalls( $incomplete, self::schema_status() );
		$this->schema_manager->expects( $this->once() )->method( 'maybe_install_schema' )->with( true );

		$this->sut->database_install();

		$this->assertSame( array( 'Fraud Protection database schema installed successfully.' ), $this->wp_cli_successes );
	}

	/**
	 * @testdox Database install should not repair an incomplete schema from a newer plugin version.
	 */
	public function test_database_install_stops_for_newer_incomplete_schema(): void {
		$newer = self::schema_status(
			array(
				'installed_version' => SchemaManager::SCHEMA_VERSION + 1,
				'complete'          => false,
			)
		);
		$this->schema_manager->method( 'get_schema_status' )->willReturn( $newer );
		$this->schema_manager->expects( $this->never() )->method( 'maybe_install_schema' );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'newer than this plugin requires' );

		$this->sut->database_install();
	}

	/**
	 * @testdox Database install should not accept a complete schema from a newer plugin version.
	 */
	public function test_database_install_stops_for_newer_complete_schema(): void {
		$newer = self::schema_status(
			array(
				'installed_version' => SchemaManager::SCHEMA_VERSION + 1,
			)
		);
		$this->schema_manager->method( 'get_schema_status' )->willReturn( $newer );
		$this->schema_manager->expects( $this->never() )->method( 'maybe_install_schema' );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'newer than this plugin requires' );

		$this->sut->database_install();
	}

	/**
	 * @testdox Database install should exit with an error when the schema remains incomplete.
	 */
	public function test_database_install_errors_when_schema_remains_incomplete(): void {
		$incomplete = self::schema_status(
			array(
				'installed_version' => 0,
				'install_state'     => array(
					'attempts'     => 1,
					'last_attempt' => 1724112000,
					'last_error'   => 'Controlled database error',
				),
				'complete'          => false,
				'tables'            => array(
					array(
						'name'   => 'wp_wc_fraud_protection_sessions',
						'exists' => false,
					),
				),
			)
		);
		$this->schema_manager->method( 'get_schema_status' )->willReturn( $incomplete );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'Controlled database error' );

		$this->sut->database_install();
	}

	/**
	 * @testdox Database install should use a generic error when no database error is available.
	 */
	public function test_database_install_uses_generic_error_when_schema_remains_incomplete(): void {
		$incomplete = self::schema_status(
			array(
				'installed_version' => 0,
				'complete'          => false,
			)
		);
		$this->schema_manager->method( 'get_schema_status' )->willReturn( $incomplete );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'Fraud Protection database schema installation did not complete.' );

		$this->sut->database_install();
	}

	/**
	 * @testdox Session prune should report the fixed-retention deletion count.
	 */
	public function test_sessions_prune_reports_deleted_count(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->session_event_pruner->expects( $this->once() )->method( 'prune_sessions' )->willReturn( 0 );

		$this->sut->sessions_prune();

		$this->assertSame( array( 'Pruned 0 expired Fraud Protection sessions.' ), $this->wp_cli_successes );
	}

	/**
	 * @testdox Session prune should stop with an error when the schema is incomplete.
	 */
	public function test_sessions_prune_stops_for_incomplete_schema(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status( array( 'complete' => false ) ) );
		$this->session_event_pruner->expects( $this->never() )->method( 'prune_sessions' );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'database schema is incomplete' );

		$this->sut->sessions_prune();
	}

	/**
	 * @testdox Session prune should convert storage failure to a WP-CLI error.
	 */
	public function test_sessions_prune_errors_on_storage_failure(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->session_event_pruner->method( 'prune_sessions' )->willThrowException( new \RuntimeException( 'raw database error' ) );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'RuntimeException: raw database error' );

		$this->sut->sessions_prune();
	}

	/**
	 * @testdox Session prune should leave unexpected exceptions for WP-CLI.
	 */
	public function test_sessions_prune_does_not_catch_unexpected_exception(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->session_event_pruner->method( 'prune_sessions' )->willThrowException( new \LogicException( 'unexpected programming error' ) );

		$this->expectException( \LogicException::class );
		$this->expectExceptionMessage( 'unexpected programming error' );

		$this->sut->sessions_prune();
	}
}
