<?php
/**
 * SessionEventStore class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for recorded session events (the sessions log).
 *
 * One row per recorded verify event: `record_event()` plain-inserts every
 * event, so repeated session IDs keep one row each and a decision change
 * across repeated attempts is never lost. Attempt counts are a read-time
 * aggregate, not a stored column.
 */
class SessionEventStore {

	/**
	 * Transient holding performance outcome counts.
	 */
	private const PERFORMANCE_COUNTS_TRANSIENT = 'wc_fraud_protection_performance_counts';

	/**
	 * Lifetime of cached performance outcome counts.
	 */
	private const PERFORMANCE_COUNTS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Transient holding Tracker-only session counts.
	 */
	private const TRACKER_COUNTS_TRANSIENT = 'wc_fraud_protection_tracker_counts';

	/**
	 * Lifetime of cached Tracker-only session counts.
	 */
	private const TRACKER_COUNTS_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SchemaManager $schema_manager The schema manager instance.
	 */
	final public function init( SchemaManager $schema_manager ): void {
		$this->schema_manager = $schema_manager;
	}

	/**
	 * Record a session event as a new row.
	 *
	 * Every event is a plain insert: repeated session IDs are not folded, so
	 * each attempt keeps its own decision and final status.
	 *
	 * @param array<string, mixed> $event The event data to record: session_id, source, decision, final_status,
	 *                                    trigger_type, risk_score (nullable float), email, ip, ip_country,
	 *                                    billing_country/state/city/postcode/name, order_id, payment_method
	 *                                    and matched_rule_id (nullable int).
	 * @return bool True on success, false on database failure.
	 */
	public function record_event( array $event ): bool {
		global $wpdb;

		$table = $this->schema_manager->get_sessions_table_name();

		$matched_rule_id = (int) ( $event['matched_rule_id'] ?? 0 );

		$columns = array(
			'session_id'       => '' === $event['session_id'] ? null : $event['session_id'],
			'recorded_at'      => gmdate( 'Y-m-d H:i:s' ),
			'source'           => $event['source'],
			'decision'         => $event['decision'],
			'final_status'     => $event['final_status'],
			'trigger_type'     => $event['trigger_type'],
			'risk_score'       => $event['risk_score'],
			'email'            => $event['email'],
			'ip'               => $event['ip'],
			'ip_country'       => $event['ip_country'],
			'billing_country'  => $event['billing_country'],
			'billing_state'    => $event['billing_state'],
			'billing_city'     => $event['billing_city'],
			'billing_postcode' => $event['billing_postcode'],
			'billing_name'     => $event['billing_name'],
			'order_id'         => 0 === $event['order_id'] ? null : $event['order_id'],
			'payment_method'   => $event['payment_method'],
			'matched_rule_id'  => 0 === $matched_rule_id ? null : $matched_rule_id,
		);

		$placeholders = array();
		$values       = array();
		foreach ( $columns as $column => $value ) {
			if ( is_null( $value ) ) {
				$placeholders[] = 'NULL';
			} elseif ( is_float( $value ) ) {
				$placeholders[] = '%f';
				$values[]       = $value;
			} elseif ( is_int( $value ) ) {
				$placeholders[] = '%d';
				$values[]       = $value;
			} else {
				$placeholders[] = '%s';
				$values[]       = $value;
			}
		}

		$sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', array_keys( $columns ) ) . ')
			VALUES (' . implode( ', ', $placeholders ) . ')';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return false !== $wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Read the narrow set of event fields needed for a contextual rule create.
	 *
	 * @param int $event_id Recorded session event row ID.
	 * @return ?array{id: int, session_id: ?string, final_status: ?string, email: ?string, ip: ?string}
	 */
	public function get_event( int $event_id ): ?array {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return null;
		}

