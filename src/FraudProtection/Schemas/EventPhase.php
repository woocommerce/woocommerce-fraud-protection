<?php
/**
 * EventPhase enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Event phase, the `type` vocabulary of a report context.
 *
 * Callers reference these when building a context via `ReportContextData::from_array()`.
 */
enum EventPhase: string {

	/** A charge attempt and its lifecycle. */
	case Payment = 'payment';

	/** A chargeback, inquiry, or dispute resolution. */
	case Dispute = 'dispute';

	/** A merchant refund or return. */
	case Refund = 'refund';
}
