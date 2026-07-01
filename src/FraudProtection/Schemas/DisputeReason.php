<?php
/**
 * DisputeReason enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Normalized cause of a dispute event, part of the `reason` vocabulary of a report context.
 *
 * Public surface: callers reference these when building a dispute context via
 * `ReportContextData::from_array()`. A dispute draws its reason from this set; a declined or
 * blocked payment draws from `PaymentRefusalReason` instead, and a refund carries no reason.
 */
enum DisputeReason: string {

	case Fraud                 = 'fraud';
	case Unrecognized          = 'unrecognized';
	case SubscriptionCanceled  = 'subscription_canceled';
	case CanceledOrReturned    = 'canceled_or_returned';
	case ProductNotReceived    = 'product_not_received';
	case ProductNotAsDescribed = 'product_not_as_described';
	case CreditNotProcessed    = 'credit_not_processed';
	case Duplicate             = 'duplicate';
	case Bank                  = 'bank';
	case Other                 = 'other';
}
