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
	private const OPT_OUT_INFO_OPTION_NAME = 'woocommerce_fraud_protection_automatic_protection_opted_out_info';

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
		return null !== get_option( self::OPT_OUT_INFO_OPTION_NAME, null );
	}

	/**
	 * Get the stored automatic-enrollment opt-out date.
	 *
	 * @return string|null The UTC date, or null when unavailable.
	 */
	public function get_opted_out_at(): ?string {
		$date = $this->get_opt_out_record()['date'] ?? null;

		return is_string( $date ) && '' !== $date ? $date : null;
	}

	/**
	 * Get the id of the user who opted out, when known.
	 *
	 * @return int|null The user id, or null when unknown.
	 */
	public function get_opted_out_by(): ?int {
		$user_id = $this->get_opt_out_record()['user_id'] ?? null;

		return is_int( $user_id ) && $user_id > 0 ? $user_id : null;
	}

	/**
	 * Decode the stored opt-out record.
	 *
	 * The opt-out is stored as a JSON object with the opt-out date and the id of
	 * the user who made it.
	 *
	 * @return array{date?: ?string, user_id?: ?int}
	 */
	private function get_opt_out_record(): array {
		$value   = get_option( self::OPT_OUT_INFO_OPTION_NAME, null );
		$decoded = is_string( $value ) ? json_decode( $value, true ) : null;
		if ( ! is_array( $decoded ) ) {
			return array();
		}

		return array(
			'date'    => isset( $decoded['date'] ) && is_string( $decoded['date'] ) ? $decoded['date'] : null,
			'user_id' => isset( $decoded['user_id'] ) ? (int) $decoded['user_id'] : null,
		);
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
	 * Store the first automatic-enrollment opt-out date.
	 *
	 * @return bool|null True when created, false when already stored, or null on failure.
	 */
	public function set_opted_out(): ?bool {
		// Store the opt-out date together with the user who made it.
		$record = wp_json_encode(
			array(
				'date'    => gmdate( 'Y-m-d H:i:s' ),
				'user_id' => get_current_user_id(),
			)
		);
		if ( ! is_string( $record ) ) {
			return null;
		}

		$created = add_option( self::OPT_OUT_INFO_OPTION_NAME, $record, '', false );
		if ( $created ) {
			return get_option( self::OPT_OUT_INFO_OPTION_NAME, null ) === $record ? true : null;
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
		delete_option( self::OPT_OUT_INFO_OPTION_NAME );

		return null === get_option( self::OPTION_NAME, null )
			&& null === get_option( self::OPT_OUT_INFO_OPTION_NAME, null );
	}
}
