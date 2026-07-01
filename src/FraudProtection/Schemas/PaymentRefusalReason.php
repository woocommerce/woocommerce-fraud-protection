<?php
/**
 * PaymentRefusalReason enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized cause of a declined or blocked payment, part of the `reason` vocabulary of a report context.
 *
 * Public surface: callers reference these when building a payment context via
 * `ReportContextData::from_array()`. These only ever accompany a refusal (a declined or blocked
 * result), never a successful capture; a dispute draws from `DisputeReason` instead, and a refund
 * carries no reason.
 */
enum PaymentRefusalReason: string {

	case LostOrStolen           = 'lost_or_stolen';
	case SuspectedFraud         = 'suspected_fraud';
	case RestrictedCard         = 'restricted_card';
	case SecurityViolation      = 'security_violation';
	case IncorrectCvc           = 'incorrect_cvc';
	case IncorrectAvs           = 'incorrect_avs';
	case IncorrectNumber        = 'incorrect_number';
	case IncorrectExpiry        = 'incorrect_expiry';
	case DoNotHonor             = 'do_not_honor';
	case GenericDecline         = 'generic_decline';
	case Compliance             = 'compliance';
	case CardNotSupported       = 'card_not_supported';
	case UnsupportedCurrency    = 'unsupported_currency';
	case ExpiredCard            = 'expired_card';
	case InvalidAccount         = 'invalid_account';
	case NotPermitted           = 'not_permitted';
	case InsufficientFunds      = 'insufficient_funds';
	case LimitExceeded          = 'limit_exceeded';
	case VelocityExceeded       = 'velocity_exceeded';
	case AuthenticationRequired = 'authentication_required';
	case IssuerUnavailable      = 'issuer_unavailable';
	case ProcessingError        = 'processing_error';
	case TestMode               = 'test_mode';
	case Duplicate              = 'duplicate';
	case RequestError           = 'request_error';
	case Operational            = 'operational';
}
