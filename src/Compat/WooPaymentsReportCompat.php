<?php
/**
 * WooPaymentsReportCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Compat;

use Automattic\WooCommerce\FraudProtection\ApiClient;
use Automattic\WooCommerce\FraudProtection\FraudProtectionController;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

defined( 'ABSPATH' ) || exit;

/**
 * Reports WooPayments payment outcomes to the Blackbox API.
 *
 * Model report flow for gateway compat layers. Listens to the WooPayments
 * webhook delivery action, normalizes a few lifecycle events into
 * ReportContextData, and reports them through the public
 * wc_fraud_protection_report() API:
 *
 * - payment_intent.succeeded      -> payment / captured
 * - payment_intent.payment_failed -> payment / declined or blocked
 * - charge.dispute.created        -> dispute / inquiry or open
 * - charge.dispute.closed         -> dispute / won or lost
 *
 * Fire-and-forget: failures are logged and never affect order processing.
 * Stateless: webhook redeliveries may produce duplicate reports; a stable
 * report_id is a tracked follow-up.
 *
 * @internal
 */
class WooPaymentsReportCompat {

	/**
	 * The WooPayments action fired after a webhook event has been processed.
	 *
	 * Receives the Stripe event type and the full event body, after
	 * WooPayments has validated the event and updated the order.
	 *
	 * @var string
	 */
	private const WEBHOOK_HOOK = 'woocommerce_payments_after_webhook_delivery';

	/**
	 * Stripe dispute status to normalized dispute `result`.
	 *
	 * `warning_*` statuses are pre-dispute inquiries. An unmapped status
	 * skips the report.
	 *
	 * @var array<string, string>
	 */
	private const DISPUTE_STATUS_MAP = array(
		'warning_needs_response' => 'inquiry',
		'warning_under_review'   => 'inquiry',
		'warning_closed'         => 'inquiry',
		'needs_response'         => 'open',
		'under_review'           => 'open',
		'won'                    => 'won',
		'lost'                   => 'lost',
	);

	/**
	 * Stripe dispute reason to normalized dispute `reason`.
	 *
	 * An unmapped reason falls back to `other`.
	 *
	 * @var array<string, string>
	 */
	private const DISPUTE_REASON_MAP = array(
		'fraudulent'                => 'fraud',
		'unrecognized'              => 'unrecognized',
		'subscription_canceled'     => 'subscription_canceled',
		'product_not_received'      => 'product_not_received',
		'product_unacceptable'      => 'product_not_as_described',
		'credit_not_processed'      => 'credit_not_processed',
		'duplicate'                 => 'duplicate',
		'bank_cannot_process'       => 'bank',
		'check_returned'            => 'bank',
		'debit_not_authorized'      => 'bank',
		'insufficient_funds'        => 'bank',
		'incorrect_account_details' => 'bank',
		'general'                   => 'other',
		'noncompliant'              => 'other',
		'customer_initiated'        => 'other',
	);

	/**
	 * Stripe decline code to normalized payment refusal `reason`.
	 *
	 * Intentionally minimal; unmapped codes fall back to `generic_decline`.
	 * Extending the map is follow-up work.
	 *
	 * @var array<string, string>
	 */
	private const DECLINE_REASON_MAP = array(
		'fraudulent'         => 'suspected_fraud',
		'lost_card'          => 'lost_or_stolen',
		'stolen_card'        => 'lost_or_stolen',
		'pickup_card'        => 'lost_or_stolen',
		'restricted_card'    => 'restricted_card',
		'merchant_blacklist' => 'restricted_card',
		'security_violation' => 'security_violation',
		'incorrect_cvc'      => 'incorrect_cvc',
		'invalid_cvc'        => 'incorrect_cvc',
		'incorrect_zip'      => 'incorrect_avs',
		'incorrect_number'   => 'incorrect_number',
		'invalid_number'     => 'incorrect_number',
		'insufficient_funds' => 'insufficient_funds',
	);

