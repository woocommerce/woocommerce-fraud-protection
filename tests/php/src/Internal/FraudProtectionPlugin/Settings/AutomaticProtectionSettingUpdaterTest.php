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

	/** @var AutomaticProtectionSetting */
	private $setting;

	/** @var SettingsTelemetry&\PHPUnit\Framework\MockObject\MockObject */
	private $telemetry;

	/** @var FraudProtectionLogger&\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();
		$this->setting   = new AutomaticProtectionSetting();
		$this->setting->reset();
		$this->telemetry = $this->createMock( SettingsTelemetry::class );
		$this->logger    = $this->createMock( FraudProtectionLogger::class );
		$this->sut       = new AutomaticProtectionSettingUpdater();
		$this->sut->init( $this->setting, $this->telemetry, $this->logger );
	}

	/**
	 * @testdox Changed writes record their action and channel.
	 *
	 * @dataProvider changed_write_provider
	 *
	 * @param bool                      $enabled Requested enabled state.
	 * @param SettingStatus             $after   Status after the write.
	 * @param AutomaticProtectionChange $change  Expected recorded change.
	 */
	public function test_changed_write_records_action( bool $enabled, SettingStatus $after, AutomaticProtectionChange $change ): void {
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( $change, SettingsChangeChannel::Settings );

		$this->assertTrue( $this->sut->set_enabled( $enabled, SettingsChangeChannel::Settings ) );
		$this->assertSame( $after, $this->setting->get_status() );
	}

	/**
	 * Provide changed automatic-protection writes.
	 *
	 * @return array<string, array{bool, SettingStatus, AutomaticProtectionChange}>
	 */
	public function changed_write_provider(): array {
		return array(
			'enable from default'          => array( true, SettingStatus::Enabled, AutomaticProtectionChange::Enabled ),
			'explicitly disable a default' => array( false, SettingStatus::Disabled, AutomaticProtectionChange::Disabled ),
		);
	}

	/**
	 * @testdox A failed write does not record an action.
	 */
	public function test_failed_write_records_no_action(): void {
		$setting = $this->createMock( AutomaticProtectionSetting::class );
		$setting->expects( $this->once() )->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$setting->expects( $this->once() )->method( 'set_enabled' )->willReturn( false );
		$this->sut->init( $setting, $this->telemetry, $this->logger );
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$this->assertFalse( $this->sut->set_enabled( true, SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox An unchanged write does not record an action.
	 */
	public function test_unchanged_write_records_no_action(): void {
		$this->setting->set_enabled( false );
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$this->assertTrue( $this->sut->set_enabled( false, SettingsChangeChannel::Cli ) );
		$this->assertSame( SettingStatus::Disabled, $this->setting->get_status() );
	}

	/**
	 * @testdox Changed resets record the CLI reset action.
	 *
	 * @dataProvider changed_reset_provider
	 *
	 * @param bool $enabled Stored state before the reset.
	 */
	public function test_changed_reset_records_cli_action( bool $enabled ): void {
		$this->setting->set_enabled( $enabled );
		$this->telemetry->expects( $this->once() )
			->method( 'record_automatic_protection_change' )
			->with( AutomaticProtectionChange::Reset, SettingsChangeChannel::Cli );

		$this->assertTrue( $this->sut->reset( SettingsChangeChannel::Cli ) );
		$this->assertSame( SettingStatus::DefaultDisabled, $this->setting->get_status() );
	}

	/**
	 * Provide changed automatic-protection resets.
	 *
	 * @return array<string, array{bool}>
	 */
	public function changed_reset_provider(): array {
		return array(
			'enabled'  => array( true ),
			'disabled' => array( false ),
		);
	}

	/**
	 * @testdox A settings-channel reset is rejected before storage or telemetry.
	 */
	public function test_settings_reset_is_rejected_and_logged(): void {
		$this->setting->set_enabled( true );
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with( 'warning', 'Automatic protection reset is only available through WP-CLI.' );

		$this->assertFalse( $this->sut->reset( SettingsChangeChannel::Settings ) );
		$this->assertSame( SettingStatus::Enabled, $this->setting->get_status() );
	}

	/**
	 * @testdox A failed reset does not record an action.
	 */
	public function test_failed_reset_records_no_action(): void {
		$setting = $this->createMock( AutomaticProtectionSetting::class );
		$setting->expects( $this->once() )->method( 'get_status' )->willReturn( SettingStatus::Enabled );
		$setting->expects( $this->once() )->method( 'reset' )->willReturn( false );
		$this->sut->init( $setting, $this->telemetry, $this->logger );
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$this->assertFalse( $this->sut->reset( SettingsChangeChannel::Cli ) );
	}

	/**
	 * @testdox An unchanged reset does not record an action.
	 */
	public function test_unchanged_reset_records_no_action(): void {
		$this->telemetry->expects( $this->never() )->method( 'record_automatic_protection_change' );

		$this->assertTrue( $this->sut->reset( SettingsChangeChannel::Cli ) );
		$this->assertSame( SettingStatus::DefaultDisabled, $this->setting->get_status() );
	}
}
