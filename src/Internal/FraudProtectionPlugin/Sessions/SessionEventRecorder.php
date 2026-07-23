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
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;

defined( 'ABSPATH' ) || exit;

/**
 * Records fraud decisions into the sessions log.
 *
 * Invoked from `DecisionHandler::apply_decision()` with the decision as
 * received from the API — not the enforcement outcome — so that block and
 * challenge decisions are recorded faithfully even when enforcement is
 * suppressed (learning mode, filter overrides). Every parsed decision is
 * recorded, allowed sessions included, so merchants can act on any session
 * from its row (e.g. add the shopper to the positive or negative list).
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
	 * Payload key under which SessionVerifier bundles per-verify data for the
	 * recorder (session ID, risk score, payment method). Not sent to the API:
	 * the key is added after the verify call.
	 */
	public const VERIFY_RESULT_KEY = '_verify_result';

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
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionEventStore    $event_store            The session event store instance.
	 * @param MerchantListsFeature $merchant_lists_feature The merchant lists feature gate instance.
	 * @param SchemaManager        $schema_manager         The schema manager instance.
	 */
	final public function init( SessionEventStore $event_store, MerchantListsFeature $merchant_lists_feature, SchemaManager $schema_manager ): void {
		$this->event_store            = $event_store;
		$this->merchant_lists_feature = $merchant_lists_feature;
		$this->schema_manager         = $schema_manager;
	}

	/**
	 * Record a decision if the feature is enabled.
	 *
	 * The recorded final status is the outcome actually applied to the
	 * session: blocked when the applied decision is Block, allowed otherwise.
	 * A suppressed or overridden block stays visible as the received decision
	 * (`decision = block`) paired with `final_status = allowed`.
	 *
	 * @param FraudDecision  $received_decision The decision as received from the API, before any coercion or override.
	 * @param FraudDecision  $applied_decision  The decision actually applied to the session, after overrides.
	 * @param SessionTrigger $trigger           The mechanism that produced the decision.
	 * @param array          $session_data      The session data payload, enriched with the {@see self::VERIFY_RESULT_KEY} bundle.
	 * @return void
	 */
	public function record_decision( FraudDecision $received_decision, FraudDecision $applied_decision, SessionTrigger $trigger, array $session_data ): void {
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
			$event        = $this->build_event( $received_decision, $final_status, $trigger, $session_data );

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
	 * Map the session data payload to a sessions table event row.
	 *
	 * @param FraudDecision      $received_decision The decision as received from the API.
	 * @param SessionFinalStatus $final_status      The effective outcome after overrides.
	 * @param SessionTrigger     $trigger           The mechanism that produced the decision.
	 * @param array              $session_data      The session data payload.
	 * @return array<string, mixed> The event row for {@see SessionEventStore::record_event()}.
	 */
	private function build_event( FraudDecision $received_decision, SessionFinalStatus $final_status, SessionTrigger $trigger, array $session_data ): array {
		$verify_result = is_array( $session_data[ self::VERIFY_RESULT_KEY ] ?? null ) ? $session_data[ self::VERIFY_RESULT_KEY ] : array();
		$customer      = is_array( $session_data['customer'] ?? null ) ? $session_data['customer'] : array();
		$billing       = is_array( $customer['billing_address'] ?? null ) ? $customer['billing_address'] : array();
		$session       = is_array( $session_data['session'] ?? null ) ? $session_data['session'] : array();
		$order         = is_array( $session_data['order'] ?? null ) ? $session_data['order'] : array();

		$email = (string) ( $customer['billing_email'] ?? '' );
		$email = '' === $email ? (string) ( $session['email'] ?? '' ) : $email;

		$billing_name = trim( ( (string) ( $billing['first_name'] ?? '' ) ) . ' ' . ( (string) ( $billing['last_name'] ?? '' ) ) );

		$ip         = \WC_Geolocation::get_ip_address();
		$geo        = \WC_Geolocation::geolocate_ip( $ip, false, false );
		$ip_country = (string) ( $geo['country'] ?? '' );

		$risk_score = $verify_result['risk_score'] ?? null;

		// Caps use mb_substr: the column limits are in characters, and a byte-based
		// substr could split a multibyte character and produce invalid UTF-8.
		return array(
			'session_id'       => mb_substr( (string) ( $verify_result['session_id'] ?? '' ), 0, 64 ),
			'source'           => mb_substr( (string) ( $session_data['source'] ?? '' ), 0, 32 ),
			'decision'         => $received_decision->value,
			'final_status'     => $final_status->value,
			'trigger_type'     => $trigger->value,
			'risk_score'       => is_numeric( $risk_score ) ? (float) $risk_score : null,
			'email'            => mb_substr( strtolower( trim( $email ) ), 0, 254 ),
			'ip'               => mb_substr( $ip, 0, 45 ),
			'ip_country'       => mb_substr( $ip_country, 0, 2 ),
			'billing_country'  => mb_substr( (string) ( $billing['country'] ?? '' ), 0, 2 ),
			'billing_state'    => mb_substr( (string) ( $billing['state'] ?? '' ), 0, 100 ),
			'billing_city'     => mb_substr( (string) ( $billing['city'] ?? '' ), 0, 100 ),
			'billing_postcode' => mb_substr( (string) ( $billing['postcode'] ?? '' ), 0, 20 ),
			'billing_name'     => mb_substr( $billing_name, 0, 255 ),
			'order_id'         => (int) ( $order['order_id'] ?? 0 ),
			'payment_method'   => mb_substr( (string) ( $verify_result['payment_method'] ?? '' ), 0, 64 ),
		);
	}
}
