<?php
/**
 * ReportContextData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable, normalized description of a single payment-outcome event.
 *
 * Built from gateway data and sent as the `context` object on a report. Only
 * normalized enums are exposed here — raw gateway codes stay on the Woo order.
 * `classification` is derived from `reason`; `evidence_strength` from the outcome.
 * A held instance is always reportable: `from_array()` returns null when a required
 * enum cannot be mapped, so callers never carry a half-built context.
 *
 * @internal
 */
class ReportContextData {

	/**
	 * Version for this context schema. Bump when the shape changes.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Event phase: a charge attempt and its lifecycle.
	 */
	public const TYPE_PAYMENT = 'payment';

	/**
	 * Event phase: a chargeback, inquiry, or dispute resolution.
	 */
	public const TYPE_DISPUTE = 'dispute';

	/**
	 * Event phase: a merchant refund or return.
	 */
	public const TYPE_REFUND = 'refund';

	/**
	 * Valid event phases.
	 *
	 * @var array<int, string>
	 */
	public const VALID_TYPES = array(
		self::TYPE_PAYMENT,
		self::TYPE_DISPUTE,
		self::TYPE_REFUND,
	);

	/**
	 * Allowed `result` values per `type`.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const RESULTS_BY_TYPE = array(
		self::TYPE_PAYMENT => array(
			'captured',
			'authorized',
			'pending',
			'declined',
			'blocked',
			'review_pending',
			'review_approved',
			'review_rejected',
			'review_expired',
			'voided',
			'canceled',
		),
		self::TYPE_DISPUTE => array(
			'inquiry',
			'open',
			'won',
			'lost',
			'accepted',
			'withdrawn',
			'prevented',
		),
		self::TYPE_REFUND  => array(
			'refunded',
			'partially_refunded',
		),
	);

	/**
	 * Classification: fraud, stolen card, security, provider block, or unauthorized dispute.
	 */
	public const CLASSIFICATION_FRAUD_FLAGGED = 'fraud_flagged';

	/**
	 * Classification: CVC, AVS, account number, or expiry mismatch.
	 */
	public const CLASSIFICATION_VERIFICATION_MISMATCH = 'verification_mismatch';

	/**
	 * Classification: terminal issuer or compliance decline without a clear fraud signal.
	 */
	public const CLASSIFICATION_HARD_DECLINE = 'hard_decline';

	/**
	 * Classification: retriable decline, funds issue, outage, SCA, or velocity signal.
	 */
	public const CLASSIFICATION_SOFT_DECLINE = 'soft_decline';

	/**
	 * Classification: fulfillment, billing, subscription, return, or refund dispute.
	 */
	public const CLASSIFICATION_SERVICE_DISPUTE = 'service_dispute';

	/**
	 * Classification: test, duplicate, request, bank, or operational outcome with no fraud signal.
	 */
	public const CLASSIFICATION_NO_RISK = 'no_risk';

	/**
	 * Evidence tier: settled evidence.
	 */
	public const EVIDENCE_CONFIRMED = 'confirmed';

	/**
	 * Evidence tier: useful signal that needs more context.
	 */
	public const EVIDENCE_CORRELATED = 'correlated';

	/**
	 * Evidence tier: no fraud signal.
	 */
	public const EVIDENCE_NEUTRAL = 'neutral';

	/**
	 * Normalized dispute reasons.
	 *
	 * @var array<int, string>
	 */
	private const DISPUTE_REASONS = array(
		'fraud',
		'unrecognized',
		'subscription_canceled',
		'canceled_or_returned',
		'product_not_received',
		'product_not_as_described',
		'credit_not_processed',
		'duplicate',
		'bank',
		'other',
	);

	/**
	 * Normalized payment refusal reasons (for declined and blocked payments).
	 *
	 * @var array<int, string>
	 */
	private const PAYMENT_REASONS = array(
		'lost_or_stolen',
		'suspected_fraud',
		'restricted_card',
		'security_violation',
		'incorrect_cvc',
		'incorrect_avs',
		'incorrect_number',
		'incorrect_expiry',
		'do_not_honor',
		'generic_decline',
		'compliance',
		'card_not_supported',
		'unsupported_currency',
		'expired_card',
		'invalid_account',
		'not_permitted',
		'insufficient_funds',
		'limit_exceeded',
		'velocity_exceeded',
		'authentication_required',
		'issuer_unavailable',
		'processing_error',
		'test_mode',
		'duplicate',
		'request_error',
		'operational',
	);

	/**
	 * Catch-all reason for a blocked payment with no better signal.
	 */
	private const REASON_BLOCKED_FALLBACK = 'suspected_fraud';

