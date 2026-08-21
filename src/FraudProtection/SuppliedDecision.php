<?php
/**
 * SuppliedDecision class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

defined( 'ABSPATH' ) || exit;

/**
 * Carries an earlier fraud decision and its optional order session ID.
 */
final class SuppliedDecision {

	/**
	 * The supplied decision.
	 *
	 * @var ?FraudDecision
	 */
	private ?FraudDecision $decision = null;

	/**
	 * The response-backed session ID that can be stored on the order.
	 *
	 * @var ?string
	 */
	private ?string $session_id_for_order = null;

	/**
	 * Supply an earlier actionable decision and its optional order session ID.
	 *
	 * @param mixed $decision             The decision to supply.
	 * @param mixed $session_id_for_order The response-backed session ID for the order.
	 *
	 * @since 0.1.9
	 */
	public function supply( mixed $decision, mixed $session_id_for_order = null ): void {
		if ( ! ( $decision instanceof FraudDecision ) || ! in_array( $decision, FraudDecision::ACTIONABLE, true ) ) {
			return;
		}

		$this->decision             = $decision;
		$this->session_id_for_order = is_string( $session_id_for_order ) && '' !== $session_id_for_order ? $session_id_for_order : null;
	}

	/**
	 * Get the supplied actionable decision.
	 *
	 * @return ?FraudDecision The supplied decision, or null.
	 *
	 * @since 0.1.9
	 */
	public function get_decision(): ?FraudDecision {
		return $this->decision;
	}

	/**
	 * Get the response-backed session ID that can be stored on the order.
	 *
	 * @return ?string The order session ID, or null.
	 *
	 * @since 0.1.9
	 */
	public function get_session_id_for_order(): ?string {
		return $this->session_id_for_order;
	}
}
