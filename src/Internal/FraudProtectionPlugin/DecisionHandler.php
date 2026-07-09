<?php
/**
 * DecisionHandler class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionClearanceManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;

defined( 'ABSPATH' ) || exit;

/**
 * Handles fraud protection decision application.
 *
 * This class is responsible for:
 * - Applying extension override filters for whitelisting
 * - Coordinating with SessionClearanceManager to apply decisions
 * - Recording actionable verdicts into the sessions log via SessionEventRecorder
 */
class DecisionHandler {

	/**
	 * Session clearance manager instance.
	 *
	 * @var SessionClearanceManager
	 */
	private SessionClearanceManager $session_manager;

	/**
	 * Session event recorder instance.
	 *
	 * @var SessionEventRecorder
	 */
	private SessionEventRecorder $event_recorder;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionClearanceManager $session_manager The session clearance manager instance.
	 * @param SessionEventRecorder    $event_recorder  The session event recorder instance.
	 */
	final public function init( SessionClearanceManager $session_manager, SessionEventRecorder $event_recorder ): void {
		$this->session_manager = $session_manager;
		$this->event_recorder  = $event_recorder;
	}

	/**
	 * Apply a fraud protection decision.
	 *
	 * This method processes a decision from the API, applies any override filters,
	 * validates the result, and updates the session status accordingly.
	 *
	 * The input decision is any valid FraudDecision parsed by ApiClient;
	 * non-actionable decisions (Challenge) are coerced to Allow here.
	 *
	 * The decision flow:
	 * 1. Coerce non-actionable decisions to Allow
	 * 2. Apply the `woocommerce_fraud_protection_decision` filter for overrides
	 * 3. Validate the filtered decision (third-party filters may return invalid values)
	 * 4. Update session status via SessionClearanceManager
	 * 5. Record the raw verdict into the sessions log (fail-open)
	 *
	 * @param FraudDecision        $decision     The decision from the API.
	 * @param array<string, mixed> $session_data The session data that was sent to the API.
	 * @return FraudDecision The final applied decision after any filter overrides.
	 */
	public function apply_decision( FraudDecision $decision, array $session_data ): FraudDecision {
		$raw_verdict = $decision;
		$session     = is_array( $session_data['session'] ?? null ) ? $session_data['session'] : array();
		$log_context = array(
			'identity_id'  => $session['wc_identity_id'] ?? 'unknown',
			'event_source' => $session_data['source'] ?? 'unknown',
		);

		// Challenge is not actionable yet (the challenge flow is not implemented). Fail open on
		// any non-actionable decision so it can never reach the session update or be returned to
		// the caller. The raw verdict captured above is still recorded below.
		if ( ! in_array( $decision, FraudDecision::ACTIONABLE, true ) ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Non-actionable decision "%s" received. Defaulting to "allow".', $decision->value ),
				array_merge( $log_context, array( 'decision_received' => $decision->value ) ),
				true
			);
			$decision = FraudDecision::Allow;
		}

		$original_decision = $decision;

		/**
		 * Filters the fraud protection decision before it is applied.
		 *
		 * This filter allows extensions to override fraud protection decisions
		 * to implement custom whitelisting logic. Common use cases:
		 * - Whitelist specific users (e.g., admins, trusted customers)
		 * - Whitelist specific conditions (e.g., certain IP ranges, logged-in users)
		 * - Integrate with external fraud detection services
		 *
		 * The decision is passed and expected back as a {@see FraudDecision}
		 * (`FraudDecision::Allow` or `FraudDecision::Block`). Any other value is
		 * rejected and the original decision is used.
		 *
		 * @since 0.1.0
		 *
		 * @param FraudDecision        $decision     The decision from the API (Allow or Block).
		 * @param array<string, mixed> $session_data The session data that was analyzed.
		 */
		/**
		 * A third-party filter callback may return any type; it is validated below.
		 *
		 * @var mixed $filtered
		 */
		$filtered = apply_filters( 'woocommerce_fraud_protection_decision', $decision, $session_data );

