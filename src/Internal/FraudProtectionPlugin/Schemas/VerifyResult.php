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
 *
 * The session ID is the ID returned by Blackbox. An empty value means the
 * response did not supply an ID that can become association state.
 */
class VerifyResult {

	/**
	 * Private constructor — use the create() or fail_open() factories.
	 *
	 * @param FraudDecision $decision   The fraud decision.
	 * @param string        $session_id The effective Blackbox session ID, or empty string if none.
	 * @param ?float        $risk_score The Blackbox risk score, or null if none.
	 * @param bool          $fail_open  Whether the decision is a synthetic allow produced by failing open.
	 */
	private function __construct(
		public readonly FraudDecision $decision,
		public readonly string $session_id,
		public readonly ?float $risk_score = null,
		public readonly bool $fail_open = false
	) {}

	/**
	 * Build a VerifyResult from an accepted Blackbox response.
	 *
	 * ApiClient validates the decision and rejects reserved submitted-value
	 * markers before it creates this result.
	 *
	 * @param FraudDecision $decision   The fraud decision.
	 * @param string        $session_id The accepted Blackbox response session ID.
	 * @param ?float        $risk_score The Blackbox risk score from the response, or null if absent.
	 * @return self
	 */
	public static function create( FraudDecision $decision, string $session_id, ?float $risk_score = null ): self {
		return new self( $decision, $session_id, $risk_score );
	}

	/**
	 * Build the fail-open VerifyResult: a synthetic allow produced when
	 * verification could not obtain a real verdict (transport error,
	 * unparseable response, unknown decision value).
	 *
	 * @return self
	 */
	public static function fail_open(): self {
		return new self( FraudDecision::Allow, '', null, true );
	}
}
