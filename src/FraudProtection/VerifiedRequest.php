<?php
/**
 * VerifiedRequest class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

defined( 'ABSPATH' ) || exit;

/**
 * The result of {@see GatewayRequestVerifier::verify_request()}.
 *
 * Holds the decision for the request and keeps it, through {@see reuse_decision_for()}, for the
 * WooCommerce submission that completes the same attempt.
 *
 * @since 0.3.1
 */
final class VerifiedRequest {

	/**
	 * Create the result of a verification.
	 *
	 * @internal Obtain instances from {@see GatewayRequestVerifier::verify_request()}.
	 *
	 * @param FraudDecision $decision        The decision for the request.
	 * @param \Closure      $store_for_reuse Stores the decision for reuse with a reference and returns whether it was stored.
	 */
	public function __construct(
		private readonly FraudDecision $decision,
		private readonly \Closure $store_for_reuse
	) {}

	/**
	 * Get the decision for the request.
	 *
	 * @return FraudDecision `FraudDecision::Allow` or `FraudDecision::Block`.
	 *
	 * @since 0.3.1
	 */
	public function get_decision(): FraudDecision {
		return $this->decision;
	}

	/**
	 * Whether the request must be stopped.
	 *
	 * @return bool True for a Block decision.
	 *
	 * @since 0.3.1
	 */
	public function is_blocked(): bool {
		return FraudDecision::Block === $this->decision;
	}

	/**
	 * Reuse the decision for the WooCommerce submission that completes this attempt.
	 *
	 * Pass a reference that only your server created while handling this request, such as
	 * the ID of the order or setup intent at your payment provider. A later checkout, Pay for
	 * order or payment-method submission with the same gateway that carries this reference, as
	 * read by the callback given to {@see GatewayRequestVerifier::register_reference_reader()},
	 * reuses this decision instead of a new verification, once.
	 *
	 * Each gateway keeps one reusable decision per customer session: a new one replaces the previous one.
	 * Nothing is kept when the verification produced no session ID to attach the decision to,
	 * for example when it failed open; the submission is then verified normally.
	 *
	 * @param string $reference The reference your server created for this attempt.
	 * @return bool Whether the decision was kept.
	 *
	 * @since 0.3.1
	 */
	public function reuse_decision_for( string $reference ): bool {
		return ( $this->store_for_reuse )( $reference );
	}
}
