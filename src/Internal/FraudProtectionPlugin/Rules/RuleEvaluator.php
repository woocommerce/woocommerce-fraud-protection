<?php
/**
 * RuleEvaluator class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\VisitorIpResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Evaluates the merchant ruleset against a session.
 *
 * Walks the active rules in position order and returns the first matching
 * one; its action (allow or block) is the merchant's decision for the
 * session, taking precedence over the Blackbox verdict.
 *
 * Fail-open at every level: a malformed rule, an unknown operator or a
 * missing context field evaluates as non-matching (logged), and the whole
 * evaluation is wrapped so a failure can never break checkout.
 *
 * The evaluation context is built from the same data the sessions recorder
 * captures (billing email from the collected session data and visitor IP from
 * the shared resolver), normalized like rule values are at write time so
 * textual variants compare equal.
 */
class RuleEvaluator {

	/**
	 * Rule store instance.
	 *
	 * @var RuleStore
	 */
	private RuleStore $rule_store;

	/**
	 * Merchant lists feature gate instance.
	 *
	 * @var MerchantListsFeature
	 */
	private MerchantListsFeature $merchant_lists_feature;

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
	 * Visitor IP resolver instance.
	 *
	 * @var VisitorIpResolver
	 */
	private VisitorIpResolver $visitor_ip_resolver;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param RuleStore                 $rule_store             The rule store instance.
	 * @param MerchantListsFeature      $merchant_lists_feature The merchant lists feature gate instance.
	 * @param SchemaManager             $schema_manager         The schema manager instance.
	 * @param ConditionOperatorRegistry $operator_registry      The condition operator registry instance.
	 * @param VisitorIpResolver         $visitor_ip_resolver    Visitor IP resolver instance.
	 */
	final public function init(
		RuleStore $rule_store,
		MerchantListsFeature $merchant_lists_feature,
		SchemaManager $schema_manager,
		ConditionOperatorRegistry $operator_registry,
		VisitorIpResolver $visitor_ip_resolver
	): void {
		$this->rule_store             = $rule_store;
		$this->merchant_lists_feature = $merchant_lists_feature;
		$this->schema_manager         = $schema_manager;
		$this->operator_registry      = $operator_registry;
		$this->visitor_ip_resolver    = $visitor_ip_resolver;
	}

	/**
	 * Evaluate the merchant ruleset against a session, first match wins.
	 *
	 * Never throws: any internal failure logs and returns null, meaning "no
	 * rule decided" so the caller falls through to the existing pipeline.
	 * Null is also returned while the feature is disabled or the rules table
	 * is not installed yet.
	 *
	 * @param array<string, mixed> $session_data The session data payload that was sent to the verify API.
	 * @return ?Rule The first matching active rule, or null when none matched.
	 */
	public function evaluate_for_session( array $session_data ): ?Rule {
		try {
			if ( ! $this->merchant_lists_feature->is_enabled() || ! $this->schema_manager->is_schema_installed() ) {
				return null;
			}

			$context = $this->build_context( $session_data );

			foreach ( $this->rule_store->get_active_rules() as $rule ) {
				try {
					if ( $this->matches( $rule, $context ) ) {
						return $rule;
					}
				} catch ( \Throwable $e ) {
					FraudProtectionController::log(
						'warning',
						'Rule evaluation failed for a rule, treating it as non-matching.',
						array(
							'event_source'      => 'rule_evaluator',
							'rule_id'           => $rule->id,
							'exception'         => $e,
							'exception_class'   => $e::class,
							'exception_message' => $e->getMessage(),
						)
					);
				}
			}

			return null;
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'Rule evaluation failed, no rule applied.',
				array(
					'event_source'      => 'rule_evaluator',
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
			return null;
		}
	}

	/**
	 * Build the evaluation context from the session data payload.
	 *
	 * @param array<string, mixed> $session_data The session data payload.
	 * @return array<string, string> The evaluation context, keyed by condition field.
	 */
	private function build_context( array $session_data ): array {
		$customer = is_array( $session_data['customer'] ?? null ) ? $session_data['customer'] : array();
		$session  = is_array( $session_data['session'] ?? null ) ? $session_data['session'] : array();

		$email = (string) ( $customer['billing_email'] ?? '' );
		$email = '' === $email ? (string) ( $session['email'] ?? '' ) : $email;

		$ip = (string) $this->visitor_ip_resolver->get_ip_address();

		return array(
			RuleConditions::FIELD_EMAIL => (string) RuleConditions::normalize_value( RuleConditions::FIELD_EMAIL, $email ),
			RuleConditions::FIELD_IP    => (string) RuleConditions::normalize_value( RuleConditions::FIELD_IP, $ip ),
		);
	}

	/**
	 * Whether a rule's condition document matches the evaluation context.
	 *
	 * @param Rule                  $rule    The rule to check.
	 * @param array<string, string> $context The evaluation context.
	 * @return bool True when the rule matches the context.
	 */
	private function matches( Rule $rule, array $context ): bool {
		$field         = $rule->conditions['field'] ?? null;
		$operator_name = $rule->conditions['operator'] ?? null;
		$value         = $rule->conditions['value'] ?? null;

		if ( ! is_string( $field ) || ! is_string( $operator_name ) || ! is_string( $value ) ) {
			FraudProtectionController::log(
				'warning',
				'Rule conditions have an unsupported shape, treating the rule as non-matching.',
				array(
					'event_source' => 'rule_evaluator',
					'rule_id'      => $rule->id,
				)
			);
			return false;
		}

		$operator = $this->operator_registry->get_operator( $operator_name );
		if ( is_null( $operator ) ) {
			FraudProtectionController::log(
				'warning',
				sprintf( 'Unknown rule condition operator "%s", treating the rule as non-matching.', $operator_name ),
				array(
					'event_source' => 'rule_evaluator',
					'rule_id'      => $rule->id,
				)
			);
			return false;
		}

		// Rule values are normalized at write time, but normalize again here for robustness
		// (normalization rules driftting across versions, rows written outside the store).
		$rule_value = RuleConditions::normalize_value( $field, $value );

		// @phpstan-ignore method.notFound (the hardcoded registry map is the only way an operator gets here, so it is trusted to expose matches())
		return ! is_null( $rule_value ) && $operator->matches( $rule_value, $context[ $field ] ?? '' );
	}
}
