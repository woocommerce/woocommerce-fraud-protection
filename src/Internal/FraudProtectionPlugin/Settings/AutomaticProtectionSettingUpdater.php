<?php
/**
 * AutomaticProtectionSettingUpdater class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Updates automatic protection and records confirmed changes.
 */
class AutomaticProtectionSettingUpdater {

	/**
	 * Automatic-protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private AutomaticProtectionSetting $setting;

	/**
	 * Settings telemetry.
	 *
	 * @var SettingsTelemetry
	 */
	private SettingsTelemetry $telemetry;

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
	 * @param AutomaticProtectionSetting $setting   Automatic-protection setting.
	 * @param SettingsTelemetry          $telemetry Settings telemetry.
	 * @param FraudProtectionLogger      $logger    Logger instance.
	 */
	final public function init( AutomaticProtectionSetting $setting, SettingsTelemetry $telemetry, FraudProtectionLogger $logger ): void {
		$this->setting   = $setting;
		$this->telemetry = $telemetry;
		$this->logger    = $logger;
	}

	/**
	 * Store an explicit automatic-protection state.
	 *
	 * @param bool                  $enabled Whether automatic protection is enabled.
	 * @param SettingsChangeChannel $channel Change channel.
	 * @return bool Whether the requested value is stored.
	 */
	public function set_enabled( bool $enabled, SettingsChangeChannel $channel ): bool {
		$before = $this->setting->get_status();
		if ( ! $this->setting->set_enabled( $enabled ) ) {
			return false;
		}

		$after = $this->setting->get_status();
		if ( $before !== $after ) {
			$change = $enabled ? AutomaticProtectionChange::Enabled : AutomaticProtectionChange::Disabled;
			$this->telemetry->record_automatic_protection_change( $change, $channel );
		}

		return true;
	}

	/**
	 * Reset automatic protection to its code default.
	 *
	 * @param SettingsChangeChannel $channel Change channel.
	 * @return bool Whether the stored value is absent.
	 */
	public function reset( SettingsChangeChannel $channel ): bool {
		if ( SettingsChangeChannel::Cli !== $channel ) {
			$this->logger->log( 'warning', 'Automatic protection reset is only available through WP-CLI.' );
			return false;
		}

		$before = $this->setting->get_status();
		if ( ! $this->setting->reset() ) {
			return false;
		}

		$after = $this->setting->get_status();
		if ( $before !== $after ) {
			$this->telemetry->record_automatic_protection_change( AutomaticProtectionChange::Reset, $channel );
		}

		return true;
	}
}
