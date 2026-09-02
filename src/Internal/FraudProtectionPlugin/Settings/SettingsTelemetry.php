<?php
/**
 * SettingsTelemetry class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\McStats;

defined( 'ABSPATH' ) || exit;

/**
 * Records settings actions and adds settings state to WooCommerce Tracker.
 */
class SettingsTelemetry {

	private const MC_GROUP = 'fraud-protection-automatic-protection';

	public const CHANNEL_SETTINGS = 'settings';

	public const CHANNEL_CLI = 'cli';

	/**
	 * Merchant-experience feature gate.
	 *
	 * @var MerchantExperienceFeature
	 */
	private MerchantExperienceFeature $merchant_experience;

	/**
	 * Automatic-protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private AutomaticProtectionSetting $automatic_protection;

	/**
	 * WooCommerce MC Stats service.
	 *
	 * @var McStats
	 */
	private McStats $mc_stats;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param MerchantExperienceFeature  $merchant_experience  Merchant-experience feature gate.
	 * @param AutomaticProtectionSetting $automatic_protection Automatic-protection setting.
	 * @param McStats                    $mc_stats             WooCommerce MC Stats service.
	 */
	final public function init( MerchantExperienceFeature $merchant_experience, AutomaticProtectionSetting $automatic_protection, McStats $mc_stats ): void {
		$this->merchant_experience  = $merchant_experience;
		$this->automatic_protection = $automatic_protection;
		$this->mc_stats             = $mc_stats;
	}

	/**
	 * Register Tracker integration.
	 */
	public function register(): void {
		add_filter( 'woocommerce_tracker_data', array( $this, 'add_tracker_data' ) );
	}

	/**
	 * Add this plugin's current settings state to WooCommerce Tracker.
	 *
	 * @internal
	 *
	 * @param mixed $data Existing Tracker data.
	 * @return array<string, mixed>
	 */
	public function add_tracker_data( $data ): array {
		$data       = is_array( $data ) ? $data : array();
		$extensions = is_array( $data['extensions'] ?? null ) ? $data['extensions'] : array();
		$plugin     = is_array( $extensions['woocommerce_fraud_protection'] ?? null ) ? $extensions['woocommerce_fraud_protection'] : array();

		$merchant_status = $this->merchant_experience->get_stored_status();
		if ( MerchantExperienceFeature::STATUS_DEFAULT === $merchant_status ) {
			$merchant_status = $this->merchant_experience->get_code_default() ? 'default_enabled' : 'default_disabled';
		}

		$automatic_status = $this->automatic_protection->get_stored_status();

		$plugin['merchant_experience_status']  = $merchant_status;
		$plugin['automatic_protection_status'] = $automatic_status;
		$plugin['automatic_protection_source'] = AutomaticProtectionSetting::STATUS_ENABLED === $automatic_status ? 'manual' : 'none';

		$extensions['woocommerce_fraud_protection'] = $plugin;
		$data['extensions']                         = $extensions;

		return $data;
	}

	/**
	 * Record a confirmed automatic-protection transition.
	 *
	 * @param string $outcome One of enabled, disabled, or reset.
	 * @param string $channel One of the CHANNEL_* constants.
	 */
	public function record_automatic_protection_change( string $outcome, string $channel ): void {
		if ( ! in_array( $outcome, array( 'enabled', 'disabled', 'reset' ), true ) || ! in_array( $channel, array( self::CHANNEL_SETTINGS, self::CHANNEL_CLI ), true ) ) {
			return;
		}

		if ( 'reset' === $outcome && self::CHANNEL_CLI !== $channel ) {
			return;
		}

		try {
			$this->mc_stats->add( self::MC_GROUP, $outcome );
			$this->mc_stats->add( self::MC_GROUP, $outcome . '-' . $channel );
			$this->mc_stats->do_server_side_stats();
		} catch ( \Throwable $error ) {
			FraudProtectionController::log(
				'warning',
				'Unable to record a Fraud Protection settings statistic.',
				array(
					'exception_class'   => $error::class,
					'exception_message' => $error->getMessage(),
				)
			);
		}
	}
}
