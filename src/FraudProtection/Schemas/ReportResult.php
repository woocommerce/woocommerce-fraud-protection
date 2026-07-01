<?php
/**
 * ReportResult enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized payment-outcome results, the `result` vocabulary of a report context.
 *
 * Public surface: callers reference these when building a context via
 * `ReportContextData::from_array()`. Case names carry the event type they belong to, and the
 * per-type sets below define which results are valid for which `type`.
 */
enum ReportResult: string {

	// Payment results.
	case PaymentCaptured       = 'captured';
	case PaymentAuthorized     = 'authorized';
	case PaymentPending        = 'pending';
	case PaymentDeclined       = 'declined';
	case PaymentBlocked        = 'blocked';
	case PaymentReviewPending  = 'review_pending';
	case PaymentReviewApproved = 'review_approved';
	case PaymentReviewRejected = 'review_rejected';
	case PaymentReviewExpired  = 'review_expired';
	case PaymentVoided         = 'voided';
	case PaymentCanceled       = 'canceled';

	// Dispute results.
	case DisputeInquiry   = 'inquiry';
	case DisputeOpen      = 'open';
	case DisputeWon       = 'won';
	case DisputeLost      = 'lost';
	case DisputeAccepted  = 'accepted';
	case DisputeWithdrawn = 'withdrawn';
	case DisputePrevented = 'prevented';

	// Refund results.
	case Refunded          = 'refunded';
	case PartiallyRefunded = 'partially_refunded';

	/**
	 * Results valid for a payment event.
	 *
	 * @var array<int, self>
	 */
	public const PAYMENT_RESULTS = array(
		self::PaymentCaptured,
		self::PaymentAuthorized,
		self::PaymentPending,
		self::PaymentDeclined,
		self::PaymentBlocked,
		self::PaymentReviewPending,
		self::PaymentReviewApproved,
		self::PaymentReviewRejected,
		self::PaymentReviewExpired,
		self::PaymentVoided,
		self::PaymentCanceled,
	);

	/**
	 * Results valid for a dispute event.
	 *
	 * @var array<int, self>
	 */
	public const DISPUTE_RESULTS = array(
		self::DisputeInquiry,
		self::DisputeOpen,
		self::DisputeWon,
		self::DisputeLost,
		self::DisputeAccepted,
		self::DisputeWithdrawn,
		self::DisputePrevented,
	);

	/**
	 * Results valid for a refund event.
	 *
	 * @var array<int, self>
	 */
	public const REFUND_RESULTS = array(
		self::Refunded,
		self::PartiallyRefunded,
	);
}
