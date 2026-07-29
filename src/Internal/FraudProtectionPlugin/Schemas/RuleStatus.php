<?php
/**
 * RuleStatus enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

defined( 'ABSPATH' ) || exit;

/**
 * Lifecycle status of a merchant rule.
 *
 * Stored as the backing string in the `status` column of the rules table.
 */
enum RuleStatus: string {

	/** The rule is evaluated and enforced. */
	case Active = 'active';

	/** The rule is kept but not evaluated. */
	case Disabled = 'disabled';

	/** The rule is soft-deleted: excluded from evaluation and hidden from the UI. */
	case Deleted = 'deleted';
}
