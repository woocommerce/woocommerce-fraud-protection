<?php
/**
 * SettingsTelemetryTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionChange;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSource;
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
	 * The System Under Test.
	 *
	 * @var SettingsTelemetry
	 */
	private $sut;

	/** @var MerchantExperienceFeature&\PHPUnit\Framework\MockObject\MockObject */
	private $merchant_experience;

	/** @var AutomaticProtectionSetting&\PHPUnit\Framework\MockObject\MockObject */
	private $automatic_protection;

	/** @var McStats&\PHPUnit\Framework\MockObject\MockObject */
	private $mc_stats;

	/** @var FraudProtectionLogger&\PHPUnit\Framework\MockObject\MockObject */
	private $logger;

	public function setUp(): void {
		parent::setUp();
		$this->merchant_experience  = $this->createMock( MerchantExperienceFeature::class );
		$this->automatic_protection = $this->createMock( AutomaticProtectionSetting::class );
		$this->mc_stats             = $this->createMock( McStats::class );
		$this->logger               = $this->createMock( FraudProtectionLogger::class );
		$this->sut                  = new SettingsTelemetry();
		$this->sut->init( $this->merchant_experience, $this->automatic_protection, $this->mc_stats, $this->logger );
	}

	/**
	 * @testdox Tracker data preserves existing fields and reports default states.
	 */
	public function test_tracker_preserves_existing_data_and_reports_defaults(): void {
		$this->merchant_experience->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );

		$result = $this->sut->add_tracker_data(
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
	 * @testdox Tracker data normalizes malformed input while preserving valid fields.
	 *
	 * @dataProvider malformed_tracker_data_provider
	 *
	 * @param mixed                $input    Existing Tracker data.
	 * @param array<string, mixed> $expected Expected Tracker data.
	 */
	public function test_tracker_normalizes_malformed_data( $input, array $expected ): void {
		$this->merchant_experience->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );

		$this->assertSame( $expected, $this->sut->add_tracker_data( $input ) );
	}

	/**
	 * @testdox Tracker data reports each merchant-experience status.
	 *
	 * @dataProvider merchant_experience_status_provider
	 *
	 * @param SettingStatus $status Merchant-experience status.
	 */
	public function test_tracker_reports_merchant_experience_status( SettingStatus $status ): void {
		$this->merchant_experience->method( 'get_status' )->willReturn( $status );
		$this->automatic_protection->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_source' )->willReturn( AutomaticProtectionSource::None );

		$plugin = $this->sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( $status->value, $plugin['merchant_experience_status'] );
	}

	/**
	 * Provide merchant-experience statuses.
	 *
	 * @return array<string, array{SettingStatus}>
	 */
	public function merchant_experience_status_provider(): array {
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
			'merchant_experience_status'  => 'default_disabled',
			'automatic_protection_status' => 'default_disabled',
			'automatic_protection_source' => 'none',
		);

		return array(
			'non-array data' => array(
				null,
				array( 'extensions' => array( 'woocommerce_fraud_protection' => $plugin ) ),
			),
			'non-array extensions' => array(
				array( 'root' => 'preserved', 'extensions' => 'invalid' ),
				array( 'root' => 'preserved', 'extensions' => array( 'woocommerce_fraud_protection' => $plugin ) ),
			),
			'non-array plugin data' => array(
				array(
					'extensions' => array(
						'existing'                       => array( 'value' => 1 ),
						'woocommerce_fraud_protection' => 'invalid',
					),
				),
				array(
					'extensions' => array(
						'existing'                       => array( 'value' => 1 ),
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
		$this->merchant_experience->method( 'get_status' )->willReturn( SettingStatus::DefaultDisabled );
		$this->automatic_protection->method( 'get_status' )->willReturn( $status );
		$this->automatic_protection->method( 'get_source' )->willReturn( $source );

		$plugin = $this->sut->add_tracker_data( array() )['extensions']['woocommerce_fraud_protection'];

		$this->assertSame( $expected_status, $plugin['automatic_protection_status'] );
		$this->assertSame( $expected_source, $plugin['automatic_protection_source'] );
	}

	/**
	 * Provide automatic-protection statuses and sources.
	 *
	 * @return array<string, array{SettingStatus, AutomaticProtectionSource, string, string}>
	 */
	public function automatic_protection_status_provider(): array {
		return array(
			'enabled'         => array( SettingStatus::Enabled, AutomaticProtectionSource::Manual, 'enabled', 'manual' ),
			'disabled'        => array( SettingStatus::Disabled, AutomaticProtectionSource::Manual, 'disabled', 'manual' ),
			'default enabled' => array( SettingStatus::DefaultEnabled, AutomaticProtectionSource::None, 'default_enabled', 'none' ),
		);
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
		$this->mc_stats->expects( $this->exactly( 2 ) )
			->method( 'add' )
			->withConsecutive(
				array( 'wcfp-automatic-protection', $change->value ),
				array( 'wcfp-automatic-protection', $change->value . '-' . $channel->value )
			);
		$this->mc_stats->expects( $this->once() )->method( 'do_server_side_stats' );

		$this->sut->record_automatic_protection_change( $change, $channel );
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
		$this->mc_stats->method( 'add' )->willThrowException( new \RuntimeException( 'stats unavailable' ) );
		$this->logger->expects( $this->once() )
			->method( 'log' )
			->with(
				'warning',
				'Unable to record a Fraud Protection settings stat.',
				array(
					'exception_class'   => \RuntimeException::class,
					'exception_message' => 'stats unavailable',
				)
			);

		$this->sut->record_automatic_protection_change( AutomaticProtectionChange::Disabled, SettingsChangeChannel::Cli );
	}
}
