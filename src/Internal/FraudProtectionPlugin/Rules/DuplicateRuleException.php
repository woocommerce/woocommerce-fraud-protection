<?php
/**
 * DuplicateRuleException class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

defined( 'ABSPATH' ) || exit;

/**
 * Thrown when a rule write would produce a live rule with the same
 * normalized conditions as an existing one (same condition hash).
 */
class DuplicateRuleException extends \RuntimeException {
}