	/**
	 * Register the webhook listener.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! FraudProtectionController::feature_is_enabled() ) {
			return;
		}

		add_action( self::WEBHOOK_HOOK, array( $this, 'handle_webhook_event' ), 10, 2 );
	}

	/**
	 * Route a processed WooPayments webhook event to its report builder.
	 *
	 * Fire-and-forget: any failure is logged and swallowed so reporting can
	 * never affect order processing.
	 *
	 * @param mixed $event_type The Stripe event type (e.g. 'charge.dispute.created').
	 * @param mixed $event_body The full Stripe event body.
	 * @return void
	 */
	public function handle_webhook_event( $event_type, $event_body ): void {
		try {
			if ( ! is_string( $event_type ) || ! is_array( $event_body ) ) {
				return;
			}

			$event_object = $event_body['data']['object'] ?? null;
			if ( ! is_array( $event_object ) ) {
				return;
			}

			switch ( $event_type ) {
				case 'payment_intent.succeeded':
					$this->report_payment_succeeded( $event_body, $event_object );
					break;
				case 'payment_intent.payment_failed':
					$this->report_payment_failed( $event_body, $event_object );
					break;
				case 'charge.dispute.created':
				case 'charge.dispute.closed':
					$this->report_dispute( $event_type, $event_body, $event_object );
					break;
			}
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'WooPaymentsReportCompat: failed to report WooPayments webhook event',
				array(
					'event_source'       => 'woopayments_report_compat',
					'hook'               => self::WEBHOOK_HOOK,
					'webhook_event_type' => is_string( $event_type ) ? $event_type : gettype( $event_type ),
					'error'              => $e->getTraceAsString(),
					'exception_class'    => get_class( $e ),
					'exception_message'  => $e->getMessage(),
					'exception_file'     => $e->getFile(),
					'exception_line'     => $e->getLine(),
				),
				true
			);
		}
	}

	/**
	 * Report a captured payment (payment_intent.succeeded).
	 *
	 * @param array $event_body The full Stripe event body.
	 * @param array $intent     The PaymentIntent object from the event.
	 * @return void
	 */
	private function report_payment_succeeded( array $event_body, array $intent ): void {
		$order = $this->resolve_order_from_intent( $intent );
		if ( null === $order || ! $this->order_has_session( $order ) ) {
			return;
		}

		$context = ReportContextData::from_array(
			array(
				'type'        => ReportContextData::TYPE_PAYMENT,
				'result'      => 'captured',
				'amount'      => $this->build_amount( $intent['amount_received'] ?? null, $intent['currency'] ?? null ),
				'occurred_at' => $this->resolve_occurred_at( $event_body, $intent ),
				'correlation' => array(
					'transaction_id'     => $this->extract_charge_id( $intent ),
					'payment_attempt_id' => $intent['id'] ?? null,
				),
			)
		);

		if ( null === $context ) {
			return;
		}

		wc_fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, $context );
	}

	/**
	 * Report a refused payment (payment_intent.payment_failed).
	 *
	 * A provider fraud block (Stripe/Radar `outcome.type = blocked`, surfaced
	 * as a masked `fraudulent` decline code) is classified as `blocked`
	 * before the decline map.
	 *
	 * @param array $event_body The full Stripe event body.
	 * @param array $intent     The PaymentIntent object from the event.
	 * @return void
	 */
	private function report_payment_failed( array $event_body, array $intent ): void {
		$order = $this->resolve_order_from_intent( $intent );
		if ( null === $order || ! $this->order_has_session( $order ) ) {
			return;
		}

		$error        = is_array( $intent['last_payment_error'] ?? null ) ? $intent['last_payment_error'] : array();
		$decline_code = $error['decline_code'] ?? ( $error['code'] ?? '' );
		$decline_code = is_string( $decline_code ) ? $decline_code : '';

		if ( $this->is_provider_fraud_block( $intent, $decline_code ) ) {
			$result = 'blocked';
			$reason = 'suspected_fraud';
		} else {
			$result = 'declined';
			$reason = self::DECLINE_REASON_MAP[ $decline_code ] ?? 'generic_decline';
		}

		$context = ReportContextData::from_array(
			array(
				'type'        => ReportContextData::TYPE_PAYMENT,
				'result'      => $result,
				'reason'      => $reason,
				'amount'      => $this->build_amount( $intent['amount'] ?? null, $intent['currency'] ?? null ),
				'occurred_at' => $this->resolve_occurred_at( $event_body, $intent ),
				'correlation' => array(
					'transaction_id'     => $this->extract_charge_id( $intent ),
					'payment_attempt_id' => $intent['id'] ?? null,
				),
			)
		);

		if ( null === $context ) {
			return;
		}

		wc_fraud_protection_report( $order, ApiClient::REPORT_SOURCE_API, $context );
	}

	/**
	 * Report a dispute lifecycle event (charge.dispute.created / .closed).
	 *
	 * @param string $event_type The Stripe event type.
	 * @param array  $event_body The full Stripe event body.
	 * @param array  $dispute    The Dispute object from the event.
	 * @return void
	 */
	private function report_dispute( string $event_type, array $event_body, array $dispute ): void {
		$charge_id = $dispute['charge'] ?? '';
		if ( ! is_string( $charge_id ) || '' === $charge_id ) {
			return;
		}

		$order = $this->find_order_by_meta( '_charge_id', $charge_id );
		if ( null === $order || ! $this->order_has_session( $order ) ) {
			return;
		}

		$status = isset( $dispute['status'] ) && is_string( $dispute['status'] ) ? $dispute['status'] : '';
		$result = self::DISPUTE_STATUS_MAP[ $status ] ?? null;
		if ( null === $result ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'WooPaymentsReportCompat: unmapped dispute status "%s" on %s, skipping report.', $status, $event_type )
			);
			return;
		}

		$raw_reason = isset( $dispute['reason'] ) && is_string( $dispute['reason'] ) ? $dispute['reason'] : '';

		$context = ReportContextData::from_array(
			array(
				'type'        => ReportContextData::TYPE_DISPUTE,
				'result'      => $result,
				'reason'      => self::DISPUTE_REASON_MAP[ $raw_reason ] ?? 'other',
				'amount'      => $this->build_amount( $dispute['amount'] ?? null, $dispute['currency'] ?? null ),
				'occurred_at' => $this->resolve_occurred_at( $event_body, $dispute ),
				'correlation' => array(
					'transaction_id'     => $charge_id,
					'payment_attempt_id' => $dispute['payment_intent'] ?? null,
					'dispute_id'         => $dispute['id'] ?? null,
				),
			)
		);

		if ( null === $context ) {
			return;
		}

		wc_fraud_protection_report( $order, ApiClient::REPORT_SOURCE_CHARGEBACK, $context );
	}

	/**
	 * Check whether a failed intent was blocked by the provider's fraud layer.
	 *
	 * Stripe/Radar blocks surface as `charge.outcome.type = blocked` with a
	 * masked `fraudulent` decline code.
	 *
	 * @param array  $intent       The PaymentIntent object from the event.
	 * @param string $decline_code The resolved decline code.
	 * @return bool
	 */
	private function is_provider_fraud_block( array $intent, string $decline_code ): bool {
		if ( 'fraudulent' === $decline_code ) {
			return true;
		}

		$outcome_type = $intent['charges']['data'][0]['outcome']['type'] ?? '';
		return 'blocked' === $outcome_type;
	}

	/**
	 * Resolve the Woo order for a PaymentIntent event.
	 *
	 * WooPayments stores the Woo order ID in the intent metadata and the
	 * intent ID in order meta; try the metadata first, then the meta lookup.
	 *
	 * @param array $intent The PaymentIntent object from the event.
	 * @return ?\WC_Order The order, or null when it cannot be resolved.
	 */
	private function resolve_order_from_intent( array $intent ): ?\WC_Order {
		$metadata = is_array( $intent['metadata'] ?? null ) ? $intent['metadata'] : array();

		if ( isset( $metadata['order_id'] ) && is_numeric( $metadata['order_id'] ) ) {
			$order = wc_get_order( (int) $metadata['order_id'] );
			if ( $order instanceof \WC_Order ) {
				return $order;
			}
		}

		$intent_id = $intent['id'] ?? '';
		if ( ! is_string( $intent_id ) || '' === $intent_id ) {
			return null;
		}

		return $this->find_order_by_meta( '_intent_id', $intent_id );
	}

	/**
	 * Find an order by a WooPayments meta key/value pair.
	 *
	 * Mirrors WooPayments' own WC_Payments_DB lookup without depending on
	 * the class, so the compat works (and is testable) standalone.
	 *
	 * @param string $meta_key   The order meta key ('_intent_id' or '_charge_id').
	 * @param string $meta_value The provider ID to look up.
	 * @return ?\WC_Order The order, or null when not found.
	 */
	private function find_order_by_meta( string $meta_key, string $meta_value ): ?\WC_Order {
		$orders = wc_get_orders(
			array(
				'limit'      => 1,
				'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$order = is_array( $orders ) && array() !== $orders ? $orders[0] : null;
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Check whether the order carries a Blackbox session ID.
	 *
	 * Orders without one (subscription renewals, off-session charges, orders
	 * predating the plugin) never went through verify; bail silently so the
	 * report API's missing-session warning stays meaningful.
	 *
	 * @param \WC_Order $order The order to check.
	 * @return bool
	 */
	private function order_has_session( \WC_Order $order ): bool {
		$session_id = $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY );
		return is_string( $session_id ) && '' !== $session_id;
	}

	/**
	 * Build the context `amount` object from Stripe minor units and currency.
	 *
	 * @param mixed $minor_units The Stripe amount (already in minor units).
	 * @param mixed $currency    The Stripe currency code.
	 * @return ?array{minor_units: int, currency: string} The amount, or null when incomplete.
	 */
	private function build_amount( $minor_units, $currency ): ?array {
		if ( ! is_numeric( $minor_units ) || ! is_string( $currency ) || '' === $currency ) {
			return null;
		}

		return array(
			'minor_units' => (int) $minor_units,
			'currency'    => strtoupper( $currency ),
		);
	}

	/**
	 * Resolve the event time as UTC ISO 8601.
	 *
	 * Prefers the Stripe event time, then the event object's own time.
	 * Returns null otherwise, letting ReportContextData fall back to now.
	 *
	 * @param array $event_body   The full Stripe event body.
	 * @param array $event_object The object from the event.
	 * @return ?string
	 */
	private function resolve_occurred_at( array $event_body, array $event_object ): ?string {
		$timestamp = $event_body['created'] ?? ( $event_object['created'] ?? null );
		if ( ! is_numeric( $timestamp ) || (int) $timestamp <= 0 ) {
			return null;
		}

		return gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp );
	}

	/**
	 * Extract the charge ID from a PaymentIntent object.
	 *
	 * Reads the legacy embedded charge list first (the shape WooPayments
	 * webhooks deliver), then `latest_charge` (newer Stripe API versions,
	 * string form only).
	 *
	 * @param array $intent The PaymentIntent object from the event.
	 * @return ?string
	 */
	private function extract_charge_id( array $intent ): ?string {
		$charge_id = $intent['charges']['data'][0]['id'] ?? ( $intent['latest_charge'] ?? null );
		return is_string( $charge_id ) && '' !== $charge_id ? $charge_id : null;
	}
}
