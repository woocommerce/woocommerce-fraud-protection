<?php
/**
 * SuppliedDecision class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

defined( 'ABSPATH' ) || exit;

/**
 * An earlier fraud decision, its optional order session ID, and whether merchant rules still apply to it.
 */
final class SuppliedDecision {

	/**
	 * Create a supplied decision.
	 *
	 * A merchant rule that matches the current request replaces the supplied decision, because
	 * the earlier verification may have run before the attempt's final details existed. Pass
	 * false for `$apply_merchant_rules` only when no shopper is involved, such as a payment that
	 * authenticated staff record at a point-of-sale till.
	 *
	 * @param FraudDecision $decision             The decision to supply.
	 * @param ?string       $session_id_for_order The response-backed session ID for the order.
	 * @param bool          $apply_merchant_rules Whether a matching merchant rule replaces the decision. Since 0.4.0.
	 *
	 * @since 0.1.9
	 */
	public function __construct(
		public readonly FraudDecision $decision,
		public readonly ?string $session_id_for_order = null,
		public readonly bool $apply_merchant_rules = true
	) {}
}
