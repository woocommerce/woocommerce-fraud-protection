<?php
/**
 * FraudProtectionCommandsTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\CLI;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\CLI\FraudProtectionCommands;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\ExtensionNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\GatewayNativeSupport;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventPruner;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSource;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSettingUpdater;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsChangeChannel;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use WP_CLI;

// phpcs:disable Squiz.Classes.ClassFileName.NoMatch
/**
 * Exception used to model WP-CLI error exits.
 */
class WPCLIErrorException extends \RuntimeException {}
// phpcs:enable Squiz.Classes.ClassFileName.NoMatch

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
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

	/** @var SchemaManager&\PHPUnit\Framework\MockObject\MockObject */
	private $schema_manager;

	/** @var SessionEventPruner&\PHPUnit\Framework\MockObject\MockObject */
	private $session_event_pruner;

	/** @var MerchantFacingFeaturesGate&\PHPUnit\Framework\MockObject\MockObject */
	private $merchant_facing_features_gate;

	/** @var AutomaticProtectionSetting&\PHPUnit\Framework\MockObject\MockObject */
	private $automatic_protection;

	/** @var AutomaticProtectionSettingUpdater&\PHPUnit\Framework\MockObject\MockObject */
	private $automatic_protection_updater;

	/** @var GatewayNativeSupport&\PHPUnit\Framework\MockObject\MockObject */
	private $native_support;

	/** @var array<string, callable> */
	private array $wp_cli_hooks;

	/** @var array<string, callable> */
	private array $wp_cli_commands;

	/** @var string[] */
	private array $wp_cli_lines;

	/** @var string[] */
	private array $wp_cli_successes;

	/** @var array<int, array{format: string, items: array<int, array<string, string>>, fields: string|string[]}> */
	private array $formatted_items;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->wp_cli_hooks     = array();
		$this->wp_cli_commands  = array();
		$this->wp_cli_lines     = array();
		$this->wp_cli_successes = array();
		$this->formatted_items  = array();

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

						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The test captures WP-CLI errors through this exception.
						throw new WPCLIErrorException( (string) $message );
					},
				),
			)
		);
		$this->register_legacy_proxy_function_mocks(
			array(
				'WP_CLI\\Utils\\format_items' => function ( string $format, array $items, $fields ): void {
					$this->formatted_items[] = array(
						'format' => $format,
						'items'  => $items,
						'fields' => $fields,
					);
				},
			)
		);

		$this->schema_manager                = $this->createMock( SchemaManager::class );
		$this->session_event_pruner          = $this->createMock( SessionEventPruner::class );
		$this->merchant_facing_features_gate = $this->createMock( MerchantFacingFeaturesGate::class );
		$this->automatic_protection          = $this->createMock( AutomaticProtectionSetting::class );
		$this->automatic_protection_updater  = $this->createMock( AutomaticProtectionSettingUpdater::class );
		$this->native_support                = $this->createMock( GatewayNativeSupport::class );
		$this->native_support->method( 'get_declarations' )->willReturn( array() );
		$this->sut = new FraudProtectionCommands();
		$this->sut->init( $this->schema_manager, $this->session_event_pruner, wc_get_container()->get( LegacyProxy::class ), $this->merchant_facing_features_gate, $this->automatic_protection, $this->automatic_protection_updater, $this->native_support, new ExtensionNativeSupport() );
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
					'attempts'     => 0,
					'last_attempt' => 0,
					'last_error'   => '',
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
				'wc fraud-protection merchant-facing-features set',
				'wc fraud-protection automatic-protection set',
				'wc fraud-protection status',
				'wc fraud-protection native-integrations list',
				'wc fraud-protection database install',
				'wc fraud-protection sessions prune',
			),
			array_keys( $this->wp_cli_commands )
		);
	}

	/**
	 * @testdox Explicit automatic-protection CLI values are passed to the updater.
	 *
	 * @dataProvider explicit_setting_value_provider
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

		$this->assertSame( array( 'The automatic blocking setting was updated.' ), $this->wp_cli_successes );
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

		$this->assertSame( array( 'The automatic blocking setting was updated.' ), $this->wp_cli_successes );
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
	 * @dataProvider explicit_setting_value_provider
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
		$this->expectExceptionMessage( 'The automatic blocking setting could not be saved.' );
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
		$this->expectExceptionMessage( 'The automatic blocking setting could not be saved.' );
		$this->sut->automatic_protection_set( array( 'default' ) );
	}

	/**
	 * Provide explicit automatic-protection values.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public function explicit_setting_value_provider(): array {
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
						'attempts'     => 3,
						'last_attempt' => 1724112000,
						'last_error'   => 'Database error',
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
		$this->assertStringContainsString( 'Installation type: Regular plugin', $output );
		$this->assertStringContainsString( 'Merchant-facing features status: default_disabled', $output );
		$this->assertStringContainsString( 'Automatic blocking status: default_disabled', $output );
		$this->assertStringContainsString( 'Automatic blocking source: none', $output );
		$this->assertStringContainsString( 'Automatic blocking opted out at: Not opted out', $output );
		$this->assertStringNotContainsString( 'code default', $output );
		$this->assertStringNotContainsString( 'stored state', $output );
		$this->assertMatchesRegularExpression( '/Jetpack blog ID: (?:[1-9][0-9]*|Unavailable)/', $output );
		$this->assertStringNotContainsString( 'Native integrations', $output );
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
	 * @testdox Status reports an explicitly enabled merchant-facing features gate.
	 */
	public function test_status_reports_enabled_merchant_facing_features(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->session_event_pruner->method( 'get_next_scheduled_action' )->willReturn( false );
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );

		$this->sut->status();

		$output = implode( "\n", $this->wp_cli_lines );
		$this->assertStringContainsString( 'Merchant-facing features status: enabled', $output );
	}

	/**
	 * @testdox The native integrations list has one row per integration: gateways first, then extensions.
	 */
	public function test_native_integrations_list_reports_declarations(): void {
		$native_support = $this->createMock( GatewayNativeSupport::class );
		$native_support->method( 'get_declarations' )->willReturn(
			array(
				'stripe'               => array( 'fraud_protection_payment_data', 'fraud_protection_express_checkout' ),
				'woocommerce_payments' => array( 'fraud_protection_payment_data' ),
			)
		);
		$extension_native_support = $this->createMock( ExtensionNativeSupport::class );
		$extension_native_support->method( 'get_declarations' )->willReturn( array( 'my-extension' => array( 'change_payment_method' ) ) );
		$this->sut->init( $this->schema_manager, $this->session_event_pruner, wc_get_container()->get( LegacyProxy::class ), $this->merchant_facing_features_gate, $this->automatic_protection, $this->automatic_protection_updater, $native_support, $extension_native_support );

		$this->sut->native_integrations_list( array(), array( 'format' => 'table' ) );

		$this->assertSame(
			array(
				array(
					'format' => 'table',
					'items'  => array(
						self::integration_row( 'gateway', 'stripe', 'fraud_protection_payment_data' ),
						self::integration_row( 'gateway', 'stripe', 'fraud_protection_express_checkout' ),
						self::integration_row( 'gateway', 'woocommerce_payments', 'fraud_protection_payment_data' ),
						self::integration_row( 'extension', 'my-extension', 'change_payment_method' ),
					),
					'fields' => array( 'type', 'id', 'integration' ),
				),
			),
			$this->formatted_items
		);
	}

	/**
	 * @testdox The native integrations list reads extension declarations from the filter, and has no rows without any.
	 */
	public function test_native_integrations_list_reads_extension_declarations_from_filter(): void {
		$this->sut->native_integrations_list( array(), array() );
		$this->assertSame( array(), $this->formatted_items[0]['items'] );

		$declare = static fn() => array( 'my-extension' => array( 'change_payment_method', 'misspelled_integration' ) );
		add_filter( 'woocommerce_fraud_protection_native_integrations', $declare );
		try {
			$this->sut->native_integrations_list( array(), array() );
		} finally {
			remove_filter( 'woocommerce_fraud_protection_native_integrations', $declare );
		}

		$this->assertSame(
			array(
				self::integration_row( 'extension', 'my-extension', 'change_payment_method' ),
				self::integration_row( 'extension', 'my-extension', 'misspelled_integration' ),
			),
			$this->formatted_items[1]['items']
		);
	}

	/**
	 * @testdox The native integrations list passes the requested format and fields to WP-CLI.
	 */
	public function test_native_integrations_list_passes_format_and_fields(): void {
		$this->sut->native_integrations_list( array(), array() );
		$this->sut->native_integrations_list(
			array(),
			array(
				'format' => 'json',
				'fields' => 'id,integration',
			)
		);

		$this->assertSame( 'table', $this->formatted_items[0]['format'] );
		$this->assertSame( array( 'type', 'id', 'integration' ), $this->formatted_items[0]['fields'] );
		$this->assertSame( 'json', $this->formatted_items[1]['format'] );
		$this->assertSame( 'id,integration', $this->formatted_items[1]['fields'] );
	}

	/**
	 * @testdox The native integrations list with --id prints only the integrations declared under that gateway ID or plugin slug, one per line.
	 *
	 * @dataProvider native_integrations_id_provider
	 *
	 * @param string   $id       Requested gateway ID or plugin slug.
	 * @param string[] $expected Expected output lines.
	 */
	public function test_native_integrations_list_filters_by_id( string $id, array $expected ): void {
		$native_support = $this->createMock( GatewayNativeSupport::class );
		$native_support->method( 'get_declarations' )->willReturn(
			array(
				'stripe' => array( 'fraud_protection_payment_data', 'fraud_protection_express_checkout' ),
				'shared' => array( 'fraud_protection_checkout' ),
			)
		);
		$extension_native_support = $this->createMock( ExtensionNativeSupport::class );
		$extension_native_support->method( 'get_declarations' )->willReturn(
			array(
				'my-extension' => array( 'change_payment_method' ),
				'shared'       => array( 'fraud_protection_checkout', 'change_payment_method' ),
			)
		);
		$this->sut->init( $this->schema_manager, $this->session_event_pruner, wc_get_container()->get( LegacyProxy::class ), $this->merchant_facing_features_gate, $this->automatic_protection, $this->automatic_protection_updater, $native_support, $extension_native_support );

		$this->sut->native_integrations_list(
			array(),
			array(
				'id'     => $id,
				'format' => 'json',
			)
		);

		$this->assertSame( $expected, $this->wp_cli_lines );
		$this->assertSame( array(), $this->formatted_items, '--id must print plain lines instead of formatted rows' );
	}

	/**
	 * Provide IDs for the native integrations list and the lines they print.
	 *
	 * @return array<string, array{string, string[]}>
	 */
	public function native_integrations_id_provider(): array {
		return array(
			'gateway ID'                         => array( 'stripe', array( 'fraud_protection_payment_data', 'fraud_protection_express_checkout' ) ),
			'plugin slug'                        => array( 'my-extension', array( 'change_payment_method' ) ),
			'ID used by a gateway and extension' => array( 'shared', array( 'fraud_protection_checkout', 'change_payment_method' ) ),
			'ID without declarations'            => array( 'unknown', array() ),
		);
	}

	/**
	 * @testdox Status reports the automatic-protection opt-out date.
	 */
	public function test_status_reports_automatic_protection_opt_out(): void {
		$this->schema_manager->method( 'get_schema_status' )->willReturn( self::schema_status() );
		$this->session_event_pruner->method( 'get_next_scheduled_action' )->willReturn( false );
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::Disabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::Manual );
		$this->automatic_protection->method( 'get_opted_out_at' )->willReturn( '2026-09-11 12:00:00' );

		$this->sut->status();

		$this->assertContains( 'Automatic blocking opted out at: 2026-09-11 12:00:00 UTC', $this->wp_cli_lines );
	}

	/**
	 * @testdox Explicit merchant-facing feature values are passed to the gate.
	 *
	 * @dataProvider explicit_setting_value_provider
	 *
	 * @param string $value   Requested setting value.
	 * @param bool   $enabled Requested enabled state.
	 */
	public function test_merchant_facing_features_set_routes_explicit_values( string $value, bool $enabled ): void {
		$this->merchant_facing_features_gate->expects( $this->once() )->method( 'set_enabled' )->with( $enabled )->willReturn( true );
		$this->merchant_facing_features_gate->expects( $this->never() )->method( 'reset' );

		$this->sut->merchant_facing_features_set( array( $value ) );

		$this->assertSame( array( 'The merchant-facing features setting was updated.' ), $this->wp_cli_successes );
	}

	/**
	 * @testdox The default merchant-facing feature value resets the gate.
	 */
	public function test_merchant_facing_features_set_routes_default_to_reset(): void {
		$this->merchant_facing_features_gate->expects( $this->never() )->method( 'set_enabled' );
		$this->merchant_facing_features_gate->expects( $this->once() )->method( 'reset' )->willReturn( true );

		$this->sut->merchant_facing_features_set( array( 'default' ) );

		$this->assertSame( array( 'The merchant-facing features setting was updated.' ), $this->wp_cli_successes );
	}

	/**
	 * @testdox Failed explicit merchant-facing feature updates are reported as CLI errors.
	 *
	 * @dataProvider explicit_setting_value_provider
	 *
	 * @param string $value   Requested setting value.
	 * @param bool   $enabled Requested enabled state.
	 */
	public function test_merchant_facing_features_set_reports_failed_explicit_updates( string $value, bool $enabled ): void {
		$this->merchant_facing_features_gate->expects( $this->once() )->method( 'set_enabled' )->with( $enabled )->willReturn( false );
		$this->merchant_facing_features_gate->expects( $this->never() )->method( 'reset' );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'The merchant-facing features setting could not be saved.' );
		$this->sut->merchant_facing_features_set( array( $value ) );
	}

	/**
	 * @testdox A failed merchant-facing feature reset is reported as a CLI error.
	 */
	public function test_merchant_facing_features_set_reports_failed_reset(): void {
		$this->merchant_facing_features_gate->expects( $this->never() )->method( 'set_enabled' );
		$this->merchant_facing_features_gate->expects( $this->once() )->method( 'reset' )->willReturn( false );

		$this->expectException( WPCLIErrorException::class );
		$this->expectExceptionMessage( 'The merchant-facing features setting could not be saved.' );
		$this->sut->merchant_facing_features_set( array( 'default' ) );
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
	 * Build one row of the native integrations list.
	 *
	 * @param string $type        Declaring component type.
	 * @param string $id          Gateway ID or plugin slug.
	 * @param string $integration Declared integration.
	 * @return array{type: string, id: string, integration: string}
	 */
	private static function integration_row( string $type, string $id, string $integration ): array {
		return array(
			'type'        => $type,
			'id'          => $id,
			'integration' => $integration,
		);
	}

	/**
	 * Configure the default setting states used by status tests.
	 */
	private function mock_default_settings_status(): void {
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );
		$this->automatic_protection->method( 'get_opted_out_at' )->willReturn( null );
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
// phpcs:enable Generic.Files.OneObjectStructurePerFile.MultipleFound
