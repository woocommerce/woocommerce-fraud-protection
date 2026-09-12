<?php
/**
 * SettingsTelemetryTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionChange;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSource;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantFacingFeaturesGate;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsChangeChannel;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

/**
 * Tests for SettingsTelemetry.
 */
class SettingsTelemetryTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SettingsTelemetry
	 */
	private $sut;

	/** @var MerchantFacingFeaturesGate&\PHPUnit\Framework\MockObject\MockObject */
	private $merchant_facing_features_gate;

	/** @var AutomaticProtectionSetting&\PHPUnit\Framework\MockObject\MockObject */
	private $automatic_protection;

	/** @var SessionEventStore&\PHPUnit\Framework\MockObject\MockObject */
	private $session_event_store;

	/** @var RuleStore&\PHPUnit\Framework\MockObject\MockObject */
	private $rule_store;

	/** @var FraudProtectionLogger&\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();
		$this->merchant_facing_features_gate = $this->createMock( MerchantFacingFeaturesGate::class );
		$this->automatic_protection          = $this->createMock( AutomaticProtectionSetting::class );
		$this->session_event_store           = $this->createMock( SessionEventStore::class );
		$this->rule_store                    = $this->createMock( RuleStore::class );
		$this->logger                        = $this->createMock( FraudProtectionLogger::class );
		$this->sut                           = new SettingsTelemetry();
		$this->sut->init( $this->merchant_facing_features_gate, $this->automatic_protection, $this->session_event_store, $this->rule_store, $this->logger );
	}

	/**
	 * @testdox Tracker data preserves existing fields and reports default states.
	 */
	public function test_tracker_preserves_existing_data_and_reports_defaults(): void {
		$this->stub_default_tracker_counts();
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );

		$result = $this->sut->add_tracker_data(
			array(
				'root'       => 'preserved',
				'extensions' => array(
					'existing'                     => array( 'value' => 1 ),
					'woocommerce_fraud_protection' => array( 'existing_field' => 'preserved' ),
				),
			)
		);

		$this->assertSame( 'preserved', $result['root'] );
		$this->assertSame( array( 'value' => 1 ), $result['extensions']['existing'] );
		$plugin = $result['extensions']['woocommerce_fraud_protection'];
		$this->assertSame( 'preserved', $plugin['existing_field'] );
		$this->assertSame( 'default_disabled', $plugin['merchant_facing_features_status'] );
		$this->assertSame( 'default_disabled', $plugin['automatic_protection_status'] );
		$this->assertSame( 'none', $plugin['automatic_protection_source'] );
		$this->assertNull( $plugin['automatic_protection_opted_out_at'] );
	}

	/**
	 * @testdox Tracker data normalizes malformed input while preserving valid fields.
	 *
	 * @dataProvider malformed_tracker_data_provider
	 *
	 * @param mixed                $input    Existing Tracker data.
	 * @param array<string, mixed> $expected Expected Tracker data.
	 */
	public function test_tracker_normalizes_malformed_data( $input, array $expected ): void {
		$this->stub_default_tracker_counts();
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );

		$this->assertSame( $expected, $this->sut->add_tracker_data( $input ) );
	}

	/**
	 * @testdox Tracker data reports each merchant-facing features status.
	 *
	 * @dataProvider merchant_facing_features_status_provider
	 *
	 * @param SettingStatus $status Merchant-facing features status.
	 */
	public function test_tracker_reports_merchant_facing_features_status( SettingStatus $status ): void {
		$this->stub_default_tracker_counts();
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( $status );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );

		$plugin = $this->sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( $status->value, $plugin['merchant_facing_features_status'] );
	}

	/**
	 * Provide merchant-facing features statuses.
	 *
	 * @return array<string, array{SettingStatus}>
	 */
	public function merchant_facing_features_status_provider(): array {
		return array(
			'enabled'          => array( SettingStatus::Enabled ),
			'disabled'         => array( SettingStatus::Disabled ),
			'default enabled'  => array( SettingStatus::DefaultEnabled ),
			'default disabled' => array( SettingStatus::DefaultDisabled ),
		);
	}

	/**
	 * Provide malformed Tracker data.
	 *
	 * @return array<string, array{mixed, array<string, mixed>}>
	 */
	public function malformed_tracker_data_provider(): array {
		$plugin = array(
			'merchant_facing_features_status'   => 'default_disabled',
			'automatic_protection_status'       => 'default_disabled',
			'automatic_protection_source'       => 'none',
			'automatic_protection_opted_out_at' => null,
			'automatic_blocks_suppressed_30d'   => 0,
			'automatic_blocks_applied_30d'      => 0,
			'allow_rule_matches_30d'            => 0,
			'block_rule_matches_30d'            => 0,
			'sessions_total_30d'                => 0,
			'automatic_allows_applied_30d'      => 0,
			'verify_errors_30d'                 => 0,
			'requests_rejected_30d'             => 0,
			'allow_rules_total'                 => 0,
			'block_rules_total'                 => 0,
		);

		return array(
			'non-array data'        => array(
				null,
				array( 'extensions' => array( 'woocommerce_fraud_protection' => $plugin ) ),
			),
			'non-array extensions'  => array(
				array(
					'root'       => 'preserved',
					'extensions' => 'invalid',
				),
				array(
					'root'       => 'preserved',
					'extensions' => array( 'woocommerce_fraud_protection' => $plugin ),
				),
			),
			'non-array plugin data' => array(
				array(
					'extensions' => array(
						'existing'                     => array( 'value' => 1 ),
						'woocommerce_fraud_protection' => 'invalid',
					),
				),
				array(
					'extensions' => array(
						'existing'                     => array( 'value' => 1 ),
						'woocommerce_fraud_protection' => $plugin,
					),
				),
			),
		);
	}

	/**
	 * @testdox Tracker data reports each automatic-protection status and source.
	 *
	 * @dataProvider automatic_protection_status_provider
	 *
	 * @param SettingStatus             $status          Setting status.
	 * @param AutomaticProtectionSource $source          Setting source.
	 * @param string                    $expected_status Expected Tracker status.
	 * @param string                    $expected_source Expected Tracker source.
	 */
	public function test_tracker_reports_status_and_source( SettingStatus $status, AutomaticProtectionSource $source, string $expected_status, string $expected_source ): void {
		$this->stub_default_tracker_counts();
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( $status );
		$this->automatic_protection->method( 'get_source' )->willReturn( $source );

		$plugin = $this->sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( $expected_status, $plugin['automatic_protection_status'] );
		$this->assertSame( $expected_source, $plugin['automatic_protection_source'] );
	}

	/**
	 * @testdox Tracker data reports the automatic-protection opt-out date.
	 */
	public function test_tracker_reports_opt_out_date(): void {
		$this->stub_default_tracker_counts();
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::Disabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::Manual );
		$this->automatic_protection->method( 'get_opted_out_at' )->willReturn( '2026-09-11 12:00:00' );

		$plugin = $this->sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( '2026-09-11 12:00:00', $plugin['automatic_protection_opted_out_at'] );
	}

	/**
	 * Provide automatic-protection statuses and sources.
	 *
	 * @return array<string, array{SettingStatus, AutomaticProtectionSource, string, string}>
	 */
	public function automatic_protection_status_provider(): array {
		return array(
			'enabled'                => array( SettingStatus::Enabled, AutomaticProtectionSource::Manual, 'enabled', 'manual' ),
			'disabled'               => array( SettingStatus::Disabled, AutomaticProtectionSource::Manual, 'disabled', 'manual' ),
			'default enabled'        => array( SettingStatus::DefaultEnabled, AutomaticProtectionSource::None, 'default_enabled', 'none' ),
			'automatically enrolled' => array( SettingStatus::Enabled, AutomaticProtectionSource::AutoEnroll, 'enabled', 'auto_enroll' ),
		);
	}

	/**
	 * @testdox Tracker data combines the approved aggregate mappings and active rule totals.
	 */
	public function test_tracker_reports_aggregate_counts(): void {
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::Disabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::Manual );
		$this->session_event_store->method( 'get_performance_counts' )->willReturn(
			array(
				'flagged_by_fraud_prevention' => 11,
				'blocked_automatically'       => 12,
				'allowed_by_rules'            => 13,
				'blocked_by_rules'            => 14,
			)
		);
		$this->session_event_store->method( 'get_tracker_counts' )->willReturn(
			array(
				'sessions_total_30d'           => 21,
				'automatic_allows_applied_30d' => 22,
				'verify_errors_30d'            => 23,
				'requests_rejected_30d'        => 24,
			)
		);
		$this->rule_store->method( 'get_active_counts' )->willReturn(
			array(
				'allow_rules_total' => 2,
				'block_rules_total' => 1,
			)
		);
		$plugin = $this->sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( 11, $plugin['automatic_blocks_suppressed_30d'] );
		$this->assertSame( 12, $plugin['automatic_blocks_applied_30d'] );
		$this->assertSame( 13, $plugin['allow_rule_matches_30d'] );
		$this->assertSame( 14, $plugin['block_rule_matches_30d'] );
		$this->assertSame( 21, $plugin['sessions_total_30d'] );
		$this->assertSame( 22, $plugin['automatic_allows_applied_30d'] );
		$this->assertSame( 23, $plugin['verify_errors_30d'] );
		$this->assertSame( 24, $plugin['requests_rejected_30d'] );
		$this->assertSame( 2, $plugin['allow_rules_total'] );
		$this->assertSame( 1, $plugin['block_rules_total'] );
	}

	/**
	 * @testdox Each Tracker aggregate failure omits only its own group.
	 *
	 * @dataProvider tracker_failure_provider
	 *
	 * @param string   $failed_group Failed aggregate group.
	 * @param string[] $missing_keys Keys that must be omitted.
	 */
	public function test_tracker_isolates_aggregate_failures( string $failed_group, array $missing_keys ): void {
		$this->merchant_facing_features_gate->method( 'get_status' )->willReturn( SettingStatus::Disabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::Manual );
		$performance = $this->session_event_store->method( 'get_performance_counts' );
		$tracker     = $this->session_event_store->method( 'get_tracker_counts' );
		$rules       = $this->rule_store->method( 'get_active_counts' );
		if ( 'performance' === $failed_group ) {
			$performance->willThrowException( new \RuntimeException( 'performance failed' ) );
		} else {
			$performance->willReturn(
				array(
					'flagged_by_fraud_prevention' => 11,
					'blocked_automatically'       => 12,
					'allowed_by_rules'            => 13,
					'blocked_by_rules'            => 14,
				)
			);
		}
		if ( 'tracker' === $failed_group ) {
			$tracker->willThrowException( new \RuntimeException( 'tracker failed' ) );
		} else {
			$tracker->willReturn(
				array(
					'sessions_total_30d'           => 21,
					'automatic_allows_applied_30d' => 22,
					'verify_errors_30d'            => 23,
					'requests_rejected_30d'        => 24,
				)
			);
		}
		if ( 'rules' === $failed_group ) {
			$rules->willThrowException( new \RuntimeException( 'rules failed' ) );
		} else {
			$rules->willReturn(
				array(
					'allow_rules_total' => 1,
					'block_rules_total' => 1,
				)
			);
		}
		$this->logger->expects( $this->once() )->method( 'log' );

		$plugin = $this->sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( 'disabled', $plugin['merchant_facing_features_status'] );
		$this->assertSame( 'enabled', $plugin['automatic_protection_status'] );
		$this->assertSame( 'manual', $plugin['automatic_protection_source'] );
		$count_keys = array( 'automatic_blocks_suppressed_30d', 'automatic_blocks_applied_30d', 'allow_rule_matches_30d', 'block_rule_matches_30d', 'sessions_total_30d', 'automatic_allows_applied_30d', 'verify_errors_30d', 'requests_rejected_30d', 'allow_rules_total', 'block_rules_total' );
		foreach ( $count_keys as $key ) {
			if ( in_array( $key, $missing_keys, true ) ) {
				$this->assertArrayNotHasKey( $key, $plugin );
			} else {
				$this->assertArrayHasKey( $key, $plugin );
			}
		}
	}

	/**
	 * Provide isolated Tracker failures and their omitted keys.
	 *
	 * @return array<string, array{string, string[]}>
	 */
	public function tracker_failure_provider(): array {
		return array(
			'performance query' => array( 'performance', array( 'automatic_blocks_suppressed_30d', 'automatic_blocks_applied_30d', 'allow_rule_matches_30d', 'block_rule_matches_30d' ) ),
			'tracker query'     => array( 'tracker', array( 'sessions_total_30d', 'automatic_allows_applied_30d', 'verify_errors_30d', 'requests_rejected_30d' ) ),
			'rule query'        => array( 'rules', array( 'allow_rules_total', 'block_rules_total' ) ),
		);
	}

	/**
	 * @testdox Settings views add only the approved source to the Fraud Protection event.
	 *
	 * @dataProvider settings_view_source_provider
	 *
	 * @param mixed  $request_source Request source value.
	 * @param string $expected       Expected bounded source.
	 */
	public function test_settings_view_source_is_allowlisted( $request_source, string $expected ): void {
		$_GET['source'] = $request_source;
		$properties     = array(
			'tab'     => 'woocommerce_fraud_protection',
			'section' => 'current-section',
		);

		$result = $this->sut->add_settings_view_source( $properties, 'wcadmin_settings_view' );

		$this->assertSame( $expected, $result['source'] );
		$this->assertSame( 'current-section', $result['section'] );
	}

	/**
	 * Provide settings view sources.
	 *
	 * @return array<string, array{mixed, string}>
	 */
	public function settings_view_source_provider(): array {
		return array(
			'inbox marker'     => array( 'inbox', 'inbox' ),
			'absent marker'    => array( null, 'settings' ),
			'unknown marker'   => array( 'other', 'settings' ),
			'uppercase marker' => array( 'INBOX', 'settings' ),
			'spaced marker'    => array( 'inbox ', 'settings' ),
			'invalid marker'   => array( array( 'inbox' ), 'settings' ),
		);
	}

	/**
	 * @testdox Other Tracks events and malformed properties remain unchanged.
	 */
	public function test_settings_view_source_preserves_unrelated_values(): void {
		$this->assertSame( 'invalid', $this->sut->add_settings_view_source( 'invalid', 'wcadmin_settings_view' ) );
		$this->assertSame(
			array( 'tab' => 'general' ),
			$this->sut->add_settings_view_source( array( 'tab' => 'general' ), 'wcadmin_settings_view' )
		);
		$this->assertSame(
			array( 'tab' => 'woocommerce_fraud_protection' ),
			$this->sut->add_settings_view_source( array( 'tab' => 'woocommerce_fraud_protection' ), 'wcadmin_other_event' )
		);
	}

	/**
	 * @testdox Each supported transition sends the exact Tracks event and activity properties.
	 *
	 * @dataProvider supported_action_provider
	 *
	 * @param AutomaticProtectionChange $change  Expected action outcome.
	 * @param SettingsChangeChannel     $channel Expected action channel.
	 */
	public function test_supported_actions_use_exact_tracks_properties( AutomaticProtectionChange $change, SettingsChangeChannel $channel ): void {
		$this->stub_default_change_counts();
		$captured = $this->capture_tracks_change_event( $change, $channel );

		$this->assertSame( $change->value, $captured['state'] );
		$this->assertSame( $channel->value, $captured['channel'] );
		$this->assertSame( 0, $captured['automatic_blocks_applied_1d'] );
		$this->assertSame( 0, $captured['automatic_blocks_applied_7d'] );
		$this->assertSame( 0, $captured['automatic_blocks_applied_30d'] );
		$this->assertSame( 0, $captured['allow_rules_created_1d'] );
		$this->assertSame( 0, $captured['allow_rules_created_7d'] );
		$this->assertSame( 0, $captured['allow_rules_created_30d'] );
		$this->assertSame( 0, $captured['block_rules_created_1d'] );
		$this->assertSame( 0, $captured['block_rules_created_7d'] );
		$this->assertSame( 0, $captured['block_rules_created_30d'] );
	}

	/**
	 * @testdox The change event sends exact non-zero aggregate values.
	 */
	public function test_change_event_sends_non_zero_aggregate_values(): void {
		$this->session_event_store->expects( $this->once() )->method( 'get_automatic_block_counts' )->willReturn(
			array(
				'automatic_blocks_applied_1d'  => 1,
				'automatic_blocks_applied_7d'  => 7,
				'automatic_blocks_applied_30d' => 30,
			)
		);
		$this->rule_store->expects( $this->once() )->method( 'get_creation_counts' )->willReturn(
			array(
				'allow_rules_created_1d'  => 2,
				'allow_rules_created_7d'  => 8,
				'allow_rules_created_30d' => 31,
				'block_rules_created_1d'  => 3,
				'block_rules_created_7d'  => 9,
				'block_rules_created_30d' => 32,
			)
		);
		$expected = array(
			'state'                        => 'enabled',
			'channel'                      => 'settings',
			'automatic_blocks_applied_1d'  => 1,
			'automatic_blocks_applied_7d'  => 7,
			'automatic_blocks_applied_30d' => 30,
			'allow_rules_created_1d'       => 2,
			'allow_rules_created_7d'       => 8,
			'allow_rules_created_30d'      => 31,
			'block_rules_created_1d'       => 3,
			'block_rules_created_7d'       => 9,
			'block_rules_created_30d'      => 32,
		);
		$captured = $this->capture_tracks_change_event( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings );
		unset( $captured['feature_email_improvements'] );

		$this->assertSame( $expected, $captured );
	}

	/**
	 * @testdox The change event uses WooCommerce consent and does not send when tracking is disabled.
	 */
	public function test_change_event_respects_woocommerce_tracking_consent(): void {
		$this->stub_default_change_counts();
		$requests = 0;
		$request  = function () use ( &$requests ) {
			++$requests;
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		update_option( 'woocommerce_allow_tracking', 'no' );
		add_filter( 'pre_http_request', $request );

		$this->sut->record_automatic_protection_change( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings );

		$this->assertSame( 0, $requests );
	}

	/**
	 * @testdox A Tracks sender failure is logged and does not escape the setting update.
	 */
	public function test_change_event_isolates_tracks_sender_failure(): void {
		$this->stub_default_change_counts();
		$failure = function ( $properties, $event_name ) {
			if ( 'wcadmin_fraud_protection_automatic_protection_changed' === $event_name ) {
				throw new \RuntimeException( 'Tracks unavailable' );
			}

			return $properties;
		};
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with(
				'warning',
				'Unable to record a Fraud Protection Tracks event.',
				array(
					'exception_class'   => \RuntimeException::class,
					'exception_message' => 'Tracks unavailable',
				)
			);
		update_option( 'woocommerce_allow_tracking', 'yes' );
		add_filter( 'woocommerce_tracks_event_properties', $failure, 20, 2 );

		$this->sut->record_automatic_protection_change( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings );

		remove_filter( 'woocommerce_tracks_event_properties', $failure, 20 );
	}

	/**
	 * @testdox Enrollment opt-outs use the exact event and normalize an unexpected source.
	 */
	public function test_enrollment_opt_out_uses_exact_event_properties(): void {
		$captured = $this->capture_tracks_event(
			'wcadmin_fraud_protection_enrollment_preference_changed',
			fn() => $this->sut->record_enrollment_opt_out( 'other' )
		);
		unset( $captured['feature_email_improvements'] );

		$this->assertSame(
			array(
				'state'  => 'opted_out',
				'source' => 'settings',
			),
			$captured
		);
	}

	/**
	 * Provide supported action and channel combinations.
	 *
	 * @return array<string, array{AutomaticProtectionChange, SettingsChangeChannel}>
	 */
	public function supported_action_provider(): array {
		return array(
			'enabled from settings'      => array( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings ),
			'disabled from settings'     => array( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Settings ),
			'enabled from CLI'           => array( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Cli ),
			'disabled from CLI'          => array( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Cli ),
			'reset from CLI'             => array( AutomaticProtectionChange::Reset, SettingsChangeChannel::Cli ),
			'enabled by auto enrollment' => array( AutomaticProtectionChange::Enabled, SettingsChangeChannel::AutoEnroll ),
		);
	}

	/**
	 * @testdox A failed activity query omits its complete group and still records the event.
	 */
	public function test_record_change_isolates_activity_query_failure(): void {
		$this->session_event_store->method( 'get_automatic_block_counts' )->willThrowException( new \RuntimeException( 'query unavailable' ) );
		$this->stub_default_rule_creation_counts();
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with(
				'warning',
				'Unable to collect Fraud Protection telemetry counts.',
				array(
					'aggregate'         => 'automatic_block_counts',
					'exception_class'   => \RuntimeException::class,
					'exception_message' => 'query unavailable',
				)
			);

		$captured = $this->capture_tracks_change_event( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Cli );

		$this->assertArrayNotHasKey( 'automatic_blocks_applied_1d', $captured );
		$this->assertArrayNotHasKey( 'automatic_blocks_applied_7d', $captured );
		$this->assertArrayNotHasKey( 'automatic_blocks_applied_30d', $captured );
		$this->assertArrayHasKey( 'allow_rules_created_1d', $captured );
		$this->assertArrayHasKey( 'block_rules_created_30d', $captured );
	}

	/**
	 * @testdox A failed rule query omits all rule counts and still records the event.
	 */
	public function test_record_change_isolates_rule_query_failure(): void {
		$this->stub_default_automatic_block_counts();
		$this->rule_store->method( 'get_creation_counts' )->willThrowException( new \RuntimeException( 'query unavailable' ) );
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with(
				'warning',
				'Unable to collect Fraud Protection telemetry counts.',
				array(
					'aggregate'         => 'rule_creation_counts',
					'exception_class'   => \RuntimeException::class,
					'exception_message' => 'query unavailable',
				)
			);

		$captured = $this->capture_tracks_change_event( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings );

		$this->assertArrayHasKey( 'automatic_blocks_applied_1d', $captured );
		$this->assertArrayHasKey( 'automatic_blocks_applied_30d', $captured );
		$this->assertArrayNotHasKey( 'allow_rules_created_1d', $captured );
		$this->assertArrayNotHasKey( 'allow_rules_created_7d', $captured );
		$this->assertArrayNotHasKey( 'allow_rules_created_30d', $captured );
		$this->assertArrayNotHasKey( 'block_rules_created_1d', $captured );
		$this->assertArrayNotHasKey( 'block_rules_created_7d', $captured );
		$this->assertArrayNotHasKey( 'block_rules_created_30d', $captured );
	}

	/**
	 * Capture the custom properties before WooCommerce adds global properties.
	 *
	 * @param AutomaticProtectionChange $change  Setting change.
	 * @param SettingsChangeChannel     $channel Change channel.
	 * @return array<string, mixed>
	 */
	private function capture_tracks_change_event( AutomaticProtectionChange $change, SettingsChangeChannel $channel ): array {
		return $this->capture_tracks_event(
			'wcadmin_fraud_protection_automatic_protection_changed',
			fn() => $this->sut->record_automatic_protection_change( $change, $channel )
		);
	}

	/**
	 * Capture one event before WooCommerce adds global properties.
	 *
	 * @param string   $expected_event Expected event name.
	 * @param callable $record_event   Event sender.
	 * @phpstan-param callable(): void $record_event
	 * @return array<string, mixed>
	 */
	private function capture_tracks_event( string $expected_event, callable $record_event ): array {
		$captured = array();
		$filter   = function ( $properties, $event_name ) use ( &$captured, $expected_event ) {
			if ( $expected_event === $event_name ) {
				$captured = $properties;
			}

			return $properties;
		};
		$request  = function () {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 200 ),
				'cookies'  => array(),
			);
		};
		update_option( 'woocommerce_allow_tracking', 'yes' );
		wp_set_current_user( 0 );
		add_filter( 'woocommerce_tracks_event_properties', $filter, 20, 2 );
		add_filter( 'pre_http_request', $request );

		$record_event();

		remove_filter( 'woocommerce_tracks_event_properties', $filter, 20 );
		remove_filter( 'pre_http_request', $request );

		return $captured;
	}

	/**
	 * Stub the default Tracker counts.
	 */
	private function stub_default_tracker_counts(): void {
		$this->session_event_store->method( 'get_performance_counts' )->willReturn(
			array(
				'flagged_by_fraud_prevention' => 0,
				'blocked_automatically'       => 0,
				'allowed_by_rules'            => 0,
				'blocked_by_rules'            => 0,
			)
		);
		$this->session_event_store->method( 'get_tracker_counts' )->willReturn(
			array(
				'sessions_total_30d'           => 0,
				'automatic_allows_applied_30d' => 0,
				'verify_errors_30d'            => 0,
				'requests_rejected_30d'        => 0,
			)
		);
		$this->rule_store->method( 'get_active_counts' )->willReturn(
			array(
				'allow_rules_total' => 0,
				'block_rules_total' => 0,
			)
		);
	}

	/**
	 * Stub the default change-event counts.
	 */
	private function stub_default_change_counts(): void {
		$this->stub_default_automatic_block_counts();
		$this->stub_default_rule_creation_counts();
	}

	/**
	 * Stub the default automatic-block counts.
	 */
	private function stub_default_automatic_block_counts(): void {
		$this->session_event_store->method( 'get_automatic_block_counts' )->willReturn(
			array(
				'automatic_blocks_applied_1d'  => 0,
				'automatic_blocks_applied_7d'  => 0,
				'automatic_blocks_applied_30d' => 0,
			)
		);
	}

	/**
	 * Stub the default rule-creation counts.
	 */
	private function stub_default_rule_creation_counts(): void {
		$this->rule_store->method( 'get_creation_counts' )->willReturn(
			array(
				'allow_rules_created_1d'  => 0,
				'allow_rules_created_7d'  => 0,
				'allow_rules_created_30d' => 0,
				'block_rules_created_1d'  => 0,
				'block_rules_created_7d'  => 0,
				'block_rules_created_30d' => 0,
			)
		);
	}
}
