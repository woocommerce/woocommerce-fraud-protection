<?php
/**
 * SessionEventRecorder class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;

defined( 'ABSPATH' ) || exit;

/**
 * Records actionable fraud verdicts into the sessions log.
 *
 * Invoked from `DecisionHandler::apply_decision()` with the raw parsed wire
 * verdict — not the enforcement outcome — so that block and challenge
 * verdicts are recorded even when enforcement is suppressed (learning mode,
 * filter overrides). Only Block and Challenge verdicts are recorded; ordinary
 * allowed traffic never touches the table.
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
	 * The verdicts that get recorded.
	 */
	private const RECORDED_VERDICTS = array( FraudDecision::Block, FraudDecision::Challenge );

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
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionEventStore    $event_store            The session event store instance.
	 * @param MerchantListsFeature $merchant_lists_feature The merchant lists feature gate instance.
	 */
	final public function init( SessionEventStore $event_store, MerchantListsFeature $merchant_lists_feature ): void {
		$this->event_store            = $event_store;
		$this->merchant_lists_feature = $merchant_lists_feature;
	}

	/**
	 * Record a verdict if it is one of the recorded kinds and the feature is enabled.
	 *
	 * @param FraudDecision      $raw_verdict  The raw parsed wire verdict, before any coercion or override.
	 * @param SessionFinalStatus $final_status The effective outcome after overrides.
	 * @param SessionTrigger     $trigger      The mechanism that produced the verdict.
	 * @param array              $session_data The session data payload, enriched with the {@see self::VERIFY_RESULT_KEY} bundle.
	 * @return void
	 */
	public function record_verdict( FraudDecision $raw_verdict, SessionFinalStatus $final_status, SessionTrigger $trigger, array $session_data ): void {
		try {
			if ( ! in_array( $raw_verdict, self::RECORDED_VERDICTS, true ) || ! $this->merchant_lists_feature->is_enabled() ) {
				return;
			}

			$event = $this->build_event( $raw_verdict, $final_status, $trigger, $session_data );

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
	 * @param FraudDecision      $raw_verdict  The raw parsed wire verdict.
	 * @param SessionFinalStatus $final_status The effective outcome after overrides.
	 * @param SessionTrigger     $trigger      The mechanism that produced the verdict.
	 * @param array              $session_data The session data payload.
	 * @return array<string, mixed> The event row for {@see SessionEventStore::record_event()}.
	 */
	private function build_event( FraudDecision $raw_verdict, SessionFinalStatus $final_status, SessionTrigger $trigger, array $session_data ): array {
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

		return array(
			'session_id'       => substr( (string) ( $verify_result['session_id'] ?? '' ), 0, 64 ),
			'source'           => substr( (string) ( $session_data['source'] ?? '' ), 0, 32 ),
			'verdict'          => $raw_verdict->value,
			'final_status'     => $final_status->value,
			'trigger_type'     => $trigger->value,
			'risk_score'       => is_numeric( $risk_score ) ? (float) $risk_score : null,
			'email'            => substr( strtolower( trim( $email ) ), 0, 254 ),
			'ip'               => substr( $ip, 0, 45 ),
			'ip_country'       => substr( $ip_country, 0, 2 ),
			'billing_country'  => substr( (string) ( $billing['country'] ?? '' ), 0, 2 ),
			'billing_state'    => substr( (string) ( $billing['state'] ?? '' ), 0, 100 ),
			'billing_city'     => substr( (string) ( $billing['city'] ?? '' ), 0, 100 ),
			'billing_postcode' => substr( (string) ( $billing['postcode'] ?? '' ), 0, 20 ),
			'billing_name'     => substr( $billing_name, 0, 255 ),
			'order_id'         => (int) ( $order['order_id'] ?? 0 ),
			'payment_method'   => substr( (string) ( $verify_result['payment_method'] ?? '' ), 0, 64 ),
		);
	}
}
