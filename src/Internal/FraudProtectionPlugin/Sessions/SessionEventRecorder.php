<?php
/**
 * SessionEventRecorder class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResult;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\VerifyResultOrigin;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Records fraud decisions into the sessions log.
 *
 * `DecisionHandler::apply_decision()` provides both the received decision and
 * the applied outcome. Every parsed decision is recorded, including allowed
 * decisions and decisions that automatic protection does not enforce.
 * Verifies that failed to produce a real verdict (transport errors,
 * unparseable responses, unknown decision values) fail open to a synthetic
 * allow and are recorded under the {@see SessionTrigger::VerifyError}
 * trigger, keeping unverified sessions distinguishable from genuine allows.
 * Paths where verification is skipped entirely (the skip filter) record
 * nothing.
 *
 * Recording also requires the sessions schema to be installed: while it is
 * pending, failing, or given up ({@see SchemaManager::is_schema_installed()}),
 * events are skipped silently instead of failing an insert (and logging a
 * warning) on every verify.
 *
 * Fail-open: recording failures are logged and never affect checkout.
 */
class SessionEventRecorder {

	/**
	 * Session event store instance.
	 *
	 * @var SessionEventStore
	 */
	private SessionEventStore $event_store;

	/**
	 * Merchant lists feature gate instance.
	 *
	 * @var MerchantListsFeature
	 */
	private MerchantListsFeature $merchant_lists_feature;

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Visitor IP resolver instance.
	 *
	 * @var VisitorIpResolver
	 */
	private VisitorIpResolver $visitor_ip_resolver;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionEventStore    $event_store            The session event store instance.
	 * @param MerchantListsFeature $merchant_lists_feature The merchant lists feature gate instance.
	 * @param SchemaManager        $schema_manager         The schema manager instance.
	 * @param VisitorIpResolver    $visitor_ip_resolver    Visitor IP resolver instance.
	 */
	final public function init( SessionEventStore $event_store, MerchantListsFeature $merchant_lists_feature, SchemaManager $schema_manager, VisitorIpResolver $visitor_ip_resolver ): void {
		$this->event_store            = $event_store;
		$this->merchant_lists_feature = $merchant_lists_feature;
		$this->schema_manager         = $schema_manager;
		$this->visitor_ip_resolver    = $visitor_ip_resolver;
	}

