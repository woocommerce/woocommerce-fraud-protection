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
	 * The System Under Test.
	 *
	 * @var AutomaticProtectionSettingUpdater
	 */
	private $sut;

	/** @var AutomaticProtectionSetting&\PHPUnit\Framework\MockObject\MockObject */
	private $setting;

	/** @var SettingsTelemetry&\PHPUnit\Framework\MockObject\MockObject */
	private $telemetry;

	/** @var FraudProtectionLogger&\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();
		$this->setting   = $this->createMock( AutomaticProtectionSetting::class );
		$this->telemetry = $this->createMock( SettingsTelemetry::class );
		$this->logger    = $this->createMock( FraudProtectionLogger::class );
		$this->sut       = new AutomaticProtectionSettingUpdater();
		$this->sut->init( $this->setting, $this->telemetry, $this->logger );
	}

	/**
	 * @testdox Changed writes record their action and channel.
	 */
	public function test_changed_write_records_action(): void {
		$this->setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::DefaultDisabled, SettingStatus::Enabled );
		$this->setting->expects( $this->once() )->method( 'set_enabled' )->with( true )->willReturn( true );
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings );

		$this->assertTrue( $this->sut->set_enabled( true, SettingsChangeChannel::Settings ) );
	}

	/**
	 * @testdox An explicit disabled choice from the disabled default records the selected channel.
	 */
	public function test_default_disabled_to_disabled_records_action(): void {
		$this->setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::DefaultDisabled, SettingStatus::Disabled );
		$this->setting->expects( $this->once() )->method( 'set_enabled' )->with( false )->willReturn( true );
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Settings );

		$this->assertTrue( $this->sut->set_enabled( false, SettingsChangeChannel::Settings ) );
	}

	/**
	 * @testdox A failed write does not record an action.
	 */
	public function test_failed_write_records_no_action(): void {
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );
		$this->setting->expects( $this->once() )->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->setting->expects( $this->once() )->method( 'set_enabled' )->willReturn( false );

		$this->assertFalse( $this->sut->set_enabled( true, SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox An unchanged write does not record an action.
	 */
	public function test_unchanged_write_records_no_action(): void {
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );
		$this->setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturn( SettingStatus::Disabled );
		$this->setting->expects( $this->once() )->method( 'set_enabled' )->willReturn( true );

		$this->assertTrue( $this->sut->set_enabled( false, SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox A changed reset records the CLI reset action.
	 */
	public function test_changed_reset_records_cli_action(): void {
		$this->setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::Enabled, SettingStatus::DefaultDisabled );
		$this->setting->expects( $this->once() )->method( 'reset' )->willReturn( true );
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Reset, SettingsChangeChannel::Cli );

		$this->assertTrue( $this->sut->reset( SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox Resetting an explicit disabled choice records the CLI reset action.
	 */
	public function test_disabled_to_default_disabled_reset_records_cli_action(): void {
		$this->setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturnOnConsecutiveCalls( SettingStatus::Disabled, SettingStatus::DefaultDisabled );
		$this->setting->expects( $this->once() )->method( 'reset' )->willReturn( true );
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Reset, SettingsChangeChannel::Cli );

		$this->assertTrue( $this->sut->reset( SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox A settings-channel reset is rejected before storage or telemetry.
	 */
	public function test_settings_reset_is_rejected_and_logged(): void {
		$this->setting->expects( $this->never() )->method( 'get_status' );
		$this->setting->expects( $this->never() )->method( 'reset' );
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with( 'warning', 'Automatic protection reset is only available through WP-CLI.' );

		$this->assertFalse( $this->sut->reset( SettingsChangeChannel::Settings ) );
	}

	/**
	 * @testdox A failed reset does not record an action.
	 */
	public function test_failed_reset_records_no_action(): void {
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );
		$this->setting->expects( $this->once() )->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$this->setting->expects( $this->once() )->method( 'reset' )->willReturn( false );

		$this->assertFalse( $this->sut->reset( SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox An unchanged reset does not record an action.
	 */
	public function test_unchanged_reset_records_no_action(): void {
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );
		$this->setting->expects( $this->exactly( 2 ) )->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->setting->expects( $this->once() )->method( 'reset' )->willReturn( true );

		$this->assertTrue( $this->sut->reset( SettingsChangeChannel::Cli ) );
	}
}
