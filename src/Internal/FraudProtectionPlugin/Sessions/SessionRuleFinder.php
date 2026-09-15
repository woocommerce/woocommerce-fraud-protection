<?php
/**
 * SessionRuleFinder class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\ConditionOperatorRegistry;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\Operators\EqualsOperator;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleConditions;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Finds the active merchant rules that currently target a recorded event's email and IP.
 *
 * The sessions list offers rule actions per value: "block this email" when
 * no rule targets the email yet, "edit email rule" when one does. Only a
 * single `equals` condition counts as targeting a value, matching what rule
 * creation from a session produces. When several active rules target the
 * same value, the first one in evaluation order wins.
 */
class SessionRuleFinder {

	/**
	 * Rule store instance.
	 *
	 * @var RuleStore
	 */
	private RuleStore $rule_store;

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Condition operator registry instance.
	 *
	 * @var ConditionOperatorRegistry
	 */
	private ConditionOperatorRegistry $operator_registry;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param RuleStore                 $rule_store        The rule store instance.
	 * @param SchemaManager             $schema_manager    The schema manager instance.
	 * @param ConditionOperatorRegistry $operator_registry The condition operator registry instance.
	 */
	final public function init( RuleStore $rule_store, SchemaManager $schema_manager, ConditionOperatorRegistry $operator_registry ): void {
		$this->rule_store        = $rule_store;
		$this->schema_manager    = $schema_manager;
		$this->operator_registry = $operator_registry;
	}

	/**
	 * Find the active rules targeting each event's email and IP.
	 *
	 * @param array<int, array<string, mixed>> $events Event rows with `id`, `email` and `ip` keys.
	 * @return array<int, array{email: ?Rule, ip: ?Rule}> The matching rules per field, keyed by event id.
	 */
	public function find_for_events( array $events ): array {
		$index   = $this->build_index();
		$matches = array();

		foreach ( $events as $event ) {
			$matches[ (int) ( $event['id'] ?? 0 ) ] = array(
				RuleConditions::FIELD_EMAIL => $this->lookup( $index, RuleConditions::FIELD_EMAIL, $event['email'] ?? null ),
				RuleConditions::FIELD_IP    => $this->lookup( $index, RuleConditions::FIELD_IP, $event['ip'] ?? null ),
			);
		}

		return $matches;
	}

	/**
	 * Get the normalized values that active rules currently target, per field.
	 *
	 * These are the same keys {@see find_for_events()} matches against, so the
	 * "without rules" list filter and the per-row rule chips stay consistent.
	 *
	 * @return array{email: string[], ip: string[]} The targeted values, keyed by condition field.
	 */
	public function get_targeted_values(): array {
		$index = $this->build_index();

		return array(
			RuleConditions::FIELD_EMAIL => array_keys( $index[ RuleConditions::FIELD_EMAIL ] ),
			RuleConditions::FIELD_IP    => array_keys( $index[ RuleConditions::FIELD_IP ] ),
		);
	}

	/**
	 * Look up the rule targeting a field value.
	 *
	 * @param array<string, array<string, Rule>> $index The rule index by field and normalized value.
	 * @param string                             $field The condition field.
	 * @param mixed                              $value The raw event value.
	 * @return ?Rule
	 */
	private function lookup( array $index, string $field, $value ): ?Rule {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}

		$normalized = RuleConditions::normalize_value( $field, $value );

		return is_null( $normalized ) ? null : ( $index[ $field ][ $normalized ] ?? null );
	}

	/**
	 * Index the active single-condition equals rules by field and normalized value.
	 *
	 * @return array<string, array<string, Rule>>
	 */
	private function build_index(): array {
		$index = array(
			RuleConditions::FIELD_EMAIL => array(),
			RuleConditions::FIELD_IP    => array(),
		);

		if ( ! $this->schema_manager->is_schema_installed() ) {
			return $index;
		}

		foreach ( $this->rule_store->get_active_rules() as $rule ) {
			$field    = $rule->conditions['field'] ?? null;
			$operator = $rule->conditions['operator'] ?? null;
			$value    = $rule->conditions['value'] ?? null;

			if ( ! is_string( $field ) || ! is_string( $operator ) || ! is_string( $value ) || ! array_key_exists( $field, $index ) ) {
				continue;
			}

			if ( ! $this->operator_registry->get_operator( $operator ) instanceof EqualsOperator ) {
				continue;
			}

			$normalized = RuleConditions::normalize_value( $field, $value );
			if ( is_null( $normalized ) || isset( $index[ $field ][ $normalized ] ) ) {
				continue;
			}

			$index[ $field ][ $normalized ] = $rule;
		}

		return $index;
	}
}
