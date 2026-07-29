<?php
/**
 * MerchantListsFeature class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Gate for the merchant-facing lists feature (session recording, the
 * merchant allow/block rules, and their admin surface).
 *
 * The gate is hardcoded rather than driven by an option or a filter: rollout
 * and rollback of this feature are controlled by deploying the plugin itself,
 * which keeps the moving parts on our side instead of depending on per-site
 * state. Flipping the feature off fleet-wide is a one-line change plus a
 * deploy.
 */
class MerchantListsFeature {

	/**
	 * Whether the merchant lists feature is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return true;
	}
}
