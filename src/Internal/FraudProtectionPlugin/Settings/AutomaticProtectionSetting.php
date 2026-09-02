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

	public const STATUS_DEFAULT_DISABLED = 'default_disabled';

	public const STATUS_ENABLED = 'enabled';

	public const STATUS_DISABLED = 'disabled';

	/**
	 * Get the stored state.
	 *
	 * @return string One of the STATUS_* constants.
	 */
	public function get_stored_status(): string {
		$value = get_option( self::OPTION_NAME, null );

		if ( 'yes' === $value ) {
			return self::STATUS_ENABLED;
		}

		if ( 'no' === $value ) {
			return self::STATUS_DISABLED;
		}

		return self::STATUS_DEFAULT_DISABLED;
	}

	/**
	 * Check whether automatic protection is enabled.
	 */
	public function is_enabled(): bool {
		return self::STATUS_ENABLED === $this->get_stored_status();
	}

	/**
	 * Store an explicit state.
	 *
	 * @param bool $enabled Whether automatic protection is enabled.
	 * @return bool Whether the requested value is stored.
	 */
	public function set_enabled( bool $enabled ): bool {
		$stored = $enabled ? 'yes' : 'no';
		update_option( self::OPTION_NAME, $stored );

		return get_option( self::OPTION_NAME, null ) === $stored;
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
