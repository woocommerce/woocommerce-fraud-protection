<?php
/**
 * SessionOutcome enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

defined( 'ABSPATH' ) || exit;

/**
 * Merchant-facing outcome of a recorded checkout attempt.
 *
 * The outcome is derived from the recorded event rather than stored: a
 * merchant rule trigger wins, then the enforced status, then the received
 * decision. A received block that was not enforced reads as "flagged by
 * fraud prevention", and every other allowed attempt (genuine allows,
 * fail-open verifies and non-actionable decisions) reads as plain "allowed".
 *
 * {@see from_row()} and {@see sql_condition()} must stay equivalent: the
 * first labels a loaded row, the second filters and counts rows in SQL.
 */
enum SessionOutcome: string {

	/** The attempt was allowed without a merchant rule or a received block. */
	case Allowed = 'allowed';

	/** A merchant allow rule decided the attempt. */
	case AllowedByRules = 'allowed_by_rules';

	/** A merchant block rule decided the attempt. */
	case BlockedByRules = 'blocked_by_rules';

	/** Automatic protection enforced the received block. */
	case BlockedAutomatically = 'blocked_automatically';

	/** A block was received but not enforced, so the attempt was allowed but flagged. */
	case FlaggedByFraudPrevention = 'flagged_by_fraud_prevention';

	/**
	 * Derive the outcome of a sessions table row.
	 *
	 * @param array<string, mixed> $row The row, with at least the trigger_type, final_status and decision columns.
	 * @return self
	 */
	public static function from_row( array $row ): self {
		$trigger = $row['trigger_type'] ?? null;

		if ( SessionTrigger::AllowRule->value === $trigger ) {
			return self::AllowedByRules;
		}

		if ( SessionTrigger::BlockRule->value === $trigger ) {
			return self::BlockedByRules;
		}

		if ( SessionFinalStatus::Blocked->value === ( $row['final_status'] ?? null ) ) {
			return self::BlockedAutomatically;
		}

		return FraudDecision::Block->value === ( $row['decision'] ?? null ) ? self::FlaggedByFraudPrevention : self::Allowed;
	}

	/**
	 * Get the SQL condition selecting the sessions table rows with this outcome.
	 *
	 * The condition is built from enum backing values only, so it is safe to
	 * interpolate into a query.
	 *
	 * @return string
	 */
	public function sql_condition(): string {
		$no_rule_trigger = sprintf( "trigger_type NOT IN ('%s', '%s')", SessionTrigger::AllowRule->value, SessionTrigger::BlockRule->value );
		$not_blocked     = sprintf( "final_status <> '%s'", SessionFinalStatus::Blocked->value );

		// phpcs:ignore PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext -- Enum cases are objects; the sniff predates enums.
		return match ( $this ) {
			self::AllowedByRules           => sprintf( "trigger_type = '%s'", SessionTrigger::AllowRule->value ),
			self::BlockedByRules           => sprintf( "trigger_type = '%s'", SessionTrigger::BlockRule->value ),
			self::BlockedAutomatically     => sprintf( "%s AND final_status = '%s'", $no_rule_trigger, SessionFinalStatus::Blocked->value ),
			self::FlaggedByFraudPrevention => sprintf( "%s AND %s AND decision = '%s'", $no_rule_trigger, $not_blocked, FraudDecision::Block->value ),
			self::Allowed                  => sprintf( "%s AND %s AND decision <> '%s'", $no_rule_trigger, $not_blocked, FraudDecision::Block->value ),
		};
	}
}
