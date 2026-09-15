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

	private const OPTION_NAME              = 'woocommerce_fraud_protection_automatic_protection';
	private const OPT_OUT_DATE_OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection_opted_out_at';
	private const ENABLED_AT_OPTION_NAME   = 'woocommerce_fraud_protection_automatic_protection_enabled_at';

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
	 * Check whether automatic enrollment was declined.
	 */
	public function is_opted_out(): bool {
		return null !== get_option( self::OPT_OUT_DATE_OPTION_NAME, null );
	}

	/**
	 * Get the stored automatic-enrollment opt-out date.
	 *
	 * @return string|null The UTC date, or null when unavailable.
	 */
	public function get_opted_out_at(): ?string {
		$value = get_option( self::OPT_OUT_DATE_OPTION_NAME, null );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	/**
	 * Get the date automatic protection was last turned on.
	 *
	 * Returns null while protection is off, so a caller reading it alongside the
	 * enabled state never shows a stale date from an earlier on period.
	 *
	 * @return string|null The UTC datetime ('Y-m-d H:i:s'), or null when unavailable.
	 */
	public function get_enabled_at(): ?string {
		if ( ! $this->is_enabled() ) {
			return null;
		}

		$value = get_option( self::ENABLED_AT_OPTION_NAME, null );

		return is_string( $value ) && '' !== $value ? $value : null;
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
		$was_enabled  = $this->is_enabled();
		$option_value = $enabled ? 'yes' : 'no';
		update_option( self::OPTION_NAME, $option_value );

		if ( get_option( self::OPTION_NAME, null ) !== $option_value ) {
			return false;
		}

		// Record when protection turns on so the list can show the enable date,
		// and clear it when it turns off. Only the first transition sets the date,
		// so re-saving while already on keeps the original enable time.
		if ( $enabled && ! $was_enabled ) {
			update_option( self::ENABLED_AT_OPTION_NAME, gmdate( 'Y-m-d H:i:s' ) );
		} elseif ( ! $enabled ) {
			delete_option( self::ENABLED_AT_OPTION_NAME );
		}

		return true;
	}

	/**
	 * Store the first automatic-enrollment opt-out date.
	 *
	 * @return bool|null True when created, false when already stored, or null on failure.
	 */
	public function set_opted_out(): ?bool {
		$opted_out_at = gmdate( 'Y-m-d H:i:s' );
		$created      = add_option( self::OPT_OUT_DATE_OPTION_NAME, $opted_out_at, '', false );
		if ( $created ) {
			return get_option( self::OPT_OUT_DATE_OPTION_NAME, null ) === $opted_out_at ? true : null;
		}

		return $this->is_opted_out() ? false : null;
	}

	/**
	 * Delete the stored state and opt-out date.
	 *
	 * @return bool Whether the state is absent.
	 */
	public function reset(): bool {
		delete_option( self::OPTION_NAME );
		delete_option( self::OPT_OUT_DATE_OPTION_NAME );
		delete_option( self::ENABLED_AT_OPTION_NAME );

		return null === get_option( self::OPTION_NAME, null )
			&& null === get_option( self::OPT_OUT_DATE_OPTION_NAME, null )
			&& null === get_option( self::ENABLED_AT_OPTION_NAME, null );
	}
}