	/**
	 * Record a decision if the feature is enabled.
	 *
	 * The recorded decision is the one carried by the verify result (as
	 * received from the API, before any coercion or override) while the
	 * recorded final status is the outcome actually applied to the session:
	 * blocked when the applied decision is Block, allowed otherwise. A
	 * suppressed or overridden block stays visible as the received decision
	 * (`decision = block`) paired with `final_status = allowed`. Fail-open
	 * results are recorded under the {@see SessionTrigger::VerifyError}
	 * trigger, so a synthetic allow stays distinguishable from a genuine
	 * Blackbox allow.
	 *
	 * When a merchant rule decided the outcome, the row records the rule id
	 * and a {@see SessionTrigger::AllowRule} or
	 * {@see SessionTrigger::BlockRule} trigger, while the `decision`
	 * column keeps what Blackbox said - the sessions log stores the verdict
	 * alongside the enforced outcome.
	 *
	 * @param VerifyResult  $result           The verify result, carrying the received decision, session ID and risk score.
	 * @param FraudDecision $applied_decision The decision actually applied to the session, after overrides.
	 * @param array         $session_data     The session data payload that was sent to the API.
	 * @param ?Rule         $matched_rule     The merchant rule that decided the outcome, if any.
	 * @return void
	 */
	public function record_decision( VerifyResult $result, FraudDecision $applied_decision, array $session_data, ?Rule $matched_rule = null ): void {
		try {
			if ( ! $this->merchant_lists_feature->is_enabled() ) {
				return;
			}

			// No schema, no recording: inserting into a missing table would fail
			// and log a warning on every single verify.
			if ( ! $this->schema_manager->is_schema_installed() ) {
				return;
			}

			$final_status = FraudDecision::Block === $applied_decision ? SessionFinalStatus::Blocked : SessionFinalStatus::Allowed;
			$event        = $this->build_event( $result, $final_status, $session_data, $matched_rule );

			if ( ! $this->event_store->record_event( $event ) ) {
				global $wpdb;
				FraudProtectionController::log(
					'warning',
					'Failed to record session event.',
					array(
						'event_source' => 'session_event_recorder',
						'session_id'   => $event['session_id'],
						'db_error'     => $wpdb->last_error,
					),
					true
				);
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'warning',
				'Session event recording failed',
				array(
					'event_source'      => 'session_event_recorder',
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
		}
	}

	/**
	 * Map the verify result and session data payload to a sessions table event row.
	 *
	 * @param VerifyResult       $result       The verify result, carrying the received decision, session ID and risk score.
	 * @param SessionFinalStatus $final_status The effective outcome after overrides.
	 * @param array              $session_data The session data payload.
	 * @param ?Rule              $matched_rule The merchant rule that decided the outcome, if any.
	 * @return array<string, mixed> The event row for {@see SessionEventStore::record_event()}.
	 */
	private function build_event( VerifyResult $result, SessionFinalStatus $final_status, array $session_data, ?Rule $matched_rule ): array {
		$customer = is_array( $session_data['customer'] ?? null ) ? $session_data['customer'] : array();
		$billing  = is_array( $customer['billing_address'] ?? null ) ? $customer['billing_address'] : array();
		$session  = is_array( $session_data['session'] ?? null ) ? $session_data['session'] : array();
		$order    = is_array( $session_data['order'] ?? null ) ? $session_data['order'] : array();
		$payment  = is_array( $session_data['payment'] ?? null ) ? $session_data['payment'] : array();

		$email = (string) ( $customer['billing_email'] ?? '' );
		$email = '' === $email ? (string) ( $session['email'] ?? '' ) : $email;

		$billing_name = trim( ( (string) ( $billing['first_name'] ?? '' ) ) . ' ' . ( (string) ( $billing['last_name'] ?? '' ) ) );

		$ip         = $this->visitor_ip_resolver->get_ip_address();
		$ip_country = $this->visitor_ip_resolver->get_ip_country( $ip );

		// A fail-open verify produced no real verdict: record it under the
		// verify_error trigger so the synthetic allow stays distinguishable
		// from a genuine Blackbox allow in the sessions log. A matched rule
		// takes precedence: it decided the outcome whatever the verify did.
		if ( ! is_null( $matched_rule ) ) {
			$trigger = FraudDecision::Allow === $matched_rule->action ? SessionTrigger::AllowRule : SessionTrigger::BlockRule;
		} elseif ( VerifyResultOrigin::RequestRejected === $result->origin ) {
			$trigger = SessionTrigger::RequestRejected;
		} else {
			$trigger = VerifyResultOrigin::FailOpen === $result->origin ? SessionTrigger::VerifyError : SessionTrigger::Blackbox;
		}

		// Caps use mb_substr: the column limits are in characters, and a byte-based
		// substr could split a multibyte character and produce invalid UTF-8.
		return array(
			'session_id'       => mb_substr( $result->session_id, 0, 64 ),
			'source'           => mb_substr( (string) ( $session_data['source'] ?? '' ), 0, 32 ),
			'decision'         => $result->decision->value,
			'final_status'     => $final_status->value,
			'trigger_type'     => $trigger->value,
			'risk_score'       => $result->risk_score,
			'email'            => mb_substr( strtolower( trim( $email ) ), 0, 254 ),
			'ip'               => mb_substr( (string) $ip, 0, 45 ),
			'ip_country'       => mb_substr( $ip_country, 0, 2 ),
			'billing_country'  => mb_substr( (string) ( $billing['country'] ?? '' ), 0, 2 ),
			'billing_state'    => mb_substr( (string) ( $billing['state'] ?? '' ), 0, 100 ),
			'billing_city'     => mb_substr( (string) ( $billing['city'] ?? '' ), 0, 100 ),
			'billing_postcode' => mb_substr( (string) ( $billing['postcode'] ?? '' ), 0, 20 ),
			'billing_name'     => mb_substr( $billing_name, 0, 255 ),
			'order_id'         => (int) ( $order['order_id'] ?? 0 ),
			'payment_method'   => mb_substr( (string) ( $payment['gateway'] ?? '' ), 0, 64 ),
			'matched_rule_id'  => $matched_rule?->id,
		);
	}
}
