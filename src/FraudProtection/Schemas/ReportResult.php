<?php
/**
 * ReportResult class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized payment-outcome results, the `result` vocabulary of a report context.
 *
 * Public surface: callers reference these when building a context via
 * `ReportContextData::from_array()`. Constant names carry the event type they belong to, and
 * the per-type sets below define which results are valid for which `type`.
 */
final class ReportResult {

	// Payment results.
	public const PAYMENT_CAPTURED        = 'captured';
	public const PAYMENT_AUTHORIZED      = 'authorized';
	public const PAYMENT_PENDING         = 'pending';
	public const PAYMENT_DECLINED        = 'declined';
	public const PAYMENT_BLOCKED         = 'blocked';
	public const PAYMENT_REVIEW_PENDING  = 'review_pending';
	public const PAYMENT_REVIEW_APPROVED = 'review_approved';
	public const PAYMENT_REVIEW_REJECTED = 'review_rejected';
	public const PAYMENT_REVIEW_EXPIRED  = 'review_expired';
	public const PAYMENT_VOIDED          = 'voided';
	public const PAYMENT_CANCELED        = 'canceled';

	// Dispute results.
	public const DISPUTE_INQUIRY   = 'inquiry';
	public const DISPUTE_OPEN      = 'open';
	public const DISPUTE_WON       = 'won';
	public const DISPUTE_LOST      = 'lost';
	public const DISPUTE_ACCEPTED  = 'accepted';
	public const DISPUTE_WITHDRAWN = 'withdrawn';
	public const DISPUTE_PREVENTED = 'prevented';

	// Refund results.
	public const REFUNDED           = 'refunded';
	public const PARTIALLY_REFUNDED = 'partially_refunded';

	/**
	 * Results valid for a payment event.
	 *
	 * @var array<int, string>
	 */
	public const PAYMENT_RESULTS = array(
		self::PAYMENT_CAPTURED,
		self::PAYMENT_AUTHORIZED,
		self::PAYMENT_PENDING,
		self::PAYMENT_DECLINED,
		self::PAYMENT_BLOCKED,
		self::PAYMENT_REVIEW_PENDING,
		self::PAYMENT_REVIEW_APPROVED,
		self::PAYMENT_REVIEW_REJECTED,
		self::PAYMENT_REVIEW_EXPIRED,
		self::PAYMENT_VOIDED,
		self::PAYMENT_CANCELED,
	);

	/**
	 * Results valid for a dispute event.
	 *
	 * @var array<int, string>
	 */
	public const DISPUTE_RESULTS = array(
		self::DISPUTE_INQUIRY,
		self::DISPUTE_OPEN,
		self::DISPUTE_WON,
		self::DISPUTE_LOST,
		self::DISPUTE_ACCEPTED,
		self::DISPUTE_WITHDRAWN,
		self::DISPUTE_PREVENTED,
	);

	/**
	 * Results valid for a refund event.
	 *
	 * @var array<int, string>
	 */
	public const REFUND_RESULTS = array(
		self::REFUNDED,
		self::PARTIALLY_REFUNDED,
	);
}
