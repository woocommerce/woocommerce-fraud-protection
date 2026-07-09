<?php
/**
 * SessionTrigger enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Mechanism that produced a recorded fraud-protection verdict.
 *
 * Stored as the backing string in the `trigger_type` column of the sessions
 * table (the column is not named `trigger` because that is a MySQL reserved
 * word).
 */
enum SessionTrigger: string {

	/** The verdict came from the Blackbox verify API. */
	case Blackbox = 'blackbox';

	/** The verdict was produced by a merchant negative-list rule. */
	case NegativeList = 'negative_list';
}
