<?php
/**
 * VerifyResult class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable result of a Blackbox `verify` call: decision, session ID, and risk score.
 */
class VerifyResult {

	/**
	 * Private constructor — use the create() factory.
	 *
	 * @param string $decision   The fraud decision (ApiClient::DECISION_ALLOW or ApiClient::DECISION_BLOCK).
	 * @param string $session_id The Blackbox session ID, or empty string if none.
	 * @param ?float $risk_score The Blackbox risk score, or null if none.
	 */
	private function __construct(
		private readonly string $decision,
		private readonly string $session_id,
		private readonly ?float $risk_score = null
	) {}

	/**
	 * Build a VerifyResult, sanitizing the session ID.
	 *
	 * The decision is expected to be pre-validated by ApiClient. The session ID
	 * originates from the API response, so it is sanitized here.
	 *
	 * @param string $decision   The fraud decision.
	 * @param string $session_id The Blackbox session ID from the response (raw).
	 * @param ?float $risk_score The Blackbox risk score from the response, or null if absent.
	 * @return self
	 */
	public static function create( string $decision, string $session_id, ?float $risk_score = null ): self {
		return new self( $decision, \sanitize_text_field( $session_id ), $risk_score );
	}

	/**
	 * Get the fraud decision.
	 *
	 * @return string
	 */
	public function get_decision(): string {
		return $this->decision;
	}

	/**
	 * Get the Blackbox session ID, or empty string if none was returned.
	 *
	 * @return string
	 */
	public function get_session_id(): string {
		return $this->session_id;
	}

	/**
	 * Get the Blackbox risk score, or null if none was returned.
	 *
	 * @return ?float
	 */
	public function get_risk_score(): ?float {
		return $this->risk_score;
	}
}
