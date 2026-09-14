<?php
/**
 * SessionOutcomeTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Schemas;

use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionOutcome;

/**
 * Tests for the SessionOutcome enum.
 */
class SessionOutcomeTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox Should derive the merchant-facing outcome from the recorded trigger, status and decision.
	 *
	 * @dataProvider row_outcome_provider
	 *
	 * @param array<string, string> $row      The recorded row columns.
	 * @param SessionOutcome        $expected The expected outcome.
	 */
	public function test_from_row_derives_outcome( array $row, SessionOutcome $expected ): void {
		$this->assertSame( $expected, SessionOutcome::from_row( $row ) );
	}

	/**
	 * Recorded rows and their outcomes.
	 *
	 * @return array<string, array{array<string, string>, SessionOutcome}>
	 */
	public function row_outcome_provider(): array {
		return array(
			'allow rule'                      => array( self::row( 'allow_rule', 'allowed', 'block' ), SessionOutcome::AllowedByRules ),
			'block rule'                      => array( self::row( 'block_rule', 'blocked', 'allow' ), SessionOutcome::BlockedByRules ),
			'enforced block'                  => array( self::row( 'blackbox', 'blocked', 'block' ), SessionOutcome::BlockedAutomatically ),
			'block enforced despite decision' => array( self::row( 'blackbox', 'blocked', 'allow' ), SessionOutcome::BlockedAutomatically ),
			'block not enforced'              => array( self::row( 'blackbox', 'allowed', 'block' ), SessionOutcome::FlaggedByFraudPrevention ),
			'genuine allow'                   => array( self::row( 'blackbox', 'allowed', 'allow' ), SessionOutcome::Allowed ),
			'fail-open allow'                 => array( self::row( 'verify_error', 'allowed', 'allow' ), SessionOutcome::Allowed ),
			'rejected request'                => array( self::row( 'request_rejected', 'allowed', 'allow' ), SessionOutcome::Allowed ),
			'challenge coerced to allow'      => array( self::row( 'blackbox', 'allowed', 'challenge' ), SessionOutcome::Allowed ),
			'row without the outcome columns' => array( array(), SessionOutcome::Allowed ),
		);
	}

	/**
	 * @testdox Should build each SQL condition from the recorded column vocabulary only.
	 *
	 * @dataProvider sql_condition_provider
	 *
	 * @param SessionOutcome $outcome  The outcome.
	 * @param string         $expected The expected SQL condition.
	 */
	public function test_sql_conditions_use_the_recorded_vocabulary( SessionOutcome $outcome, string $expected ): void {
		$this->assertSame( $expected, $outcome->sql_condition() );
	}

	/**
	 * Outcomes and their SQL conditions.
	 *
	 * @return array<string, array{SessionOutcome, string}>
	 */
	public function sql_condition_provider(): array {
		$no_rule = "trigger_type NOT IN ('allow_rule', 'block_rule')";

		return array(
			'allowed by rules'            => array( SessionOutcome::AllowedByRules, "trigger_type = 'allow_rule'" ),
			'blocked by rules'            => array( SessionOutcome::BlockedByRules, "trigger_type = 'block_rule'" ),
			'blocked automatically'       => array( SessionOutcome::BlockedAutomatically, $no_rule . " AND final_status = 'blocked'" ),
			'flagged by fraud prevention' => array( SessionOutcome::FlaggedByFraudPrevention, $no_rule . " AND final_status <> 'blocked' AND decision = 'block'" ),
			'allowed'                     => array( SessionOutcome::Allowed, $no_rule . " AND final_status <> 'blocked' AND decision <> 'block'" ),
		);
	}

	/**
	 * Build a recorded row with the columns the outcome is derived from.
	 *
	 * @param string $trigger_type The trigger_type column.
	 * @param string $final_status The final_status column.
	 * @param string $decision     The decision column.
	 * @return array<string, string>
	 */
	private static function row( string $trigger_type, string $final_status, string $decision ): array {
		return array(
			'trigger_type' => $trigger_type,
			'final_status' => $final_status,
			'decision'     => $decision,
		);
	}
}
