<?php
/**
 * VerifyResult class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of a Blackbox `verify` call: decision, session ID, and risk score.
 */
class VerifyResult {

	/**
	 * Private constructor — use the create() factory.
	 *
	 * @param FraudDecision $decision   The fraud decision.
	 * @param string        $session_id The Blackbox session ID, or empty string if none.
	 * @param ?float        $risk_score The Blackbox risk score, or null if none.
	 */
	private function __construct(
		public readonly FraudDecision $decision,
		public readonly string $session_id,
		public readonly ?float $risk_score = null
	) {}

	/**
	 * Build a VerifyResult, sanitizing the session ID.
	 *
	 * The decision is expected to be pre-validated by ApiClient. The session ID
	 * originates from the API response, so it is sanitized here.
	 *
	 * @param FraudDecision $decision   The fraud decision.
	 * @param string        $session_id The Blackbox session ID from the response (raw).
	 * @param ?float        $risk_score The Blackbox risk score from the response, or null if absent.
	 * @return self
	 */
	public static function create( FraudDecision $decision, string $session_id, ?float $risk_score = null ): self {
		return new self( $decision, \sanitize_text_field( $session_id ), $risk_score );
	}
}
