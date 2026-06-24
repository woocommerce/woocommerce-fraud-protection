<?php
/**
 * ReportReason class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized cause of a report event, the `reason` vocabulary of a report context.
 *
 * Public surface: callers reference these when building a context via
 * `ReportContextData::from_array()`. Constant names carry the event they belong to: a dispute
 * draws from `DISPUTE_REASONS`, a declined or blocked payment from `PAYMENT_REFUSAL_REASONS`
 * (these only ever accompany a refusal, never a successful capture); refunds carry no reason.
 */
final class ReportReason {

	// Dispute reasons.
	public const DISPUTE_FRAUD                    = 'fraud';
	public const DISPUTE_UNRECOGNIZED             = 'unrecognized';
	public const DISPUTE_SUBSCRIPTION_CANCELED    = 'subscription_canceled';
	public const DISPUTE_CANCELED_OR_RETURNED     = 'canceled_or_returned';
	public const DISPUTE_PRODUCT_NOT_RECEIVED     = 'product_not_received';
	public const DISPUTE_PRODUCT_NOT_AS_DESCRIBED = 'product_not_as_described';
	public const DISPUTE_CREDIT_NOT_PROCESSED     = 'credit_not_processed';
	public const DISPUTE_DUPLICATE                = 'duplicate';
	public const DISPUTE_BANK                     = 'bank';
	public const DISPUTE_OTHER                    = 'other';

	// Payment refusal reasons (declined and blocked payments).
	public const PAYMENT_REFUSAL_LOST_OR_STOLEN          = 'lost_or_stolen';
	public const PAYMENT_REFUSAL_SUSPECTED_FRAUD         = 'suspected_fraud';
	public const PAYMENT_REFUSAL_RESTRICTED_CARD         = 'restricted_card';
	public const PAYMENT_REFUSAL_SECURITY_VIOLATION      = 'security_violation';
	public const PAYMENT_REFUSAL_INCORRECT_CVC           = 'incorrect_cvc';
	public const PAYMENT_REFUSAL_INCORRECT_AVS           = 'incorrect_avs';
	public const PAYMENT_REFUSAL_INCORRECT_NUMBER        = 'incorrect_number';
	public const PAYMENT_REFUSAL_INCORRECT_EXPIRY        = 'incorrect_expiry';
	public const PAYMENT_REFUSAL_DO_NOT_HONOR            = 'do_not_honor';
	public const PAYMENT_REFUSAL_GENERIC_DECLINE         = 'generic_decline';
	public const PAYMENT_REFUSAL_COMPLIANCE              = 'compliance';
	public const PAYMENT_REFUSAL_CARD_NOT_SUPPORTED      = 'card_not_supported';
	public const PAYMENT_REFUSAL_UNSUPPORTED_CURRENCY    = 'unsupported_currency';
	public const PAYMENT_REFUSAL_EXPIRED_CARD            = 'expired_card';
	public const PAYMENT_REFUSAL_INVALID_ACCOUNT         = 'invalid_account';
	public const PAYMENT_REFUSAL_NOT_PERMITTED           = 'not_permitted';
	public const PAYMENT_REFUSAL_INSUFFICIENT_FUNDS      = 'insufficient_funds';
	public const PAYMENT_REFUSAL_LIMIT_EXCEEDED          = 'limit_exceeded';
	public const PAYMENT_REFUSAL_VELOCITY_EXCEEDED       = 'velocity_exceeded';
	public const PAYMENT_REFUSAL_AUTHENTICATION_REQUIRED = 'authentication_required';
	public const PAYMENT_REFUSAL_ISSUER_UNAVAILABLE      = 'issuer_unavailable';
	public const PAYMENT_REFUSAL_PROCESSING_ERROR        = 'processing_error';
	public const PAYMENT_REFUSAL_TEST_MODE               = 'test_mode';
	public const PAYMENT_REFUSAL_DUPLICATE               = 'duplicate';
	public const PAYMENT_REFUSAL_REQUEST_ERROR           = 'request_error';
	public const PAYMENT_REFUSAL_OPERATIONAL             = 'operational';

	/**
	 * Reasons valid for a dispute event.
	 *
	 * @var array<int, string>
	 */
	public const DISPUTE_REASONS = array(
		self::DISPUTE_FRAUD,
		self::DISPUTE_UNRECOGNIZED,
		self::DISPUTE_SUBSCRIPTION_CANCELED,
		self::DISPUTE_CANCELED_OR_RETURNED,
		self::DISPUTE_PRODUCT_NOT_RECEIVED,
		self::DISPUTE_PRODUCT_NOT_AS_DESCRIBED,
		self::DISPUTE_CREDIT_NOT_PROCESSED,
		self::DISPUTE_DUPLICATE,
		self::DISPUTE_BANK,
		self::DISPUTE_OTHER,
	);

	/**
	 * Reasons valid for a declined or blocked payment event.
	 *
	 * @var array<int, string>
	 */
	public const PAYMENT_REFUSAL_REASONS = array(
		self::PAYMENT_REFUSAL_LOST_OR_STOLEN,
		self::PAYMENT_REFUSAL_SUSPECTED_FRAUD,
		self::PAYMENT_REFUSAL_RESTRICTED_CARD,
		self::PAYMENT_REFUSAL_SECURITY_VIOLATION,
		self::PAYMENT_REFUSAL_INCORRECT_CVC,
		self::PAYMENT_REFUSAL_INCORRECT_AVS,
		self::PAYMENT_REFUSAL_INCORRECT_NUMBER,
		self::PAYMENT_REFUSAL_INCORRECT_EXPIRY,
		self::PAYMENT_REFUSAL_DO_NOT_HONOR,
		self::PAYMENT_REFUSAL_GENERIC_DECLINE,
		self::PAYMENT_REFUSAL_COMPLIANCE,
		self::PAYMENT_REFUSAL_CARD_NOT_SUPPORTED,
		self::PAYMENT_REFUSAL_UNSUPPORTED_CURRENCY,
		self::PAYMENT_REFUSAL_EXPIRED_CARD,
		self::PAYMENT_REFUSAL_INVALID_ACCOUNT,
		self::PAYMENT_REFUSAL_NOT_PERMITTED,
		self::PAYMENT_REFUSAL_INSUFFICIENT_FUNDS,
		self::PAYMENT_REFUSAL_LIMIT_EXCEEDED,
		self::PAYMENT_REFUSAL_VELOCITY_EXCEEDED,
		self::PAYMENT_REFUSAL_AUTHENTICATION_REQUIRED,
		self::PAYMENT_REFUSAL_ISSUER_UNAVAILABLE,
		self::PAYMENT_REFUSAL_PROCESSING_ERROR,
		self::PAYMENT_REFUSAL_TEST_MODE,
		self::PAYMENT_REFUSAL_DUPLICATE,
		self::PAYMENT_REFUSAL_REQUEST_ERROR,
		self::PAYMENT_REFUSAL_OPERATIONAL,
	);
}
