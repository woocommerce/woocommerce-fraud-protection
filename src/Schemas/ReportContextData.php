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
 * Built from gateway data and sent as the `context` object on a report. The plugin
 * sends normalized facts only — raw gateway codes stay on the Woo order, and any
 * interpretation of those facts happens server-side. `from_array()` returns null only
 * when `type` or `result` cannot be mapped, so a held instance is always reportable;
 * `reason` and the optional blocks are best-effort.
 *
 * Unlike the other schema DTOs, this is public surface: it is the context type accepted
 * by `wc_fraud_protection_report()`, so integrations construct it via `from_array()`. It
 * is therefore not marked `@internal`.
 */
class ReportContextData {

	use SanitizesScalarFields;

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
		self::TYPE_PAYMENT => ReportResult::PAYMENT_RESULTS,
		self::TYPE_DISPUTE => ReportResult::DISPUTE_RESULTS,
		self::TYPE_REFUND  => ReportResult::REFUND_RESULTS,
	);

	/**
	 * Liability shift: authenticated, liability moved to the issuer.
	 */
	public const LIABILITY_SHIFTED = 'shifted';

	/**
	 * Liability shift: 3DS attempted, issuer did not fully authenticate.
	 */
	public const LIABILITY_ATTEMPTED = 'attempted';

	/**
	 * Liability shift: no 3DS, or authentication failed.
	 */
	public const LIABILITY_NOT_SHIFTED = 'not_shifted';

	/**
	 * Valid liability-shift values.
	 *
	 * @var array<int, string>
	 */
	public const VALID_LIABILITY_SHIFTS = array(
		self::LIABILITY_SHIFTED,
		self::LIABILITY_ATTEMPTED,
		self::LIABILITY_NOT_SHIFTED,
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
	 * Normalized cause, or null when unmapped or not applicable.
	 *
	 * @var ?string
	 */
	private ?string $reason;

	/**
	 * 3DS/SCA liability outcome, or null when undeterminable.
	 *
	 * @var ?string
	 */
	private ?string $liability_shift;

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
	 * Normalized payment instrument, or null when none is known.
	 *
	 * Reuses the verify-side shape so Blackbox parses one instrument schema.
	 *
	 * @var ?PaymentInstrumentData
	 */
	private ?PaymentInstrumentData $instrument;

	/**
	 * Constructor.
	 *
	 * @param string                 $type                               Event phase.
	 * @param string                 $result                             Outcome within the phase.
	 * @param ?string                $reason                             Normalized reason.
	 * @param ?string                $liability_shift                    3DS/SCA liability outcome.
	 * @param ?int                   $amount_minor_units                 Amount in minor units.
	 * @param ?string                $amount_currency                    ISO-4217 currency.
	 * @param string                 $occurred_at                        UTC ISO 8601 event time.
	 * @param string                 $gateway                            Woo gateway ID.
	 * @param ?int                   $correlation_order_id               Woo order ID.
	 * @param ?string                $correlation_transaction_id         Provider transaction ID.
	 * @param ?string                $correlation_payment_attempt_id     Provider payment-attempt ID.
	 * @param ?string                $correlation_dispute_id             Provider dispute ID.
	 * @param ?string                $correlation_refund_id              Provider refund ID.
	 * @param ?string                $correlation_network_transaction_id Card-network transaction reference.
	 * @param ?PaymentInstrumentData $instrument                         Normalized payment instrument.
	 */
	private function __construct(
		string $type,
		string $result,
		?string $reason,
		?string $liability_shift,
		?int $amount_minor_units,
		?string $amount_currency,
		string $occurred_at,
		string $gateway,
		?int $correlation_order_id,
		?string $correlation_transaction_id,
		?string $correlation_payment_attempt_id,
		?string $correlation_dispute_id,
		?string $correlation_refund_id,
		?string $correlation_network_transaction_id,
		?PaymentInstrumentData $instrument
	) {
		$this->type                               = $type;
		$this->result                             = $result;
		$this->reason                             = $reason;
		$this->liability_shift                    = $liability_shift;
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
		$this->instrument                         = $instrument;
	}

	/**
	 * Build a context from the JSON-shaped array.
	 *
	 * Sanitizes enums and resolves `reason`. Returns null — and logs — only when `type`
	 * or `result` cannot be mapped. `reason` is optional everywhere (sent when mapped,
	 * omitted otherwise); a non-empty value that fails to map is logged. `instrument`
	 * and `liability_shift` are optional context.
	 *
	 * @param array<string, mixed> $data Context fields in the API shape.
	 * @return ?self The context, or null when it cannot be reported.
	 */
	public static function from_array( array $data ): ?self {
		// type and result are skip-gates: an unmappable one drops the whole report, which is an
		// error worth forwarding (sanitize_enum already logs the bad field, but stays silent
		// when the field is simply absent).
		$type = self::sanitize_enum( $data, 'type', self::VALID_TYPES );
		if ( null === $type ) {
			FraudProtectionController::log(
				'error',
				'Skipping report: context type is missing or unmappable.',
				array(),
				true
			);
			return null;
		}

		$result = self::sanitize_enum( $data, 'result', self::RESULTS_BY_TYPE[ $type ] );
		if ( null === $result ) {
			FraudProtectionController::log(
				'error',
				'Skipping report: context result is missing or unmappable for the given type.',
				array(),
				true
			);
			return null;
		}

		return new self(
			$type,
			$result,
			self::resolve_reason( $data, $type ),
			self::sanitize_enum( $data, 'liability_shift', self::VALID_LIABILITY_SHIFTS ),
			self::sanitize_non_negative_int( $data, 'amount_minor_units' ),
			self::sanitize_currency_code( $data, 'amount_currency' ),
			self::normalize_occurred_at( $data, 'occurred_at' ),
			self::sanitize_string_field( $data, 'gateway' ) ?? '',
			self::sanitize_positive_int( $data, 'correlation_order_id' ),
			self::sanitize_string_field( $data, 'correlation_transaction_id' ),
			self::sanitize_string_field( $data, 'correlation_payment_attempt_id' ),
			self::sanitize_string_field( $data, 'correlation_dispute_id' ),
			self::sanitize_string_field( $data, 'correlation_refund_id' ),
			self::sanitize_string_field( $data, 'correlation_network_transaction_id' ),
			self::sanitize_instrument( $data, 'instrument' )
		);
	}

	/**
	 * Return a copy with order-derived fields filled in where missing.
	 *
	 * Only fills an empty gateway and a missing correlation order ID, so caller-supplied
	 * values always win.
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
	 * Serialize to the API `context` shape: a fixed, flat field set with null for any value
	 * that did not resolve — the verify-side convention, so Blackbox parses one stable shape.
	 *
	 * The property names are the wire keys, so the body derives from the object's own
	 * properties; only `schema_version` (a constant) and `instrument` (nested) are special-cased.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$context               = array( 'schema_version' => self::SCHEMA_VERSION ) + get_object_vars( $this );
		$context['instrument'] = null !== $this->instrument ? $this->instrument->to_array() : null;

		return $context;
	}

	/**
	 * Resolve the normalized reason for the event.
	 *
	 * Refunds carry no reason. Payments and disputes map the caller-supplied value against
	 * the allowed set and return null when it does not map; there is no catch-all fallback,
	 * and an unmapped value is omitted rather than skipping the report.
	 *
	 * @param array<string, mixed> $data Raw fields.
	 * @param string               $type Event phase.
	 * @return ?string Normalized reason, or null when unmapped or not applicable.
	 */
	private static function resolve_reason( array $data, string $type ): ?string {
		if ( self::TYPE_REFUND === $type ) {
			return null;
		}

		$allowed = self::TYPE_DISPUTE === $type ? ReportReason::DISPUTE_REASONS : ReportReason::PAYMENT_REFUSAL_REASONS;

		return self::sanitize_enum( $data, 'reason', $allowed );
	}

	/**
	 * Normalize a time field to UTC ISO 8601, falling back to now.
	 *
	 * @param array<string, mixed> $data  Raw fields.
	 * @param string               $field Field name to read.
	 * @return string UTC ISO 8601 timestamp.
	 */
	private static function normalize_occurred_at( array $data, string $field ): string {
		$raw = $data[ $field ] ?? null;
		// Only trust ISO-8601-style input; reject relative ("now") and "@unix" forms.
		$candidate = is_string( $raw ) ? trim( $raw ) : '';
		if ( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}([T ].+)?$/', $candidate ) ) {
			try {
				$parsed = new \DateTimeImmutable( $candidate );
				return $parsed->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
			} catch ( \Exception $e ) {
				// Fall through to now.
				unset( $e );
			}
		}

		// A provided value that could not be normalized falls back to now; log so it surfaces.
		if ( null !== $raw ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Report context field "%s" was not a valid UTC ISO 8601 time; using the current time.', $field )
			);
		}

		return gmdate( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Build the optional payment instrument from a field, or null when none is usable.
	 *
	 * An all-null instrument (only unrecognized keys, or an empty array) collapses to null, so
	 * we send `instrument: null` rather than a block of nulls.
	 *
	 * @param array<string, mixed> $data  Raw fields.
	 * @param string               $field Field name to read.
	 * @return ?PaymentInstrumentData
	 */
	private static function sanitize_instrument( array $data, string $field ): ?PaymentInstrumentData {
		$raw = $data[ $field ] ?? null;
		if ( null === $raw ) {
			return null;
		}

		if ( ! is_array( $raw ) ) {
			self::log_dropped_field( $field, 'expected an array' );
			return null;
		}

		$instrument = PaymentInstrumentData::from_array( $raw );
		foreach ( $instrument->to_array() as $value ) {
			if ( null !== $value ) {
				return $instrument;
			}
		}

		return null;
	}
}
