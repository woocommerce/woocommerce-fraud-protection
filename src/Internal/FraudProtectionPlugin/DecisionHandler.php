<?php
/**
 * DecisionHandler class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;

defined( 'ABSPATH' ) || exit;

/**
 * Handles fraud protection decision application.
 *
 * This class is responsible for:
 * - Validating decisions and applying extension override filters for whitelisting
 * - Applying learning mode
 * - Recording actionable verdicts into the sessions log via SessionEventRecorder
 *
 * Stateless by design: the returned decision applies only to the current
 * checkout/payment attempt, and enforcement is up to the caller (e.g. throwing
 * a RouteException or adding a checkout error). No blocking state is persisted,
 * so every new attempt is verified from scratch — a block does not follow the
 * shopper, and the `woocommerce_fraud_protection_decision` filter can override
 * the verdict on any subsequent attempt. The sessions log is a write-only
 * record; it never feeds back into decisions.
 */
class DecisionHandler {

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
	 * @param SessionEventRecorder $event_recorder The session event recorder instance.
	 */
	final public function init( SessionEventRecorder $event_recorder ): void {
		$this->event_recorder = $event_recorder;
	}

	/**
	 * Apply a fraud protection decision.
	 *
	 * This method processes a decision from the API, applies any override
	 * filters, validates the result, and returns the final decision for the
	 * caller to enforce on the current attempt.
	 *
	 * The input decision is any valid FraudDecision parsed by ApiClient;
	 * non-actionable decisions (Challenge) are coerced to Allow here.
	 *
	 * The decision flow:
	 * 1. Coerce non-actionable decisions to Allow
	 * 2. Apply the `woocommerce_fraud_protection_decision` filter for overrides
	 * 3. Validate the filtered decision (third-party filters may return invalid values)
	 * 4. Apply learning mode (suppresses Block decisions while active)
	 * 5. Record the received decision into the sessions log (fail-open)
	 *
	 * @param FraudDecision        $decision     The decision from the API.
	 * @param array<string, mixed> $session_data The session data that was sent to the API, extended with the verify result.
	 * @param SessionTrigger       $trigger      The mechanism that produced the decision, recorded into the sessions log.
	 * @return FraudDecision The final applied decision after any filter overrides.
	 */
	public function apply_decision( FraudDecision $decision, array $session_data, SessionTrigger $trigger = SessionTrigger::Blackbox ): FraudDecision {
		$received_decision = $decision;
		$session           = is_array( $session_data['session'] ?? null ) ? $session_data['session'] : array();
		$log_context       = array(
			'identity_id'  => $session['wc_identity_id'] ?? 'unknown',
			'event_source' => $session_data['source'] ?? 'unknown',
		);

		// The parameter type permits FraudDecision::Challenge, which is not actionable and not yet
		// supported. Fail open on any non-actionable decision so only actionable decisions are
		// returned to the caller. The received decision captured above is still recorded below.
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

		// The filter payload is deliberate, not the raw internal array: the recorder
		// bundle is stripped and replaced with the intentional `verify_result` subset
		// (no session ID) documented on the filter below.
		$verify_result       = is_array( $session_data[ SessionEventRecorder::VERIFY_RESULT_KEY ] ?? null ) ? $session_data[ SessionEventRecorder::VERIFY_RESULT_KEY ] : array();
		$filter_session_data = $session_data;
		unset( $filter_session_data[ SessionEventRecorder::VERIFY_RESULT_KEY ] );
		$filter_session_data['verify_result'] = array(
			'risk_score'     => is_numeric( $verify_result['risk_score'] ?? null ) ? (float) $verify_result['risk_score'] : null,
			'payment_method' => (string) ( $verify_result['payment_method'] ?? '' ),
		);

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
		 * The session data includes a `verify_result` key with details of the
		 * verify response: `risk_score` (float|null) and `payment_method`
		 * (string). The risk score is informational only: it may be
		 * recalibrated server-side at any time, so do not build threshold
		 * rules on top of it.
		 *
		 * @since 0.1.0
		 *
		 * @param FraudDecision        $decision     The decision from the API (Allow or Block).
		 * @param array<string, mixed> $session_data The session data that was analyzed, including the `verify_result` details.
		 */
		/**
		 * A third-party filter callback may return any type; it is validated below.
		 *
		 * @var mixed $filtered
		 */
		$filtered = apply_filters( 'woocommerce_fraud_protection_decision', $decision, $filter_session_data );

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

		// Record the received decision (not the enforcement outcome), so suppressed
		// blocks and challenges are recorded faithfully. The recorder is fail-open.
		$this->event_recorder->record_decision( $received_decision, $decision, $trigger, $session_data );

		return $decision;
	}
}