		// Validate filtered decision (third-party filters may return any value).
		if ( $filtered instanceof FraudDecision && in_array( $filtered, FraudDecision::ACTIONABLE, true ) ) {
			$decision = $filtered;
		} else {
			if ( $filtered instanceof FraudDecision ) {
				$filtered_value = $filtered->value;
			} elseif ( is_scalar( $filtered ) ) {
				$filtered_value = (string) $filtered;
			} else {
				$filtered_value = gettype( $filtered );
			}

			FraudProtectionController::log(
				'warning',
				sprintf( 'Filter `woocommerce_fraud_protection_decision` returned invalid decision "%s". Using original decision "%s".', $filtered_value, $original_decision->value ),
				array_merge(
					$log_context,
					array(
						'filter'            => 'woocommerce_fraud_protection_decision',
						'decision_received' => $filtered_value,
						'argument_type'     => gettype( $filtered ),
						'original_decision' => $original_decision->value,
						'filtered_decision' => $filtered_value,
					)
				),
				true
			);
			$decision = $original_decision;
		}

		// Log if decision was overridden.
		if ( $decision !== $original_decision ) {
			FraudProtectionController::log(
				'info',
				sprintf( 'Decision overridden by filter `woocommerce_fraud_protection_decision`: "%s" -> "%s"', $original_decision->value, $decision->value ),
				array_merge(
					$log_context,
					array(
						'original_decision' => $original_decision->value,
						'final_decision'    => $decision->value,
					)
				)
			);
		}

		/**
		 * Filters whether learning mode is active.
		 *
		 * When learning mode is enabled (default), block decisions are suppressed
		 * and treated as "allow", regardless of whether the decision came from the
		 * API or was set by the `woocommerce_fraud_protection_decision` filter.
		 * This allows the plugin to observe fraud signals without affecting real
		 * transactions.
		 *
		 * To enable enforcement (blocking), return false:
		 * `add_filter( 'woocommerce_fraud_protection_learning_mode', '__return_false' );`
		 *
		 * @since 0.1.0
		 *
		 * @param bool $learning_mode Whether learning mode is active. Default true.
		 */
		$learning_mode = (bool) apply_filters( 'woocommerce_fraud_protection_learning_mode', true );

		if ( $learning_mode && FraudDecision::Block === $decision ) {
			FraudProtectionController::log(
				'info',
				sprintf( 'Learning mode: suppressing "%s" decision, allowing session.', $decision->value ),
				$log_context
			);
			$decision = FraudDecision::Allow;
		}

		// Apply the decision to the session.
		$this->update_session_status( $decision );

		// Record the raw verdict (not the enforcement outcome): block/challenge events are
		// recorded even when enforcement was suppressed. The recorder is fail-open.
		$this->event_recorder->record_verdict(
			$raw_verdict,
			FraudDecision::Block === $decision ? SessionFinalStatus::Blocked : SessionFinalStatus::NotEnforced,
			SessionTrigger::Blackbox,
			$session_data
		);

		return $decision;
	}

	/**
	 * Update the session status based on the decision.
	 *
	 * Important: Once a session is blocked, it stays blocked until explicitly reset.
	 * This prevents race conditions where emptying the cart (done during block_session)
	 * causes subsequent fraud checks to return "allow" (due to lower cart value),
	 * which would incorrectly unblock the session.
	 *
	 * @param FraudDecision $decision The decision to apply.
	 * @return void
	 */
	private function update_session_status( FraudDecision $decision ): void {
		// Don't overwrite a blocked session with an allow decision.
		// Once blocked, a session should stay blocked until explicitly reset.
		if ( FraudDecision::Allow === $decision && $this->session_manager->is_session_blocked() ) {
			FraudProtectionController::log(
				'info',
				'Preserving blocked session status. Allow decision not applied to already-blocked session.'
			);
			return;
		}

		switch ( $decision ) {
			case FraudDecision::Allow:
				$this->session_manager->allow_session();
				break;

			case FraudDecision::Block:
				$this->session_manager->block_session();
				break;
		}
	}
}
