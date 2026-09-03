<?php
/**
 * AutomaticProtectionSetting class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the automatic-protection state for one site.
 */
class AutomaticProtectionSetting {

	private const OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection';

	/**
	 * Get the setting status.
	 */
	public function get_status(): SettingStatus {
		$option_value = get_option( self::OPTION_NAME, null );

		if ( 'yes' === $option_value ) {
			return SettingStatus::Enabled;
		}

		if ( 'no' === $option_value ) {
			return SettingStatus::Disabled;
		}

		return SettingStatus::Enabled === $this->get_default() ? SettingStatus::DefaultEnabled : SettingStatus::DefaultDisabled;
	}

	/**
	 * Get the code default.
	 */
	public function get_default(): SettingStatus {
		return SettingStatus::Disabled;
	}

	/**
	 * Check whether automatic protection is enabled.
	 */
	public function is_enabled(): bool {
		$status = $this->get_status();

		return in_array( $status, array( SettingStatus::Enabled, SettingStatus::DefaultEnabled ), true );
	}

	/**
	 * Get the source of the current setting state.
	 */
	public function get_source(): AutomaticProtectionSource {
		return in_array( $this->get_status(), array( SettingStatus::Enabled, SettingStatus::Disabled ), true )
			? AutomaticProtectionSource::Manual
			: AutomaticProtectionSource::None;
	}

	/**
	 * Store an explicit state.
	 *
	 * @param bool $enabled Whether automatic protection is enabled.
	 * @return bool Whether the requested value is stored.
	 */
	public function set_enabled( bool $enabled ): bool {
		$option_value = $enabled ? 'yes' : 'no';
		update_option( self::OPTION_NAME, $option_value );

		return get_option( self::OPTION_NAME, null ) === $option_value;
	}

	/**
	 * Delete the stored state.
	 *
	 * @return bool Whether the state is absent.
	 */
	public function reset(): bool {
		delete_option( self::OPTION_NAME );

		return null === get_option( self::OPTION_NAME, null );
	}
}
