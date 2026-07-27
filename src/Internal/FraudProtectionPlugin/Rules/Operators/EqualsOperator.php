<?php
/**
 * EqualsOperator class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\Operators;

defined( 'ABSPATH' ) || exit;

/**
 * The `equals` operator: strict equality between two non-empty strings.
 *
 * An empty or non-string value on either side is non-matching: an empty
 * context value means the field was missing from the evaluation context,
 * which the design mandates to evaluate as a non-match.
 */
class EqualsOperator {

	/**
	 * Whether the context value is the same non-empty string as the rule value.
	 * Assumes values normalized in both sides. Untyped on purpose to allow
	 * for future extensibility.
	 *
	 * @param mixed $rule_value    The condition value from the rule document.
	 * @param mixed $context_value The value from the evaluation context.
	 * @return bool True when the condition matches.
	 */
	public function matches( $rule_value, $context_value ): bool {
		if ( ! is_string( $rule_value ) || '' === $rule_value || ! is_string( $context_value ) || '' === $context_value ) {
			return false;
		}

		return $rule_value === $context_value;
	}
}
