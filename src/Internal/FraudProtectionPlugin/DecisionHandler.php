<?php
/**
 * DecisionHandler class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleEvaluator;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventRecorder;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\AutomaticProtectionSetting;

defined( 'ABSPATH' ) || exit;

/**
 * Handles fraud protection decision application.
 *
 * This class is responsible for:
 * - Evaluating the merchant ruleset against the session
 * - Validating decisions and applying extension override filters for whitelisting
 * - Applying the automatic-protection setting
 * - Recording actionable verdicts into the sessions log via SessionEventRecorder
 *
 * Stateless by design: the returned decision applies only to the current
 * checkout/payment attempt, and enforcement is up to the caller (e.g. throwing
 * a RouteException or adding a checkout error). No blocking state is persisted,
 * so every new attempt is verified from scratch — a block does not follow the
 * shopper. For automated blocks the
 * `woocommerce_fraud_protection_automated_decision` filter can override the
 * verdict on any subsequent attempt; for rule-decided blocks the recovery path
 * is the merchant editing or deleting the rule (the filter does not apply to
 * them). The sessions log is a write-only record; it never feeds back into
 * decisions (merchant rules, which do feed decisions, live in their own
 * table).
 */
class DecisionHandler {

	/**
	 * Session event recorder instance.
	 *
	 * @var SessionEventRecorder
	 */
	private SessionEventRecorder $event_recorder;

	/**
	 * Rule evaluator instance.
	 *
	 * @var RuleEvaluator
	 */
	private RuleEvaluator $rule_evaluator;

	/**
	 * Automatic-protection setting.
	 *
	 * @var AutomaticProtectionSetting
	 */
	private AutomaticProtectionSetting $automatic_protection;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionEventRecorder       $event_recorder       The session event recorder instance.
	 * @param RuleEvaluator              $rule_evaluator       The rule evaluator instance.
	 * @param AutomaticProtectionSetting $automatic_protection Automatic-protection setting.
	 */
	final public function init( SessionEventRecorder $event_recorder, RuleEvaluator $rule_evaluator, AutomaticProtectionSetting $automatic_protection ): void {
		$this->event_recorder       = $event_recorder;
		$this->rule_evaluator       = $rule_evaluator;
		$this->automatic_protection = $automatic_protection;
	}

