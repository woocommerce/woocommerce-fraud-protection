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

	/**
	 * The id of the existing live rule with the same conditions, so callers
	 * (e.g. the UI) can point the user back to it.
	 *
	 * @var int
	 */
	public readonly int $existing_rule_id;

	/**
	 * Constructor.
	 *
	 * @param string $message          The exception message.
	 * @param int    $existing_rule_id The id of the existing live rule with the same conditions.
	 */
	public function __construct( string $message, int $existing_rule_id ) {
		parent::__construct( $message );
		$this->existing_rule_id = $existing_rule_id;
	}
}
