<?php
/**
 * ConditionOperatorRegistry class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\Operators\EqualsOperator;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the `operator` key of a rule condition to its operator instance.
 *
 * The operator set is a code-level registry (not filterable): new operators
 * ship as new classes in the `Operators/` subnamespace added to the map,
 * with no database schema change. There is no operator interface — the
 * hardcoded map is the only way a class gets here, so operators are trusted
 * to have the expected shape: a public `matches( $rule_value, $context_value ): bool`
 * method, receiving values already normalized by {@see RuleConditions} and
 * treating any value shape it does not support as non-matching.
 *
 * An unknown operator name resolves to null, which evaluation treats as
 * non-matching (fail-open).
 */
class ConditionOperatorRegistry {

	/**
	 * Operator name to implementation class map.
	 *
	 * @var array<string, class-string>
	 */
	private const OPERATORS = array(
		'equals' => EqualsOperator::class,
	);

	/**
	 * Get the known operator names.
	 *
	 * This is the single source of truth for which operators exist: the
	 * write-time condition validator ({@see RuleConditions}) accepts exactly
	 * the operators this registry can resolve at evaluation time.
	 *
	 * @return string[] The operator names.
	 */
	public static function get_operator_names(): array {
		return array_keys( self::OPERATORS );
	}

	/**
	 * Get the operator instance for an operator name.
	 *
	 * Operators are resolved from the WooCommerce container, which returns
	 * shared instances, so no local caching is needed.
	 *
	 * @param string $name The operator name from the condition document.
	 * @return ?object The operator, or null when the name is unknown.
	 */
	public function get_operator( string $name ): ?object {
		$class = self::OPERATORS[ $name ] ?? null;

		return is_null( $class ) ? null : wc_get_container()->get( $class );
	}
}
