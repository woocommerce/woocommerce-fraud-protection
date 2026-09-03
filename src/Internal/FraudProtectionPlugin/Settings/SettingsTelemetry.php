<?php
/**
 * SettingsTelemetry class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Records settings actions and adds settings state to WooCommerce Tracker.
 */
class SettingsTelemetry {

	private const MC_NAMESPACE = 'wcfp';

	private const MC_GROUP_AUTOMATIC_PROTECTION = 'automatic-protection';

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
	 * Plugin MC Stats service.
	 *
	 * @var McStats
	 */
	private McStats $mc_stats;

	/**
	 * Logger instance.
	 *
	 * @var FraudProtectionLogger
	 */
	private FraudProtectionLogger $logger;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param MerchantExperienceFeature  $merchant_experience  Merchant-experience feature gate.
	 * @param AutomaticProtectionSetting $automatic_protection Automatic-protection setting.
	 * @param McStats                    $mc_stats             Plugin MC Stats service.
	 * @param FraudProtectionLogger      $logger               Logger instance.
	 */
	final public function init( MerchantExperienceFeature $merchant_experience, AutomaticProtectionSetting $automatic_protection, McStats $mc_stats, FraudProtectionLogger $logger ): void {
		$this->merchant_experience  = $merchant_experience;
		$this->automatic_protection = $automatic_protection;
		$this->mc_stats             = $mc_stats;
		$this->logger               = $logger;
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

		$plugin['merchant_experience_status']  = $this->merchant_experience->get_status()->value;
		$plugin['automatic_protection_status'] = $this->automatic_protection->get_status()->value;
		$plugin['automatic_protection_source'] = $this->automatic_protection->get_source()->value;

		$extensions['woocommerce_fraud_protection'] = $plugin;
		$data['extensions']                         = $extensions;

		return $data;
	}

	/**
	 * Record a confirmed automatic-protection transition.
	 *
	 * @param AutomaticProtectionChange $change Setting change.
	 * @param SettingsChangeChannel     $channel Change channel.
	 */
	public function record_automatic_protection_change( AutomaticProtectionChange $change, SettingsChangeChannel $channel ): void {
		try {
			$this->mc_stats->add( self::MC_NAMESPACE . '-' . self::MC_GROUP_AUTOMATIC_PROTECTION, $change->value );
			$this->mc_stats->add( self::MC_NAMESPACE . '-' . self::MC_GROUP_AUTOMATIC_PROTECTION, $change->value . '-' . $channel->value );
			$this->mc_stats->do_server_side_stats();
		} catch ( \Throwable $error ) {
			$this->logger->log(
				'warning',
				'Unable to record a Fraud Protection settings stat.',
				array(
					'exception_class'   => $error::class,
					'exception_message' => $error->getMessage(),
				)
			);
		}
	}
}
