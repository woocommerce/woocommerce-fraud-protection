<?php
/**
 * SessionFinalStatus enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Outcome applied to a recorded fraud-protection session. This can differ from
 * the received decision. For example, a block decision that is not enforced
 * has `decision = block` and `final_status = allowed`. This vocabulary does not
 * record why the outcomes differ.
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
