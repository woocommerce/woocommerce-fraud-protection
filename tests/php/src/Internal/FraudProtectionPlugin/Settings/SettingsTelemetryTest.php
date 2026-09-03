<?php
/**
 * SettingsTelemetryTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionChange;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\McStats;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\MerchantExperienceFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsChangeChannel;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

/**
 * Tests for SettingsTelemetry.
 */
class SettingsTelemetryTest extends FraudProtectionUnitTestCase {

	/**
	 * Merchant experience feature.
	 *
	 * @var MerchantExperienceFeature
	 */
	private $merchant_experience;

	/**
	 * Automatic protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private $automatic_protection;

	public function setUp(): void {
		parent::setUp();
		$this->merchant_experience  = new MerchantExperienceFeature();
		$this->automatic_protection = new AutomaticProtectionSetting();
		$this->merchant_experience->reset();
		$this->automatic_protection->reset();
	}

	public function tearDown(): void {
		$this->merchant_experience->reset();
		$this->automatic_protection->reset();
		remove_all_filters( 'woocommerce_tracker_data' );
		parent::tearDown();
	}

	/**
	 * @testdox Tracker data preserves existing fields and reports default states.
	 */
	public function test_tracker_preserves_existing_data_and_reports_defaults(): void {
		$sut = $this->make_sut( $this->createMock( McStats::class ) );

		$result = $sut->add_tracker_data(
			array(
				'root'       => 'preserved',
				'extensions' => array(
					'existing'                       => array( 'value' => 1 ),
					'woocommerce_fraud_protection' => array( 'existing_field' => 'preserved' ),
				),
			)
		);

		$this->assertSame( 'preserved', $result['root'] );
		$this->assertSame( array( 'value' => 1 ), $result['extensions']['existing'] );
		$plugin = $result['extensions']['woocommerce_fraud_protection'];
		$this->assertSame( 'preserved', $plugin['existing_field'] );
		$this->assertSame( 'default_disabled', $plugin['merchant_experience_status'] );
		$this->assertSame( 'default_disabled', $plugin['automatic_protection_status'] );
		$this->assertSame( 'none', $plugin['automatic_protection_source'] );
	}

	/**
	 * @testdox Tracker data reports explicit settings and a manual enabled source.
	 */
	public function test_tracker_reports_explicit_states(): void {
		$this->merchant_experience->set_enabled( false );
		$this->automatic_protection->set_enabled( true );
		$sut = $this->make_sut( $this->createMock( McStats::class ) );

		$plugin = $sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( 'disabled', $plugin['merchant_experience_status'] );
		$this->assertSame( 'enabled', $plugin['automatic_protection_status'] );
		$this->assertSame( 'manual', $plugin['automatic_protection_source'] );
	}

	/**
	 * @testdox Tracker data reports an explicit disabled automatic-protection state with a manual source.
	 */
	public function test_tracker_reports_explicit_disabled_state(): void {
		$this->automatic_protection->set_enabled( false );
		$sut = $this->make_sut( $this->createMock( McStats::class ) );

		$plugin = $sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( 'disabled', $plugin['automatic_protection_status'] );
		$this->assertSame( 'manual', $plugin['automatic_protection_source'] );
	}

	/**
	 * @testdox Tracker data reports an enabled merchant-experience code default.
	 */
	public function test_tracker_reports_enabled_code_default(): void {
		$merchant_experience = new class() extends MerchantExperienceFeature {
			public function get_default(): SettingStatus {
				return SettingStatus::Enabled;
			}
		};
		$sut = new SettingsTelemetry();
		$sut->init( $merchant_experience, $this->automatic_protection, $this->createMock( McStats::class ), $this->createMock( FraudProtectionLogger::class ) );

		$plugin = $sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( 'default_enabled', $plugin['merchant_experience_status'] );
	}