	/**
	 * Catch-all reason for a masked or unmapped decline.
	 */
	private const REASON_DECLINED_FALLBACK = 'generic_decline';

	/**
	 * Maps each normalized reason to its classification group.
	 *
	 * @var array<string, string>
	 */
	private const REASON_CLASSIFICATION = array(
		// Dispute reasons.
		'fraud'                    => self::CLASSIFICATION_FRAUD_FLAGGED,
		'unrecognized'             => self::CLASSIFICATION_FRAUD_FLAGGED,
		'subscription_canceled'    => self::CLASSIFICATION_SERVICE_DISPUTE,
		'canceled_or_returned'     => self::CLASSIFICATION_SERVICE_DISPUTE,
		'product_not_received'     => self::CLASSIFICATION_SERVICE_DISPUTE,
		'product_not_as_described' => self::CLASSIFICATION_SERVICE_DISPUTE,
		'credit_not_processed'     => self::CLASSIFICATION_SERVICE_DISPUTE,
		'duplicate'                => self::CLASSIFICATION_NO_RISK,
		'bank'                     => self::CLASSIFICATION_NO_RISK,
		'other'                    => self::CLASSIFICATION_NO_RISK,
		// Payment refusal reasons.
		'lost_or_stolen'           => self::CLASSIFICATION_FRAUD_FLAGGED,
		'suspected_fraud'          => self::CLASSIFICATION_FRAUD_FLAGGED,
		'restricted_card'          => self::CLASSIFICATION_FRAUD_FLAGGED,
		'security_violation'       => self::CLASSIFICATION_FRAUD_FLAGGED,
		'incorrect_cvc'            => self::CLASSIFICATION_VERIFICATION_MISMATCH,
		'incorrect_avs'            => self::CLASSIFICATION_VERIFICATION_MISMATCH,
		'incorrect_number'         => self::CLASSIFICATION_VERIFICATION_MISMATCH,
		'incorrect_expiry'         => self::CLASSIFICATION_VERIFICATION_MISMATCH,
		'do_not_honor'             => self::CLASSIFICATION_HARD_DECLINE,
		'generic_decline'          => self::CLASSIFICATION_HARD_DECLINE,
		'compliance'               => self::CLASSIFICATION_HARD_DECLINE,
		'card_not_supported'       => self::CLASSIFICATION_HARD_DECLINE,
		'unsupported_currency'     => self::CLASSIFICATION_HARD_DECLINE,
		'expired_card'             => self::CLASSIFICATION_HARD_DECLINE,
		'invalid_account'          => self::CLASSIFICATION_HARD_DECLINE,
		'not_permitted'            => self::CLASSIFICATION_HARD_DECLINE,
		'insufficient_funds'       => self::CLASSIFICATION_SOFT_DECLINE,
		'limit_exceeded'           => self::CLASSIFICATION_SOFT_DECLINE,
		'velocity_exceeded'        => self::CLASSIFICATION_SOFT_DECLINE,
		'authentication_required'  => self::CLASSIFICATION_SOFT_DECLINE,
		'issuer_unavailable'       => self::CLASSIFICATION_SOFT_DECLINE,
		'processing_error'         => self::CLASSIFICATION_SOFT_DECLINE,
		'test_mode'                => self::CLASSIFICATION_NO_RISK,
		'request_error'            => self::CLASSIFICATION_NO_RISK,
		'operational'              => self::CLASSIFICATION_NO_RISK,
	);

	/**
	 * Event phase: TYPE_PAYMENT, TYPE_DISPUTE, or TYPE_REFUND.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Outcome within the phase (validated against RESULTS_BY_TYPE).
	 *
	 * @var string
	 */
	private string $result;

	/**
	 * Normalized cause, or null when not applicable.
	 *
	 * @var ?string
	 */
	private ?string $reason;

	/**
	 * Cause group derived from `reason`, or null when there is no reason.
	 *
	 * @var ?string
	 */
	private ?string $classification;

	/**
	 * Confidence tier derived from the outcome. Always set.
	 *
	 * @var string
	 */
	private string $evidence_strength;

	/**
	 * Amount in minor units, or null when no amount is known.
	 *
	 * @var ?int
	 */
	private ?int $amount_minor_units;

	/**
	 * ISO-4217 currency code, or null when no amount is known.
	 *
	 * @var ?string
	 */
	private ?string $amount_currency;

	/**
	 * Best known event time in UTC ISO 8601. Always set.
	 *
	 * @var string
	 */
	private string $occurred_at;

	/**
	 * WooCommerce gateway ID. May be empty until enriched from the order.
	 *
	 * @var string
	 */
	private string $gateway;