	/**
	 * Apply a fraud protection decision.
	 *
	 * This method processes a verify result from the API, applies any override
	 * filters, validates the result, and returns the final decision for the
	 * caller to enforce on the current attempt.
	 *
	 * The result decision is any valid FraudDecision parsed by ApiClient;
	 * non-actionable decisions (Challenge) are coerced to Allow here.
	 *
	 * The decision flow:
	 * 1. Evaluate the merchant ruleset: a matching active rule decides the
	 *    outcome directly - it takes precedence over the Blackbox verdict and
	 *    bypasses the decision filter and the automatic-protection gate below.
	 *    In particular, a merchant block rule always enforces.
	 *    The `woocommerce_fraud_protection_rule_applied` action announces the
	 *    outcome to extensions.
	 * 2. Coerce non-actionable decisions to Allow
	 * 3. Apply the `woocommerce_fraud_protection_automated_decision` filter for overrides
	 * 4. Validate the filtered decision (third-party filters may return invalid values)
	 * 5. Apply the automatic-protection setting
	 * 6. Record the received decision into the sessions log (fail-open)
	 *
	 * @param VerifyResult         $result       The verify result from the API.
	 * @param array<string, mixed> $session_data The session data that was sent to the API.
	 * @return FraudDecision The final applied decision after any filter overrides.
	 */
	public function apply_decision( VerifyResult $result, array $session_data ): FraudDecision {
		$decision    = $result->decision;
		$session     = is_array( $session_data['session'] ?? null ) ? $session_data['session'] : array();
		$log_context = array(
			'identity_id'  => $session['wc_identity_id'] ?? 'unknown',
			'event_source' => $session_data['source'] ?? 'unknown',
		);

		// The payload the public hooks receive (the rule_applied action and
		// the automated_decision filter) extends the session data with the
		// intentional `verify_result` subset (no session ID) documented on
		// the filter below.
		$hook_session_data                  = $session_data;
		$hook_session_data['verify_result'] = array(
			'risk_score'     => $result->risk_score,
			'payment_method' => (string) ( $session_data['payment']['gateway'] ?? '' ),
		);

		$matched_rule = $this->rule_evaluator->evaluate_for_session( $session_data );
		if ( ! is_null( $matched_rule ) ) {
			FraudProtectionController::log(
				'info',
				sprintf( 'Merchant rule %d decided the session: "%s" (received decision was "%s").', $matched_rule->id, $matched_rule->action->value, $decision->value ),
				array_merge(
					$log_context,
					array(
						'matched_rule_id'   => $matched_rule->id,
						'final_decision'    => $matched_rule->action->value,
						'decision_received' => $decision->value,
					)
				)
			);
			$this->event_recorder->record_decision( $result, $matched_rule->action, $session_data, $matched_rule );

			try {
				/**
				 * Fires when a merchant rule has decided the session outcome.
				 *
				 * @since 0.1.6
				 *
				 * @param int                  $rule_id           The id of the rule that decided the session.
				 * @param FraudDecision        $applied_decision  The enforced decision (the rule's action).
				 * @param FraudDecision        $received_decision The automated decision received from the API, superseded by the rule.
				 * @param array<string, mixed> $session_data      The session data that was analyzed, including the same `verify_result` subset the `woocommerce_fraud_protection_automated_decision` filter receives (see its docblock; the risk score is informational only).
				 */
				do_action( 'woocommerce_fraud_protection_rule_applied', $matched_rule->id, $matched_rule->action, $decision, $hook_session_data );
			} catch ( \Throwable $e ) {
				FraudProtectionController::log(
					'warning',
					'A callback hooked to `woocommerce_fraud_protection_rule_applied` threw an exception.',
					array_merge(
						$log_context,
						array(
							'hook'              => 'woocommerce_fraud_protection_rule_applied',
							'exception'         => $e,
							'exception_class'   => $e::class,
							'exception_message' => $e->getMessage(),
						)
					),
					true
				);
			}

			return $matched_rule->action;
		}

		// The result may carry FraudDecision::Challenge, which is not actionable and not yet
		// supported. Fail open on any non-actionable decision so only actionable decisions are
		// returned to the caller. The decision as received ($result->decision) is still recorded below.
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

		try {
			/**
			 * Filters the automated fraud protection decision before it is applied.
			 *
			 * This filter allows extensions to override automated fraud protection
			 * decisions to implement custom whitelisting logic. It does not apply
			 * to sessions decided by a merchant rule: an explicit rule set by the
			 * merchant takes precedence over extension code (see the
			 * `woocommerce_fraud_protection_rule_applied` action). Common use cases:
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
			 * @since 0.1.6 Renamed from `woocommerce_fraud_protection_decision`.
			 * @since 0.1.8 A throwing callback uses the actionable decision that entered the filter.
			 *
			 * @param FraudDecision        $decision     The decision from the API (Allow or Block).
			 * @param array<string, mixed> $session_data The session data that was analyzed, including the `verify_result` details.
			 */
			$filtered = apply_filters( 'woocommerce_fraud_protection_automated_decision', $decision, $hook_session_data );
		} catch ( \Throwable $e ) {
			$filtered = $original_decision;

			FraudProtectionController::log(
				'warning',
				'Filter `woocommerce_fraud_protection_automated_decision` threw. Using the decision that entered the filter.',
				array_merge(
					$log_context,
					array(
						'filter'            => 'woocommerce_fraud_protection_automated_decision',
						'decision_received' => $original_decision->value,
						'exception_class'   => $e::class,
						'exception_message' => $e->getMessage(),
						'exception_file'    => $e->getFile(),
						'exception_line'    => $e->getLine(),
					)
				),
				true
			);
		}

		/**
		 * A third-party callback may return any type.
		 *
		 * @var mixed $filtered
		 */
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
				sprintf( 'Filter `woocommerce_fraud_protection_automated_decision` returned invalid decision "%s". Using original decision "%s".', $filtered_value, $original_decision->value ),
				array_merge(
					$log_context,
					array(
						'filter'            => 'woocommerce_fraud_protection_automated_decision',
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
				sprintf( 'Decision overridden by filter `woocommerce_fraud_protection_automated_decision`: "%s" -> "%s"', $original_decision->value, $decision->value ),
				array_merge(
					$log_context,
					array(
						'original_decision' => $original_decision->value,
						'final_decision'    => $decision->value,
					)
				)
			);
		}

		if ( FraudDecision::Block === $decision && ! $this->automatic_protection->is_enabled() ) {
			FraudProtectionController::log(
				'info',
				'Automatic protection is disabled: suppressing the "block" decision and allowing the session.',
				$log_context
			);
			$decision = FraudDecision::Allow;
		}

		// Record the received decision (not the enforcement outcome), so suppressed
		// blocks and challenges are recorded faithfully. The recorder is fail-open.
		$this->event_recorder->record_decision( $result, $decision, $session_data );

		return $decision;
	}
}