		$table = $this->schema_manager->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from SchemaManager.
				"SELECT id, session_id, final_status, email, ip FROM {$table} WHERE id = %d",
				$event_id
			),
			ARRAY_A
		);

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'id'           => (int) ( $row['id'] ?? 0 ),
			'session_id'   => is_string( $row['session_id'] ?? null ) ? $row['session_id'] : null,
			'final_status' => is_string( $row['final_status'] ?? null ) ? $row['final_status'] : null,
			'email'        => is_string( $row['email'] ?? null ) ? $row['email'] : null,
			'ip'           => is_string( $row['ip'] ?? null ) ? $row['ip'] : null,
		);
	}

	/**
	 * Count performance outcomes recorded during the previous 30 days.
	 *
	 * @return array{flagged_by_fraud_prevention: int, blocked_automatically: int, allowed_by_rules: int, blocked_by_rules: int}
	 * @throws \RuntimeException When the aggregate query fails.
	 */
	public function get_performance_counts(): array {
		global $wpdb;

		$cached_counts = get_transient( self::PERFORMANCE_COUNTS_TRANSIENT );
		if ( $this->has_valid_cached_counts( $cached_counts, array( 'flagged_by_fraud_prevention', 'blocked_automatically', 'allowed_by_rules', 'blocked_by_rules' ) ) ) {
			return array(
				'flagged_by_fraud_prevention' => $cached_counts['flagged_by_fraud_prevention'],
				'blocked_automatically'       => $cached_counts['blocked_automatically'],
				'allowed_by_rules'            => $cached_counts['allowed_by_rules'],
				'blocked_by_rules'            => $cached_counts['blocked_by_rules'],
			);
		}

		$table  = $this->schema_manager->get_sessions_table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		$sql = "SELECT
			SUM( CASE WHEN trigger_type IN ( %s, %s ) AND decision = %s AND final_status = %s THEN 1 ELSE 0 END ) AS flagged_by_fraud_prevention,
			SUM( CASE WHEN trigger_type IN ( %s, %s ) AND decision = %s AND final_status = %s THEN 1 ELSE 0 END ) AS blocked_automatically,
			SUM( CASE WHEN trigger_type = %s THEN 1 ELSE 0 END ) AS allowed_by_rules,
			SUM( CASE WHEN trigger_type = %s THEN 1 ELSE 0 END ) AS blocked_by_rules
			FROM {$table}
			WHERE recorded_at >= %s";

		$values = array(
			SessionTrigger::Blackbox->value,
			SessionTrigger::RequestRejected->value,
			FraudDecision::Block->value,
			SessionFinalStatus::Allowed->value,
			SessionTrigger::Blackbox->value,
			SessionTrigger::RequestRejected->value,
			FraudDecision::Block->value,
			SessionFinalStatus::Blocked->value,
			SessionTrigger::AllowRule->value,
			SessionTrigger::BlockRule->value,
			$cutoff,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from SchemaManager and results are cached in a transient.
		$counts = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		if ( ! is_array( $counts ) ) {
			throw new \RuntimeException( 'Session event performance query failed.' );
		}

		$performance_counts = array(
			'flagged_by_fraud_prevention' => (int) $counts['flagged_by_fraud_prevention'],
			'blocked_automatically'       => (int) $counts['blocked_automatically'],
			'allowed_by_rules'            => (int) $counts['allowed_by_rules'],
			'blocked_by_rules'            => (int) $counts['blocked_by_rules'],
		);

		set_transient( self::PERFORMANCE_COUNTS_TRANSIENT, $performance_counts, self::PERFORMANCE_COUNTS_CACHE_TTL );

		return $performance_counts;
	}

	/**
	 * Count automatic blocks applied during cumulative recent windows.
	 *
	 * @return array{automatic_blocks_applied_1d: int, automatic_blocks_applied_7d: int, automatic_blocks_applied_30d: int}
	 * @throws \RuntimeException When the aggregate query fails.
	 */
	public function get_automatic_block_counts(): array {
		global $wpdb;

		$table      = $this->schema_manager->get_sessions_table_name();
		$timestamp  = time();
		$cutoff_1d  = gmdate( 'Y-m-d H:i:s', $timestamp - DAY_IN_SECONDS );
		$cutoff_7d  = gmdate( 'Y-m-d H:i:s', $timestamp - ( 7 * DAY_IN_SECONDS ) );
		$cutoff_30d = gmdate( 'Y-m-d H:i:s', $timestamp - ( 30 * DAY_IN_SECONDS ) );

		$sql = "SELECT
			SUM( CASE WHEN recorded_at >= %s THEN 1 ELSE 0 END ) AS automatic_blocks_applied_1d,
			SUM( CASE WHEN recorded_at >= %s THEN 1 ELSE 0 END ) AS automatic_blocks_applied_7d,
			COUNT(*) AS automatic_blocks_applied_30d
			FROM {$table}
			WHERE trigger_type IN ( %s, %s )
				AND decision = %s
				AND final_status = %s
				AND recorded_at >= %s";

		$values = array(
			$cutoff_1d,
			$cutoff_7d,
			SessionTrigger::Blackbox->value,
			SessionTrigger::RequestRejected->value,
			FraudDecision::Block->value,
			SessionFinalStatus::Blocked->value,
			$cutoff_30d,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from SchemaManager and this is one bounded aggregate query.
		$counts = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		if ( ! is_array( $counts ) ) {
			throw new \RuntimeException( 'Automatic block count query failed.' );
		}

		return array(
			'automatic_blocks_applied_1d'  => (int) $counts['automatic_blocks_applied_1d'],
			'automatic_blocks_applied_7d'  => (int) $counts['automatic_blocks_applied_7d'],
			'automatic_blocks_applied_30d' => (int) $counts['automatic_blocks_applied_30d'],
		);
	}

	/**
	 * Count Tracker-only session outcomes recorded during the previous 30 days.
	 *
	 * @return array{sessions_total_30d: int, automatic_allows_applied_30d: int, verify_errors_30d: int, requests_rejected_30d: int}
	 * @throws \RuntimeException When the aggregate query fails.
	 */
	public function get_tracker_counts(): array {
		global $wpdb;

		$cached_counts = get_transient( self::TRACKER_COUNTS_TRANSIENT );
		if ( $this->has_valid_cached_counts( $cached_counts, array( 'sessions_total_30d', 'automatic_allows_applied_30d', 'verify_errors_30d', 'requests_rejected_30d' ) ) ) {
			return array(
				'sessions_total_30d'           => $cached_counts['sessions_total_30d'],
				'automatic_allows_applied_30d' => $cached_counts['automatic_allows_applied_30d'],
				'verify_errors_30d'            => $cached_counts['verify_errors_30d'],
				'requests_rejected_30d'        => $cached_counts['requests_rejected_30d'],
			);
		}

		$table  = $this->schema_manager->get_sessions_table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		$sql = "SELECT
			COUNT(*) AS sessions_total_30d,
			SUM( CASE WHEN trigger_type = %s AND decision = %s AND final_status = %s THEN 1 ELSE 0 END ) AS automatic_allows_applied_30d,
			SUM( CASE WHEN trigger_type = %s THEN 1 ELSE 0 END ) AS verify_errors_30d,
			SUM( CASE WHEN trigger_type = %s THEN 1 ELSE 0 END ) AS requests_rejected_30d
			FROM {$table}
			WHERE recorded_at >= %s";

		$values = array(
			SessionTrigger::Blackbox->value,
			FraudDecision::Allow->value,
			SessionFinalStatus::Allowed->value,
			SessionTrigger::VerifyError->value,
			SessionTrigger::RequestRejected->value,
			$cutoff,
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from SchemaManager and results are cached in a transient.
		$counts = $wpdb->get_row( $wpdb->prepare( $sql, $values ), ARRAY_A );

		if ( ! is_array( $counts ) ) {
			throw new \RuntimeException( 'Session event Tracker query failed.' );
		}

		$tracker_counts = array(
			'sessions_total_30d'           => (int) $counts['sessions_total_30d'],
			'automatic_allows_applied_30d' => (int) $counts['automatic_allows_applied_30d'],
			'verify_errors_30d'            => (int) $counts['verify_errors_30d'],
			'requests_rejected_30d'        => (int) $counts['requests_rejected_30d'],
		);

		set_transient( self::TRACKER_COUNTS_TRANSIENT, $tracker_counts, self::TRACKER_COUNTS_CACHE_TTL );

		return $tracker_counts;
	}

	/**
	 * Check that a cached aggregate contains approved non-negative integer values.
	 *
	 * @param mixed    $cached_counts Cached value.
	 * @param string[] $keys          Approved count keys.
	 * @return bool True when every approved key has a valid count.
	 * @phpstan-assert-if-true array<string, int> $cached_counts
	 */
	private function has_valid_cached_counts( $cached_counts, array $keys ): bool {
		if ( ! is_array( $cached_counts ) ) {
			return false;
		}

		foreach ( $keys as $key ) {
			$value = $cached_counts[ $key ] ?? null;
			if ( ! is_int( $value ) || $value < 0 ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Delete event rows whose `recorded_at` is older than the given number of days.
	 *
	 * Deletes in batches to keep individual queries small.
	 *
	 * @param int $days Retention period in days.
	 * @return int The number of rows deleted.
	 * @throws \RuntimeException When a delete query fails.
	 */
	public function prune_older_than( int $days ): int {
		global $wpdb;

		$table  = $this->schema_manager->get_sessions_table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %s LIMIT 1000", $cutoff ) );
			if ( false === $deleted ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The internal row count is safe in a CLI error.
				throw new \RuntimeException( sprintf( 'Session event pruning failed after deleting %d row(s).', $total ) );
			}
			$total += (int) $deleted;
		} while ( 1000 <= $deleted );

		return $total;
	}
}