	/**
	 * Correlation: Woo order ID, or null.
	 *
	 * @var ?int
	 */
	private ?int $correlation_order_id;

	/**
	 * Correlation: provider transaction/charge ID, or null.
	 *
	 * @var ?string
	 */
	private ?string $correlation_transaction_id;

	/**
	 * Correlation: provider payment-attempt/order ID, or null.
	 *
	 * @var ?string
	 */
	private ?string $correlation_payment_attempt_id;

	/**
	 * Correlation: provider dispute ID, or null.
	 *
	 * @var ?string
	 */
	private ?string $correlation_dispute_id;

	/**
	 * Correlation: provider refund ID, or null.
	 *
	 * @var ?string
	 */
	private ?string $correlation_refund_id;

	/**
	 * Correlation: card-network transaction reference, or null.
	 *
	 * @var ?string
	 */
	private ?string $correlation_network_transaction_id;

	/**
	 * Build a context from the JSON-shaped array.
	 *
	 * Sanitizes enums, derives `classification` and `evidence_strength`, and resolves
	 * `reason`. Returns null — and logs — when a required enum cannot be mapped: `type`,
	 * `result`, or a dispute's `reason`. A held instance is therefore always reportable.
	 *
	 * @param array<string, mixed> $data Context fields in the API shape.
	 * @return ?self The context, or null when it cannot be reported.
	 */
	public static function from_array( array $data ): ?self {
		$type = isset( $data['type'] ) && is_string( $data['type'] ) ? $data['type'] : '';
		if ( ! in_array( $type, self::VALID_TYPES, true ) ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Unmappable report context type "%s", skipping report.', $type )
			);
			return null;
		}

