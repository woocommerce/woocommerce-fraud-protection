<?php
/**
 * ClearanceStatus enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Fraud-protection clearance state of a WooCommerce session.
 *
 * Persisted in the WC session and managed by `SessionClearanceManager`.
 */
enum ClearanceStatus: string {

	/** Pending clearance (challenge required). */
	case Pending = 'pending';

	/** Allowed. */
	case Allowed = 'allowed';

	/** Blocked. */
	case Blocked = 'blocked';
}
