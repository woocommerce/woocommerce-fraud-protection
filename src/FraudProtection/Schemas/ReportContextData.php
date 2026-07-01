<?php
/**
 * ReportContextData class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SanitizesScalarFields;

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
 * by `FraudProtectionReporter::report()`, so integrations construct it via `from_array()`. It
 * is therefore not marked `@internal`.
 */
class ReportContextData {

	use SanitizesScalarFields;

	/**
	 * Version for this context schema. Bump when the shape changes.
	 */
	public const SCHEMA_VERSION = 1;

	/**
	 * Event phase, as the backing string of an `EventPhase` case.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Outcome within the phase (validated against ReportResult::for_phase()).
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
	 * Best known event time. Rendered to UTC ISO 8601 at serialization. Always set.
	 *
	 * @var \DateTimeImmutable
	 */
	private \DateTimeImmutable $occurred_at;

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
	 * @param \DateTimeImmutable     $occurred_at                        Best known event time.
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
		\DateTimeImmutable $occurred_at,
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
	 * Build a context from an array of report field values.
	 *
	 * Most fields are scalars; `occurred_at` is a `DateTimeInterface` and `instrument` a nested
	 * array. Sanitizes enums and resolves `reason`. Returns null — and logs — only when `type`
	 * or `result` cannot be mapped. `reason` is optional everywhere (sent when mapped, omitted
	 * otherwise); a non-empty value that fails to map is logged. `instrument` and `liability_shift`
	 * are optional context.
	 *
	 * @param array<string, mixed> $data Report field values keyed by field name.
	 * @return ?self The context, or null when it cannot be reported.
	 */
	public static function from_array( array $data ): ?self {
		// type and result are skip-gates: an unmappable one drops the whole report, which is an
		// error worth forwarding (sanitize_enum already logs the bad field, but stays silent
		// when the field is simply absent).
		$phase = self::sanitize_enum( $data, 'type', EventPhase::cases() );
		if ( is_null( $phase ) ) {
			FraudProtectionController::log(
				'error',
				'Skipping report: context type is missing or unmappable.',
				array(),
				true
			);
			return null;
		}

		$result = self::sanitize_enum( $data, 'result', ReportResult::for_phase( $phase ) );
		if ( is_null( $result ) ) {
			FraudProtectionController::log(
				'error',
				'Skipping report: context result is missing or unmappable for the given type.',
				array(),
				true
			);
			return null;
		}

		return new self(
			$phase->value,
			$result->value,
			self::resolve_reason( $data, $phase, $result ),
			self::sanitize_enum( $data, 'liability_shift', LiabilityShift::cases() )?->value,
			self::sanitize_non_negative_int( $data, 'amount_minor_units' ),
			self::sanitize_string_field( $data, 'amount_currency' ),
			self::sanitize_date( $data, 'occurred_at' ),
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
	 * The property names are the wire keys, so the body derives from the object's own properties;
	 * `schema_version` (a constant), `occurred_at` (a DateTime rendered to UTC ISO 8601) and
	 * `instrument` (nested) are special-cased.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$context                = array( 'schema_version' => self::SCHEMA_VERSION ) + get_object_vars( $this );
		$context['occurred_at'] = gmdate( \DateTimeInterface::RFC3339, $this->occurred_at->getTimestamp() );
		$context['instrument']  = null !== $this->instrument ? $this->instrument->to_array() : null;

		return $context;
	}

	/**
	 * Resolve the normalized reason for the event.
	 *
	 * Refunds carry no reason. A payment reason is a decline/refusal code, so it is resolved only
	 * for a declined or blocked result — a captured, voided, or review-resolved payment carries no
	 * reason. Disputes map their reason for any result. The caller-supplied value is matched against
	 * the allowed set and dropped to null when it does not map; there is no catch-all fallback, and
	 * an unmapped value is omitted rather than skipping the report.
	 *
	 * @param array<string, mixed> $data   Raw fields.
	 * @param EventPhase           $phase  Event phase.
	 * @param ReportResult         $result Outcome within the phase; gates payment reasons to refusals.
	 * @return ?string Normalized reason, or null when unmapped or not applicable.
	 */
	private static function resolve_reason( array $data, EventPhase $phase, ReportResult $result ): ?string {
		if ( EventPhase::Refund === $phase ) {
			return null;
		}

		if ( EventPhase::Payment === $phase ) {
			$refusals = array( ReportResult::PaymentDeclined, ReportResult::PaymentBlocked );
			if ( ! in_array( $result, $refusals, true ) ) {
				return null;
			}

			return self::sanitize_enum( $data, 'reason', PaymentRefusalReason::cases() )?->value;
		}

		return self::sanitize_enum( $data, 'reason', DisputeReason::cases() )?->value;
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
