<?php
/**
 * MerchantListsFeature class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Gate for the merchant-facing lists feature (blocked-sessions recording,
 * positive/negative lists, and their admin surface).
 *
 * The feature is disabled by default so that fleet rollouts of the plugin in
 * learning mode remain completely unaffected (no new tables, no writes, no UI)
 * until enablement is explicitly decided per site.
 */
class MerchantListsFeature {

	/**
	 * Option that enables the merchant lists feature ('yes' to enable).
	 */
	public const OPTION_NAME = 'woocommerce_fraud_protection_merchant_lists_enabled';

	/**
	 * Whether the merchant lists feature is enabled for this site.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		$enabled = 'yes' === get_option( self::OPTION_NAME, 'no' );

		/**
		 * Filters whether the fraud protection merchant lists feature is enabled.
		 *
		 * The feature gates blocked-sessions recording, the positive/negative
		 * lists, and their admin surface as a single unit.
		 *
		 * @since 0.1.6
		 *
		 * @param bool $enabled Whether the feature is enabled. Defaults to the value of the option.
		 */
		return (bool) apply_filters( 'woocommerce_fraud_protection_merchant_lists_enabled', $enabled );
	}
}
