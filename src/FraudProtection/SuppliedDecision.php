<?php
/**
 * SuppliedDecision class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

defined( 'ABSPATH' ) || exit;

/**
 * An earlier fraud decision and its optional order session ID.
 */
final class SuppliedDecision {

	/**
	 * Create a supplied decision.
	 *
	 * @param FraudDecision $decision             The decision to supply.
	 * @param ?string       $session_id_for_order The response-backed session ID for the order.
	 *
	 * @since 0.1.9
	 */
	public function __construct(
		public readonly FraudDecision $decision,
		public readonly ?string $session_id_for_order = null
	) {}
}
