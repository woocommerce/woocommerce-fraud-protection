<?php
/**
 * SessionTrigger enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Mechanism that produced a recorded fraud-protection decision.
 *
 * Stored as the backing string in the `trigger_type` column of the sessions
 * table (the column is not named `trigger` because that is a MySQL reserved
 * word).
 */
enum SessionTrigger: string {

	/** The decision came from the Blackbox verify API. */
	case Blackbox = 'blackbox';
}
