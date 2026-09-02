<?php
/**
 * MerchantExperienceFeature class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Controls merchant-facing Fraud Protection features.
 */
class MerchantExperienceFeature {

	private const OPTION_NAME = 'woocommerce_fraud_protection_merchant_experience';

	private const CODE_DEFAULT = false;

	public const STATUS_DEFAULT = 'default';

	public const STATUS_ENABLED = 'enabled';

	public const STATUS_DISABLED = 'disabled';

	/**
	 * Get the stored override state.
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

		return self::STATUS_DEFAULT;
	}

	/**
	 * Get the code default.
	 */
	public function get_code_default(): bool {
		return self::CODE_DEFAULT;
	}

	/**
	 * Check whether merchant-facing features are enabled.
	 */
	public function is_enabled(): bool {
		$status = $this->get_stored_status();

		if ( self::STATUS_ENABLED === $status ) {
			return true;
		}

		if ( self::STATUS_DISABLED === $status ) {
			return false;
		}

		$value = get_option( self::OPTION_NAME, null );

		return null === $value ? $this->get_code_default() : false;
	}

	/**
	 * Store an explicit per-site override.
	 *
	 * @param bool $enabled Whether merchant-facing features are enabled.
	 * @return bool Whether the requested value is stored.
	 */
	public function set_enabled( bool $enabled ): bool {
		$stored = $enabled ? 'yes' : 'no';
		update_option( self::OPTION_NAME, $stored );

		return get_option( self::OPTION_NAME, null ) === $stored;
	}

	/**
	 * Delete the per-site override.
	 *
	 * @return bool Whether the override is absent.
	 */
	public function reset(): bool {
		delete_option( self::OPTION_NAME );

		return null === get_option( self::OPTION_NAME, null );
	}
}
