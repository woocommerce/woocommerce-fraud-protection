<?php
/**
 * AutomaticProtectionSettingUpdaterTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionChange;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSettingUpdater;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsChangeChannel;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

/**
 * Tests for AutomaticProtectionSettingUpdater.
 */
class AutomaticProtectionSettingUpdaterTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox Changed writes record their action and channel.
	 */
	public function test_changed_write_records_action(): void {
		$setting = $this->createMock( AutomaticProtectionSetting::class );
		$setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::DefaultDisabled, SettingStatus::Enabled );
		$setting->expects( $this->once() )->method( 'set_enabled' )->with( true )->willReturn( true );
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings );

		$this->assertTrue( $this->make_sut( $setting, $telemetry )->set_enabled( true, SettingsChangeChannel::Settings ) );
	}

	/**
	 * @testdox An explicit disabled choice from the disabled default records the selected channel.
	 */
	public function test_default_disabled_to_disabled_records_action(): void {
		$setting = $this->createMock( AutomaticProtectionSetting::class );
		$setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::DefaultDisabled, SettingStatus::Disabled );
		$setting->expects( $this->once() )->method( 'set_enabled' )->with( false )->willReturn( true );
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Settings );

		$this->assertTrue( $this->make_sut( $setting, $telemetry )->set_enabled( false, SettingsChangeChannel::Settings ) );
	}

	/**
	 * @testdox Failed and unchanged writes do not record actions.
	 */
	public function test_failed_and_unchanged_writes_record_no_action(): void {
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$failed = $this->createMock( AutomaticProtectionSetting::class );
		$failed->expects( $this->once() )->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$failed->expects( $this->once() )->method( 'set_enabled' )->willReturn( false );
		$this->assertFalse( $this->make_sut( $failed, $telemetry )->set_enabled( true, SettingsChangeChannel::Cli ) );

		$unchanged = $this->createMock( AutomaticProtectionSetting::class );
		$unchanged->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturn( SettingStatus::Disabled );
		$unchanged->expects( $this->once() )->method( 'set_enabled' )->willReturn( true );
		$this->assertTrue( $this->make_sut( $unchanged, $telemetry )->set_enabled( false, SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox A changed reset records the CLI reset action.
	 */
	public function test_changed_reset_records_cli_action(): void {
		$setting = $this->createMock( AutomaticProtectionSetting::class );
		$setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::Enabled, SettingStatus::DefaultDisabled );
		$setting->expects( $this->once() )->method( 'reset' )->willReturn( true );
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Reset, SettingsChangeChannel::Cli );

		$this->assertTrue( $this->make_sut( $setting, $telemetry )->reset( SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox Resetting an explicit disabled choice records the CLI reset action.
	 */
	public function test_disabled_to_default_disabled_reset_records_cli_action(): void {
		$setting = $this->createMock( AutomaticProtectionSetting::class );
		$setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::Disabled, SettingStatus::DefaultDisabled );
		$setting->expects( $this->once() )->method( 'reset' )->willReturn( true );
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Reset, SettingsChangeChannel::Cli );

		$this->assertTrue( $this->make_sut( $setting, $telemetry )->reset( SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox A settings-channel reset is rejected before storage or telemetry.
	 */
	public function test_settings_reset_is_rejected_and_logged(): void {
		$setting = $this->createMock( AutomaticProtectionSetting::class );
		$setting->expects( $this->never() )->method( 'get_status' );
		$setting->expects( $this->never() )->method( 'reset' );
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );
		$logger = $this->createMock( FraudProtectionLogger::class );
		$logger->expects( $this->once() )
			->method( 'log' )
			->with( 'warning', 'Automatic protection reset is only available through WP-CLI.' );

		$this->assertFalse( $this->make_sut( $setting, $telemetry, $logger )->reset( SettingsChangeChannel::Settings ) );
	}

	/**
	 * @testdox Failed and unchanged resets do not record actions.
	 */
	public function test_failed_and_unchanged_resets_record_no_action(): void {
		$telemetry = $this->createMock( SettingsTelemetry::class );
		$telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$failed = $this->createMock( AutomaticProtectionSetting::class );
		$failed->expects( $this->once() )->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$failed->expects( $this->once() )->method( 'reset' )->willReturn( false );
		$this->assertFalse( $this->make_sut( $failed, $telemetry )->reset( SettingsChangeChannel::Cli ) );

		$unchanged = $this->createMock( AutomaticProtectionSetting::class );
		$unchanged->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$unchanged->expects( $this->once() )->method( 'reset' )->willReturn( true );
		$this->assertTrue( $this->make_sut( $unchanged, $telemetry )->reset( SettingsChangeChannel::Cli ) );
	}

	private function make_sut( AutomaticProtectionSetting $setting, SettingsTelemetry $telemetry, ?FraudProtectionLogger $logger = null ): AutomaticProtectionSettingUpdater {
		$sut = new AutomaticProtectionSettingUpdater();
		$sut->init( $setting, $telemetry, $logger ?? $this->createMock( FraudProtectionLogger::class ) );

		return $sut;
	}
}
