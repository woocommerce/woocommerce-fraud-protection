<?php
/**
 * SessionFinalStatus enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome actually applied to a recorded fraud-protection session, as opposed
 * to what Blackbox said (the received decision). The *reason* for an outcome
 * is not part of this vocabulary: a block suppressed by the automatic-protection setting or a
 * filter override reads as `decision = block` paired with
 * `final_status = allowed`.
 *
 * Stored as the backing string in the `final_status` column of the sessions
 * table. The vocabulary is deliberately open-ended: the future challenge flow
 * will add outcome cases without requiring a schema change.
 */
enum SessionFinalStatus: string {

	/** The session was allowed. */
	case Allowed = 'allowed';

	/** The session was blocked. */
	case Blocked = 'blocked';
}
