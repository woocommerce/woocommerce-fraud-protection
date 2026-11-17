<?php
/**
 * RuleManager class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\RuleStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Settings\SettingsTelemetry;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the merchant's active exact-value custom rules.
 *
 * Shared by the rules REST controller and the WP-CLI commands, so both apply
 * the same validation, active-only checks, duplicate messages, and change
 * telemetry. Callers are expected to gate on the schema being installed.
 */
class RuleManager {

	/**
	 * Telemetry origin of changes made through WP-CLI.
	 */
	public const ORIGIN_CLI = 'cli';

	/**
	 * Rule persistence.
	 *
	 * @var RuleStore
	 */
	private RuleStore $rule_store;

	/**
	 * Settings telemetry.
	 *
	 * @var SettingsTelemetry
	 */
	private SettingsTelemetry $telemetry;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param RuleStore         $rule_store Rule persistence.
	 * @param SettingsTelemetry $telemetry  Settings telemetry.
	 */
	final public function init( RuleStore $rule_store, SettingsTelemetry $telemetry ): void {
		$this->rule_store = $rule_store;
		$this->telemetry  = $telemetry;
	}

	/**
	 * Build the normalized condition document of an exact-value rule.
	 *
	 * @param string $type  Rule type: email or ip.
	 * @param string $value Rule value.
	 * @return ?array The normalized conditions, or null when the type is unknown or the value is not valid for it.
	 */
	public static function build_conditions( string $type, string $value ): ?array {
		return RuleConditions::validate_and_normalize(
			array(
				'field'    => $type,
				'operator' => 'equals',
				'value'    => $value,
			)
		);
	}

	/**
	 * Detect the rule type of a value.
	 *
	 * An IP address never contains "@", which every email value requires, so
	 * at most one type accepts a value.
	 *
	 * @param string $value Rule value.
	 * @return ?string The rule type, or null when the value is neither an IP address nor an email address.
	 */
	public static function detect_type( string $value ): ?string {
		foreach ( array( RuleConditions::FIELD_IP, RuleConditions::FIELD_EMAIL ) as $type ) {
			if ( ! is_null( RuleConditions::normalize_value( $type, $value ) ) ) {
				return $type;
			}
		}

		return null;
	}

	/**
	 * Get an active rule by ID.
	 *
	 * @param int $id Rule ID.
	 * @return ?Rule The rule, or null when no active rule has the ID.
	 * @throws \RuntimeException When the rule query fails.
	 */
	public function get_active_rule( int $id ): ?Rule {
		$rule = $id > 0 ? $this->rule_store->get_rule( $id ) : null;

		return $rule instanceof Rule && RuleStatus::Active === $rule->status ? $rule : null;
	}

	/**
	 * Find the active rule targeting an exact value.
	 *
	 * @param string $type  Rule type: email or ip.
	 * @param string $value Rule value; normalized before matching.
	 * @return ?Rule The rule, or null when no active rule targets the value.
	 * @throws \RuntimeException When the rule query fails.
	 */
	public function find_active_rule_by_value( string $type, string $value ): ?Rule {
		$result = $this->rule_store->get_active_rules_page(
			filters: array(
				'type'  => $type,
				'value' => $value,
			),
			per_page: 1
		);

		return $result['items'][0] ?? null;
	}

	/**
	 * Create an active rule and record the change.
	 *
	 * @param FraudDecision $action            Rule action.
	 * @param array         $conditions        Condition document from {@see build_conditions()}.
	 * @param string        $origin            Telemetry origin of the change.
	 * @param ?string       $source_session_id Session ID the rule is created from, if any.
	 * @param ?array        $source_meta       Creation-time context to preserve, if any.
	 * @return Rule The created rule.
	 * @throws \InvalidArgumentException When the action is not actionable or the conditions are invalid.
	 * @throws DuplicateRuleException When a live rule with the same conditions exists.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function create_rule( FraudDecision $action, array $conditions, string $origin, ?string $source_session_id = null, ?array $source_meta = null ): Rule {
		$rule = $this->rule_store->create_rule( $action, $conditions, $source_session_id, $source_meta );

		$this->telemetry->record_rule_change( 'created', $rule->action, self::rule_type( $rule ), $origin );

		return $rule;
	}

	/**
	 * Update the action or conditions of an active rule, recording the change when there is one.
	 *
	 * A null action or conditions keeps the stored value, so a concurrent change to that field is not overwritten.
	 *
	 * @param int            $id         Rule ID.
	 * @param ?FraudDecision $action     New rule action, or null to keep it.
	 * @param ?array         $conditions New condition document from {@see build_conditions()}, or null to keep it.
	 * @param string         $origin     Telemetry origin of the change.
	 * @return ?array{rule: Rule, changed: bool} The current rule and whether it changed, or null when no active rule has the ID.
	 * @throws \InvalidArgumentException When the action is not actionable or the conditions are invalid.
	 * @throws DuplicateRuleException When the new conditions duplicate another live rule.
	 * @throws \RuntimeException When the update fails.
	 */
	public function update_rule( int $id, ?FraudDecision $action, ?array $conditions, string $origin ): ?array {
		$result = $this->rule_store->update_rule_with_result( $id, $action, $conditions, required_status: RuleStatus::Active );

		if ( ! is_null( $result ) && $result['changed'] ) {
			$this->telemetry->record_rule_change( 'updated', $result['rule']->action, self::rule_type( $result['rule'] ), $origin );
		}

		return $result;
	}

	/**
	 * Delete an active rule and record the change.
	 *
	 * @param int    $id     Rule ID.
	 * @param string $origin Telemetry origin of the change.
	 * @return ?Rule The deleted rule as it was before deletion, or null when no active rule has the ID.
	 * @throws \RuntimeException When the read or delete fails.
	 */
	public function delete_rule( int $id, string $origin ): ?Rule {
		$rule = $this->rule_store->delete_rule_with_result( $id, RuleStatus::Active );

		if ( $rule instanceof Rule ) {
			$this->telemetry->record_rule_change( 'deleted', $rule->action, self::rule_type( $rule ), $origin );
		}

		return $rule;
	}

	/**
	 * Get the message reporting that a rule for the same value already exists.
	 *
	 * @param string         $type            Rule type of the rejected rule.
	 * @param ?FraudDecision $existing_action Action of the existing rule, when known.
	 * @return string
	 */
	public static function duplicate_rule_message( string $type, ?FraudDecision $existing_action ): string {
		return match ( array( $type, $existing_action ) ) {
			array( RuleConditions::FIELD_EMAIL, FraudDecision::Allow ) => __( 'You already have a rule that allows this email address.', 'woocommerce-fraud-protection' ),
			array( RuleConditions::FIELD_EMAIL, FraudDecision::Block ) => __( 'You already have a rule that blocks this email address.', 'woocommerce-fraud-protection' ),
			array( RuleConditions::FIELD_IP, FraudDecision::Allow ) => __( 'You already have a rule that allows this IP address.', 'woocommerce-fraud-protection' ),
			array( RuleConditions::FIELD_IP, FraudDecision::Block ) => __( 'You already have a rule that blocks this IP address.', 'woocommerce-fraud-protection' ),
			default => __( 'You already have a rule with this value.', 'woocommerce-fraud-protection' ),
		};
	}

	/**
	 * Get the type of a rule.
	 *
	 * @param Rule $rule The rule.
	 * @return string
	 */
	private static function rule_type( Rule $rule ): string {
		return (string) ( $rule->conditions['field'] ?? '' );
	}
}