	/**
	 * @testdox An enabled automatic-protection default has no manual source.
	 */
	public function test_tracker_reports_enabled_automatic_protection_default_without_source(): void {
		$automatic_protection = new class() extends AutomaticProtectionSetting {
			public function get_default(): SettingStatus {
				return SettingStatus::Enabled;
			}
		};
		$sut = new SettingsTelemetry();
		$sut->init( $this->merchant_experience, $automatic_protection, $this->createMock( McStats::class ), $this->createMock( FraudProtectionLogger::class ) );

		$plugin = $sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( 'default_enabled', $plugin['automatic_protection_status'] );
		$this->assertSame( 'none', $plugin['automatic_protection_source'] );
	}

	/**
	 * @testdox A confirmed action sends aggregate and channel stats in the focused group.
	 */
	public function test_record_change_sends_aggregate_and_channel_stats(): void {
		$mc_stats = $this->createMock( McStats::class );
		$mc_stats->expects( $this->exactly( 2 ) )
			->method( 'add' )
			->withConsecutive(
				array( 'fraud-protection-automatic-protection', 'enabled' ),
				array( 'fraud-protection-automatic-protection', 'enabled-settings' )
			);
		$mc_stats->expects( $this->once() )->method( 'do_server_side_stats' );

		$this->make_sut( $mc_stats )->record_automatic_protection_change( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings );
	}

	/**
	 * @testdox Each supported action sends the expected aggregate and channel stats.
	 *
	 * @dataProvider supported_action_provider
	 *
	 * @param AutomaticProtectionChange $change  Expected action outcome.
	 * @param SettingsChangeChannel      $channel Expected action channel.
	 */
	public function test_supported_actions_use_exact_stat_names( AutomaticProtectionChange $change, SettingsChangeChannel $channel ): void {
		$mc_stats = $this->createMock( McStats::class );
		$mc_stats->expects( $this->exactly( 2 ) )
			->method( 'add' )
			->withConsecutive(
				array( 'fraud-protection-automatic-protection', $change->value ),
				array( 'fraud-protection-automatic-protection', $change->value . '-' . $channel->value )
			);
		$mc_stats->expects( $this->once() )->method( 'do_server_side_stats' );

		$this->make_sut( $mc_stats )->record_automatic_protection_change( $change, $channel );
	}

	/**
	 * Provide supported action and channel combinations.
	 *
	 * @return array<string, array{AutomaticProtectionChange, SettingsChangeChannel}>
	 */
	public function supported_action_provider(): array {
		return array(
			'enabled from settings'  => array( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Settings ),
			'disabled from settings' => array( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Settings ),
			'enabled from CLI'       => array( AutomaticProtectionChange::Enabled, SettingsChangeChannel::Cli ),
			'disabled from CLI'      => array( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Cli ),
			'reset from CLI'         => array( AutomaticProtectionChange::Reset, SettingsChangeChannel::Cli ),
		);
	}

	/**
	 * @testdox Telemetry failures do not escape into a setting operation.
	 */
	public function test_record_change_isolates_telemetry_failure(): void {
		$mc_stats = $this->createMock( McStats::class );
		$mc_stats->method( 'add' )->willThrowException( new \RuntimeException( 'stats unavailable' ) );
		$logger = $this->createMock( FraudProtectionLogger::class );
		$logger->expects( $this->once() )
			->method( 'log' )
			->with( 'warning', 'Unable to record a Fraud Protection settings stat.', $this->isType( 'array' ) );

		$this->make_sut( $mc_stats, $logger )->record_automatic_protection_change( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Cli );
	}

	private function make_sut( McStats $mc_stats, ?FraudProtectionLogger $logger = null ): SettingsTelemetry {
		$sut = new SettingsTelemetry();
		$sut->init( $this->merchant_experience, $this->automatic_protection, $mc_stats, $logger ?? $this->createMock( FraudProtectionLogger::class ) );

		return $sut;
	}
}