		$result = isset( $data['result'] ) && is_string( $data['result'] ) ? $data['result'] : '';
		if ( ! in_array( $result, self::RESULTS_BY_TYPE[ $type ], true ) ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Unmappable report context result "%s" for type "%s", skipping report.', $result, $type )
			);
			return null;
		}

		$raw_reason = isset( $data['reason'] ) && is_string( $data['reason'] ) ? $data['reason'] : null;
		$reason     = self::resolve_reason( $type, $result, $raw_reason );

		// A dispute's reason is required and has no documented catch-all; skip rather than guess.
		if ( self::TYPE_DISPUTE === $type && null === $reason ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Unmappable required dispute reason "%s", skipping report.', null === $raw_reason ? '' : $raw_reason )
			);
			return null;
		}

		$classification = null === $reason ? null : self::REASON_CLASSIFICATION[ $reason ];

		list( $amount_minor_units, $amount_currency ) = self::sanitize_amount( $data['amount'] ?? null );

		$correlation = is_array( $data['correlation'] ?? null ) ? $data['correlation'] : array();

		return new self(
			$type,
			$result,
			$reason,
			$classification,
			self::derive_evidence_strength( $type, $result, $classification ),
			$amount_minor_units,
			$amount_currency,
			self::normalize_occurred_at( isset( $data['occurred_at'] ) && is_string( $data['occurred_at'] ) ? $data['occurred_at'] : null ),
			isset( $data['gateway'] ) && is_string( $data['gateway'] ) ? sanitize_text_field( $data['gateway'] ) : '',
			isset( $correlation['order_id'] ) && is_numeric( $correlation['order_id'] ) && (int) $correlation['order_id'] > 0 ? (int) $correlation['order_id'] : null,
			self::sanitize_correlation_id( $correlation['transaction_id'] ?? null ),
			self::sanitize_correlation_id( $correlation['payment_attempt_id'] ?? null ),
			self::sanitize_correlation_id( $correlation['dispute_id'] ?? null ),
			self::sanitize_correlation_id( $correlation['refund_id'] ?? null ),
			self::sanitize_correlation_id( $correlation['network_transaction_id'] ?? null )
		);
	}

	/**
	 * Return a copy with order-derived fields filled in where missing.
	 *
	 * Only fills an empty gateway and a missing correlation order ID, so caller-supplied
	 * values always win. Mirrors PaymentMethodData::with_transaction_mode().
	 *
	 * @param int    $order_id Woo order ID.
	 * @param string $gateway  Woo gateway ID (payment method).
	 * @return self
	 */
	public function with_order_defaults( int $order_id, string $gateway ): self {
		$clone = clone $this;

		if ( '' === $clone->gateway ) {
			$clone->gateway = sanitize_text_field( $gateway );
		}

		if ( null === $clone->correlation_order_id && $order_id > 0 ) {
			$clone->correlation_order_id = $order_id;
		}

		return $clone;
	}

	/**
	 * Serialize to the API `context` shape.
	 *
	 * Required fields are always present; null or empty optionals are omitted, since the
	 * report transport does not strip empties. `amount` and `correlation` nest their fields.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$context = array(
			'schema_version' => self::SCHEMA_VERSION,
			'type'           => $this->type,
			'result'         => $this->result,
		);

		if ( null !== $this->reason ) {
			$context['reason'] = $this->reason;
		}

		if ( null !== $this->classification ) {
			$context['classification'] = $this->classification;
		}

		$context['evidence_strength'] = $this->evidence_strength;

		if ( null !== $this->amount_minor_units && null !== $this->amount_currency ) {
			$context['amount'] = array(
				'minor_units' => $this->amount_minor_units,
				'currency'    => $this->amount_currency,
			);
		}

		$context['occurred_at'] = $this->occurred_at;
		$context['gateway']     = $this->gateway;

		$correlation = $this->correlation_to_array();
		if ( array() !== $correlation ) {
			$context['correlation'] = $correlation;
		}

		return $context;
	}

	/**
	 * Resolve the normalized reason for the event.
	 *
	 * Declined and blocked payments fall back to a documented default when the gateway
	 * value cannot be mapped. Disputes have no documented default, so an unmappable
	 * dispute reason returns null (and from_array() skips the report). Refunds and other
	 * payment outcomes carry no reason.
	 *
	 * @param string  $type       Event phase.
	 * @param string  $result     Outcome within the phase.
	 * @param ?string $raw_reason Caller-supplied reason value.
	 * @return ?string Normalized reason, or null when unmapped or not applicable.
	 */
	private static function resolve_reason( string $type, string $result, ?string $raw_reason ): ?string {
		if ( self::TYPE_REFUND === $type ) {
			return null;
		}

		$allowed = self::TYPE_DISPUTE === $type ? self::DISPUTE_REASONS : self::PAYMENT_REASONS;
		$mapped  = null !== $raw_reason && in_array( $raw_reason, $allowed, true ) ? $raw_reason : null;

		if ( self::TYPE_PAYMENT === $type && in_array( $result, array( 'declined', 'blocked' ), true ) && null === $mapped ) {
			return 'blocked' === $result ? self::REASON_BLOCKED_FALLBACK : self::REASON_DECLINED_FALLBACK;
		}

		return $mapped;
	}

	/**
	 * Derive the evidence tier from the outcome.
	 *
	 * @param string  $type           Event phase.
	 * @param string  $result         Outcome within the phase.
	 * @param ?string $classification Derived classification (used for declines).
	 * @return string One of the EVIDENCE_* tiers.
	 */
	private static function derive_evidence_strength( string $type, string $result, ?string $classification ): string {
		if ( self::TYPE_DISPUTE === $type ) {
			if ( in_array( $result, array( 'lost', 'accepted', 'won' ), true ) ) {
				return self::EVIDENCE_CONFIRMED;
			}
			if ( in_array( $result, array( 'open', 'inquiry', 'prevented' ), true ) ) {
				return self::EVIDENCE_CORRELATED;
			}
			return self::EVIDENCE_NEUTRAL; // withdrawn.
		}

		if ( self::TYPE_REFUND === $type ) {
			return self::EVIDENCE_CORRELATED;
		}

		// Payments.
		if ( in_array( $result, array( 'blocked', 'review_rejected', 'review_approved' ), true ) ) {
			return self::EVIDENCE_CONFIRMED;
		}

		if ( 'declined' === $result ) {
			return self::CLASSIFICATION_NO_RISK === $classification ? self::EVIDENCE_NEUTRAL : self::EVIDENCE_CORRELATED;
		}

		if ( in_array( $result, array( 'review_pending', 'review_expired' ), true ) ) {
			return self::EVIDENCE_CORRELATED;
		}

		return self::EVIDENCE_NEUTRAL; // captured, authorized, pending, voided, canceled.
	}

	/**
	 * Sanitize the amount object into a [minor_units, currency] pair.
	 *
	 * Both must resolve for an amount to be reported; otherwise both are null.
	 *
	 * @param mixed $raw Raw amount value.
	 * @return array{0: ?int, 1: ?string}
	 */
	private static function sanitize_amount( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array( null, null );
		}

		$minor_units = isset( $raw['minor_units'] ) && is_numeric( $raw['minor_units'] ) ? (int) $raw['minor_units'] : null;

		$currency = null;
		if ( isset( $raw['currency'] ) && is_string( $raw['currency'] ) ) {
			$candidate = strtoupper( trim( $raw['currency'] ) );
			$currency  = (bool) preg_match( '/^[A-Z]{3}$/', $candidate ) ? $candidate : null;
		}

		if ( null === $minor_units || null === $currency ) {
			return array( null, null );
		}

		return array( $minor_units, $currency );
	}

	/**
	 * Normalize an event time to UTC ISO 8601, falling back to now.
	 *
	 * @param ?string $raw Caller-supplied time string.
	 * @return string UTC ISO 8601 timestamp.
	 */
	private static function normalize_occurred_at( ?string $raw ): string {
		// Only trust ISO-8601-style input; reject relative ("now") and "@unix" forms.
		$candidate = null === $raw ? '' : trim( $raw );
		if ( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}([T ].+)?$/', $candidate ) ) {
			try {
				$parsed = new \DateTimeImmutable( $candidate );
				return $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
			} catch ( \Exception $e ) {
				// Fall through to now.
				unset( $e );
			}
		}

		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Sanitize a string correlation ID.
	 *
	 * @param mixed $raw Raw ID value.
	 * @return ?string Trimmed ID, or null when empty/unusable.
	 */
	private static function sanitize_correlation_id( $raw ): ?string {
		if ( ! is_string( $raw ) && ! is_int( $raw ) ) {
			return null;
		}

		$value = sanitize_text_field( (string) $raw );
		return '' === $value ? null : $value;
	}

	/**
	 * Build the non-null correlation map for the wire.
	 *
	 * @return array<string, int|string>
	 */
	private function correlation_to_array(): array {
		$correlation = array();

		if ( null !== $this->correlation_order_id ) {
			$correlation['order_id'] = $this->correlation_order_id;
		}
		if ( null !== $this->correlation_transaction_id ) {
			$correlation['transaction_id'] = $this->correlation_transaction_id;
		}
		if ( null !== $this->correlation_payment_attempt_id ) {
			$correlation['payment_attempt_id'] = $this->correlation_payment_attempt_id;
		}
		if ( null !== $this->correlation_dispute_id ) {
			$correlation['dispute_id'] = $this->correlation_dispute_id;
		}
		if ( null !== $this->correlation_refund_id ) {
			$correlation['refund_id'] = $this->correlation_refund_id;
		}
		if ( null !== $this->correlation_network_transaction_id ) {
			$correlation['network_transaction_id'] = $this->correlation_network_transaction_id;
		}

		return $correlation;
	}

	/**
	 * Constructor.
	 *
	 * @param string  $type                               Event phase.
	 * @param string  $result                             Outcome within the phase.
	 * @param ?string $reason                             Normalized reason.
	 * @param ?string $classification                     Derived classification.
	 * @param string  $evidence_strength                  Derived evidence tier.
	 * @param ?int    $amount_minor_units                 Amount in minor units.
	 * @param ?string $amount_currency                    ISO-4217 currency.
	 * @param string  $occurred_at                        UTC ISO 8601 event time.
	 * @param string  $gateway                            Woo gateway ID.
	 * @param ?int    $correlation_order_id               Woo order ID.
	 * @param ?string $correlation_transaction_id         Provider transaction ID.
	 * @param ?string $correlation_payment_attempt_id     Provider payment-attempt ID.
	 * @param ?string $correlation_dispute_id             Provider dispute ID.
	 * @param ?string $correlation_refund_id              Provider refund ID.
	 * @param ?string $correlation_network_transaction_id Card-network transaction reference.
	 */
	private function __construct(
		string $type,
		string $result,
		?string $reason,
		?string $classification,
		string $evidence_strength,
		?int $amount_minor_units,
		?string $amount_currency,
		string $occurred_at,
		string $gateway,
		?int $correlation_order_id,
		?string $correlation_transaction_id,
		?string $correlation_payment_attempt_id,
		?string $correlation_dispute_id,
		?string $correlation_refund_id,
		?string $correlation_network_transaction_id
	) {
		$this->type                               = $type;
		$this->result                             = $result;
		$this->reason                             = $reason;
		$this->classification                     = $classification;
		$this->evidence_strength                  = $evidence_strength;
		$this->amount_minor_units                 = $amount_minor_units;
		$this->amount_currency                    = $amount_currency;
		$this->occurred_at                        = $occurred_at;
		$this->gateway                            = $gateway;
		$this->correlation_order_id               = $correlation_order_id;
		$this->correlation_transaction_id         = $correlation_transaction_id;
		$this->correlation_payment_attempt_id     = $correlation_payment_attempt_id;
		$this->correlation_dispute_id             = $correlation_dispute_id;
		$this->correlation_refund_id              = $correlation_refund_id;
		$this->correlation_network_transaction_id = $correlation_network_transaction_id;
	}
}
