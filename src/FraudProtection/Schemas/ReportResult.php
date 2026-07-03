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
 * `ReportContextData::from_array()`. Case names carry the event phase they belong to; `for_phase()`
 * returns the results valid for a given phase.
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
	 * The result cases valid for a given event phase.
	 *
	 * @param EventPhase $phase Event phase.
	 * @return array<int, self>
	 */
	public static function for_phase( EventPhase $phase ): array {
		return match ( $phase ) {
			EventPhase::Payment => array(
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
			),
			EventPhase::Dispute => array(
				self::DisputeInquiry,
				self::DisputeOpen,
				self::DisputeWon,
				self::DisputeLost,
				self::DisputeAccepted,
				self::DisputeWithdrawn,
				self::DisputePrevented,
			),
			EventPhase::Refund => array(
				self::Refunded,
				self::PartiallyRefunded,
			),
		};
	}
}
