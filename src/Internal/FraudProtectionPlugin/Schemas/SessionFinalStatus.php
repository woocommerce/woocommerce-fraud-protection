<?php
/**
 * SessionFinalStatus enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Effective outcome of a recorded fraud-protection session event, after any
 * overrides: what actually happened, as opposed to what Blackbox said
 * (the raw verdict).
 *
 * Stored as the backing string in the `final_status` column of the sessions
 * table. The vocabulary is deliberately open-ended: the future challenge flow
 * will add outcome cases without requiring a schema change.
 */
enum SessionFinalStatus: string {

	/** The session was allowed (the verdict was allow and nothing overrode it). */
	case Allowed = 'allowed';

	/** The session was blocked (the verdict was enforced). */
	case Blocked = 'blocked';

	/** A block verdict was overridden by a merchant allow-list rule. */
	case AllowedByAllowlist = 'allowed_by_allowlist';

	/** A non-allow verdict was not enforced (learning mode, filter override, or non-actionable verdict). */
	case NotEnforced = 'not_enforced';
}
