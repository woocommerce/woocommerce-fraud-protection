<?php
/**
 * SessionEventStore class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleConditions;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionFinalStatus;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionOutcome;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionTrigger;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for recorded session events (the sessions log).
 *
 * One row per recorded verify event: `record_event()` plain-inserts every
 * event, so repeated session IDs keep one row each and a decision change
 * across repeated attempts is never lost. Attempt counts are a read-time
 * aggregate, not a stored column.
 *
 * The read side also serves the merchant-facing checkout attempts list:
 * `query_events()` pages, sorts and filters the retained rows and
 * `get_payment_methods()` feeds the list's provider filter.
 */
class SessionEventStore {

	/**
	 * Columns the checkout attempts list can be sorted by.
	 */
	public const SORTABLE_COLUMNS = array( 'recorded_at', 'email', 'ip', 'ip_country', 'billing_country', 'payment_method' );

	/**
	 * Maximum rows per checkout attempts list page.
	 */
	public const MAX_PER_PAGE = 100;

	/**
	 * Columns loaded for the checkout attempts list. The risk score is deliberately excluded.
	 */
	private const LIST_COLUMNS = array(
		'id',
		'session_id',
		'recorded_at',
		'source',
		'decision',
		'final_status',
		'trigger_type',
		'email',
		'ip',
		'ip_country',
		'billing_country',
		'billing_state',
		'billing_city',
		'billing_postcode',
		'billing_name',
		'order_id',
		'payment_method',
		'matched_rule_id',
	);

	/**
	 * SQL expressions that compare a stored email or IP with values normalized by
	 * {@see RuleConditions::normalize_value()}, the way rules compare them at checkout.
	 * Each field has the expression of the stored column and the placeholder of a value.
	 *
	 * The recorder already stores emails trimmed and lowercased like the normalizer, so they are
	 * compared byte for byte. Comparing them under the column collation would also match other
	 * addresses, such as "josé@" for "jose@". SQL LOWER() can't be used either: it lowercases
	 * non-ASCII letters, which the normalizer keeps.
	 *
	 * IPs are compared as packed binary addresses, with both sides packed by INET6_ATON. Their text
	 * forms can't be compared, because the database and PHP shorten some IPv6 addresses differently:
	 * INET6_NTOA writes "2001:db8:1:2:3:4::5" where inet_ntop() writes "2001:db8:1:2:3:4:0:5".
	 * A stored value INET6_ATON can't parse is NULL, so it never matches.
	 */
	private const MATCH_EXPRESSIONS = array(
		RuleConditions::FIELD_EMAIL => array( 'CAST(email AS BINARY)', '%s' ),
		RuleConditions::FIELD_IP    => array( 'INET6_ATON(ip)', 'INET6_ATON(%s)' ),
	);

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

		$table                           = $this->schema_manager->get_sessions_table_name();
		$cutoff                          = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );
		$flagged_condition               = SessionOutcome::FlaggedByFraudPrevention->sql_condition();
		$blocked_automatically_condition = SessionOutcome::BlockedAutomatically->sql_condition();
		$allowed_by_rules_condition      = SessionOutcome::AllowedByRules->sql_condition();
		$blocked_by_rules_condition      = SessionOutcome::BlockedByRules->sql_condition();

		$sql = "SELECT
			SUM( CASE WHEN {$flagged_condition} THEN 1 ELSE 0 END ) AS flagged_by_fraud_prevention,
			SUM( CASE WHEN {$blocked_automatically_condition} THEN 1 ELSE 0 END ) AS blocked_automatically,
			SUM( CASE WHEN {$allowed_by_rules_condition} THEN 1 ELSE 0 END ) AS allowed_by_rules,
			SUM( CASE WHEN {$blocked_by_rules_condition} THEN 1 ELSE 0 END ) AS blocked_by_rules
			FROM {$table}
			WHERE recorded_at >= %s";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- The table name comes from SchemaManager, the outcome conditions contain enum values only, and results are cached in a transient.
		$counts = $wpdb->get_row( $wpdb->prepare( $sql, $cutoff ), ARRAY_A );

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

	/**
	 * Query the retained events for the checkout attempts list.
	 *
	 * @param array<string, mixed> $args {
	 *     Optional. Query arguments.
	 *
	 *     @type int                 $days            Only events recorded within this many days. Default the retention period.
	 *     @type int                 $page            1-based page number. Default 1.
	 *     @type int                 $per_page        Rows per page, capped at MAX_PER_PAGE. Default 20.
	 *     @type string              $orderby         One of SORTABLE_COLUMNS. Default 'recorded_at'.
	 *     @type string              $order           'asc' or 'desc'. Default 'desc'.
	 *     @type ?SessionFinalStatus $final_status    Only events with this final status. Default null (any).
	 *     @type SessionOutcome[]    $outcomes        Only events with one of these outcomes. Default none (any).
	 *     @type string[]            $payment_methods Only events with one of these payment method ids. Default none (any).
	 *     @type string              $search          Only events whose email or IP contains this text. Default ''.
	 *     @type string              $email           Only events with this email address, normalized with
	 *                                               RuleConditions::normalize_value(). Default '' (any).
	 *     @type string              $ip              Only events with this IP address, normalized with
	 *                                               RuleConditions::normalize_value(). Default '' (any).
	 *     @type string              $rules           'with' or 'without' to keep only events that do or do not have an
	 *                                               active rule targeting their email or IP. Default '' (any).
	 *     @type array{email: string[], ip: string[]} $rule_values The normalized values active rules target, used when
	 *                                               $rules is set. Default empty.
	 *     @type int                 $max_id          Only events with this ID or lower, so that events recorded while
	 *                                               several pages are read do not shift the later pages. Default 0 (any).
	 * }
	 * @return array{items: array<int, array<string, mixed>>, total: int} The page rows, with integer id, order_id and
	 *                                                                    matched_rule_id, and the total matching count.
	 * @throws \RuntimeException When a query fails.
	 */
	public function query_events( array $args = array() ): array {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'days'                 => SessionEventPruner::RETENTION_DAYS,
				'page'                 => 1,
				'per_page'             => 20,
				'orderby'              => 'recorded_at',
				'order'                => 'desc',
				'final_status'         => null,
				'outcomes'             => array(),
				'payment_methods'      => array(),
				'search'               => '',
				'email'                => '',
				'ip'                   => '',
				'rules'                => '',
				'rule_values'          => array(),
				'max_id'               => 0,
				// Provider ids in the order rows should sort by when ordering by
				// payment method (they are resolved to display titles upstream).
				'payment_method_order' => array(),
			)
		);

		$table    = $this->schema_manager->get_sessions_table_name();
		$where    = $this->build_where( $args );
		$per_page = max( 1, min( self::MAX_PER_PAGE, (int) $args['per_page'] ) );
		$offset   = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$orderby  = in_array( $args['orderby'], self::SORTABLE_COLUMNS, true ) ? $args['orderby'] : 'recorded_at';
		$order    = 'asc' === strtolower( (string) $args['order'] ) ? 'ASC' : 'DESC';
		$columns  = implode( ', ', self::LIST_COLUMNS );
		$limit    = $wpdb->prepare( 'LIMIT %d OFFSET %d', $per_page, $offset );

		// Ordering by provider sorts by the display title, not the raw id. The
		// caller passes the provider ids already sorted by title (and direction),
		// and FIELD() arranges the rows to match. Rows whose provider is not in
		// the list (unknown or empty) sort first.
		$order_by = "{$orderby} {$order}, id DESC";
		if ( 'payment_method' === $orderby ) {
			$ordered_ids = array_values( array_filter( (array) $args['payment_method_order'], 'is_string' ) );
			if ( array() !== $ordered_ids ) {
				$placeholders = implode( ', ', array_fill( 0, count( $ordered_ids ), '%s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$field    = $wpdb->prepare( "FIELD(payment_method, {$placeholders})", ...$ordered_ids );
				$order_by = "{$field}, id DESC";
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Every clause is prepared in build_where().
		$total = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" );
		$this->throw_on_database_error( 'Session event count failed.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clauses, order and limit are prepared, the other fragments are allowlisted constants.
		$rows = $wpdb->get_results( "SELECT {$columns} FROM {$table} WHERE {$where} ORDER BY {$order_by} {$limit}", ARRAY_A );
		$this->throw_on_database_error( 'Session event query failed.' );

		return array(
			'items' => array_map( fn( array $row ): array => self::type_row( $row ), is_array( $rows ) ? $rows : array() ),
			'total' => (int) $total,
		);
	}

	/**
	 * Get the ID of the most recently recorded event.
	 *
	 * @return int The ID, or 0 when no event is recorded.
	 * @throws \RuntimeException When the query fails.
	 */
	public function get_latest_event_id(): int {
		global $wpdb;

		$table = $this->schema_manager->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from SchemaManager.
		$id = $wpdb->get_var( "SELECT MAX(id) FROM {$table}" );
		$this->throw_on_database_error( 'Session event ID query failed.' );

		return (int) $id;
	}

	/**
	 * Get one event with the columns of the checkout attempts list.
	 *
	 * @param int $event_id Recorded session event row ID.
	 * @return ?array<string, mixed> The row typed like {@see query_events()} rows, or null when no event has the ID.
	 * @throws \RuntimeException When the query fails.
	 */
	public function get_list_event( int $event_id ): ?array {
		global $wpdb;

		if ( $event_id <= 0 ) {
			return null;
		}

		$table   = $this->schema_manager->get_sessions_table_name();
		$columns = implode( ', ', self::LIST_COLUMNS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from SchemaManager and the columns are an allowlisted constant.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT {$columns} FROM {$table} WHERE id = %d", $event_id ), ARRAY_A );
		$this->throw_on_database_error( 'Session event read failed.' );

		return is_array( $row ) ? self::type_row( $row ) : null;
	}

	/**
	 * Get the IDs of every event whose email or IP matches a value.
	 *
	 * Events of any age match. The stored value is compared the same way as in the
	 * "with/without rules" list filter (see MATCH_EXPRESSIONS), so the value must be
	 * normalized with {@see RuleConditions::normalize_value()} first.
	 *
	 * @param string $field The condition field: email or ip.
	 * @param string $value The normalized email address or IP address.
	 * @return int[] The event IDs, in ascending order. None when the field is not email or ip.
	 * @throws \RuntimeException When the query fails.
	 */
	public function get_event_ids_by_value( string $field, string $value ): array {
		global $wpdb;

		if ( ! isset( self::MATCH_EXPRESSIONS[ $field ] ) ) {
			return array();
		}

		$table = $this->schema_manager->get_sessions_table_name();
		$where = self::match_clause( $field, array( $value ), false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name comes from SchemaManager and the clause is prepared.
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE {$where} ORDER BY id ASC" );
		$this->throw_on_database_error( 'Session event match query failed.' );

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}

	/**
	 * Delete events by ID.
	 *
	 * Runs one query, so callers delete large sets in batches. The cached outcome
	 * counts are cleared when rows are deleted, so they no longer include them.
	 *
	 * @param int[] $ids Recorded session event row IDs.
	 * @return int The number of rows deleted. IDs with no row are skipped.
	 * @throws \RuntimeException When the delete query fails.
	 */
	public function delete_events( array $ids ): int {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( $ids, fn( $id ): bool => is_int( $id ) && $id > 0 ) ) );
		if ( array() === $ids ) {
			return 0;
		}

		$table        = $this->schema_manager->get_sessions_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The table name comes from SchemaManager and the placeholders match the IDs.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", ...$ids ) );
		if ( false === $deleted ) {
			throw new \RuntimeException( 'Session event deletion failed.' );
		}

		if ( $deleted > 0 ) {
			delete_transient( self::PERFORMANCE_COUNTS_TRANSIENT );
			delete_transient( self::TRACKER_COUNTS_TRANSIENT );
		}

		return (int) $deleted;
	}

	/**
	 * Get the distinct payment method ids of the retained events.
	 *
	 * @param int $days Only events recorded within this many days.
	 * @return string[] The non-empty payment method ids, sorted.
	 * @throws \RuntimeException When the query fails.
	 */
	public function get_payment_methods( int $days ): array {
		global $wpdb;

		$table = $this->schema_manager->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$values = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT payment_method FROM {$table} WHERE recorded_at >= %s AND payment_method <> '' ORDER BY payment_method", self::cutoff( $days ) ) );
		$this->throw_on_database_error( 'Session payment method query failed.' );

		return array_values( array_filter( array_map( 'strval', is_array( $values ) ? $values : array() ), fn( string $value ): bool => '' !== $value ) );
	}

	/**
	 * Build the WHERE clause of a checkout attempts list query.
	 *
	 * Each clause is prepared on its own, so the returned SQL is complete.
	 *
	 * @param array<string, mixed> $args The parsed query arguments.
	 * @return string
	 */
	private function build_where( array $args ): string {
		global $wpdb;

		$clauses = array( $wpdb->prepare( 'recorded_at >= %s', self::cutoff( max( 1, (int) $args['days'] ) ) ) );

		$max_id = (int) $args['max_id'];
		if ( $max_id > 0 ) {
			$clauses[] = $wpdb->prepare( 'id <= %d', $max_id );
		}

		$final_status = $args['final_status'];
		if ( $final_status instanceof SessionFinalStatus ) {
			$clauses[] = $wpdb->prepare( 'final_status = %s', $final_status->value );
		}

		$outcome_conditions = array();
		foreach ( (array) $args['outcomes'] as $outcome ) {
			if ( $outcome instanceof SessionOutcome ) {
				$outcome_conditions[ $outcome->value ] = '(' . $outcome->sql_condition() . ')';
			}
		}
		if ( array() !== $outcome_conditions ) {
			$clauses[] = '(' . implode( ' OR ', $outcome_conditions ) . ')';
		}

		$payment_methods = array_values( array_filter( array_filter( (array) $args['payment_methods'], 'is_string' ), fn( string $method ): bool => '' !== $method ) );
		if ( array() !== $payment_methods ) {
			$placeholders = implode( ', ', array_fill( 0, count( $payment_methods ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$clauses[] = $wpdb->prepare( "payment_method IN ({$placeholders})", ...$payment_methods );
		}

		$search = trim( (string) $args['search'] );
		if ( '' !== $search ) {
			$like      = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses[] = $wpdb->prepare( '(email LIKE %s OR ip LIKE %s)', $like, $like );
		}

		foreach ( array( RuleConditions::FIELD_EMAIL, RuleConditions::FIELD_IP ) as $field ) {
			$value = is_string( $args[ $field ] ?? null ) ? $args[ $field ] : '';
			if ( '' !== $value ) {
				$clauses[] = self::match_clause( $field, array( $value ), false );
			}
		}

		foreach ( $this->rule_filter_clauses( (string) $args['rules'], $args['rule_values'] ?? array() ) as $clause ) {
			$clauses[] = $clause;
		}

		return implode( ' AND ', $clauses );
	}

	/**
	 * Build the clauses keeping only events with or without a matching rule.
	 *
	 * The rule values are the finder's normalized keys, so the stored values are
	 * compared with them like rules compare them at checkout (see MATCH_EXPRESSIONS).
	 * This keeps the "with/without rules" filter consistent with the per-row rule
	 * lookup: an IPv6 address is filtered the same way it is chipped whatever its
	 * notation, and an email that differs from a rule only by accents is not
	 * filtered as having that rule.
	 *
	 * @param string $mode        '' (no filter), 'with', or 'without'.
	 * @param mixed  $rule_values The normalized values active rules target, keyed by field.
	 * @return string[] The prepared clauses to AND into the query.
	 */
	private function rule_filter_clauses( string $mode, $rule_values ): array {
		if ( 'with' !== $mode && 'without' !== $mode ) {
			return array();
		}

		$rule_values = is_array( $rule_values ) ? $rule_values : array();
		$normalize   = fn( $values ): array => array_values( array_filter( array_filter( (array) $values, 'is_string' ), fn( string $value ): bool => '' !== $value ) );
		$is_without  = 'without' === $mode;

		$clauses = array();
		foreach ( array( RuleConditions::FIELD_EMAIL, RuleConditions::FIELD_IP ) as $field ) {
			$values = $normalize( $rule_values[ $field ] ?? array() );
			if ( array() !== $values ) {
				$clauses[] = self::match_clause( $field, $values, $is_without );
			}
		}

		if ( $is_without ) {
			return $clauses;
		}

		// With no active rules, nothing can match a rule.
		return array( array() === $clauses ? '0 = 1' : '(' . implode( ' OR ', $clauses ) . ')' );
	}

	/**
	 * Build the clause matching events whose email or IP equals, or equals none of, some values.
	 *
	 * @param string   $field  The condition field: email or ip.
	 * @param string[] $values The normalized values; at least one.
	 * @param bool     $negate Whether to match events whose value equals none of the values,
	 *                         including events without a value.
	 * @return string The prepared clause.
	 */
	private static function match_clause( string $field, array $values, bool $negate ): string {
		global $wpdb;

		list( $column, $placeholder ) = self::MATCH_EXPRESSIONS[ $field ];

		$placeholders = implode( ', ', array_fill( 0, count( $values ), $placeholder ) );
		$sql          = $negate ? "({$column} IS NULL OR {$column} NOT IN ({$placeholders}))" : "{$column} IN ({$placeholders})";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- The column and placeholder expressions are allowlisted constants.
		return $wpdb->prepare( $sql, ...$values );
	}

	/**
	 * Get the oldest `recorded_at` value inside a window of days.
	 *
	 * @param int $days The window length in days.
	 * @return string The cutoff as a MySQL datetime in UTC.
	 */
	private static function cutoff( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
	}

	/**
	 * Cast the integer columns of a loaded row.
	 *
	 * @param array<string, mixed> $row The row as loaded.
	 * @return array<string, mixed>
	 */
	private static function type_row( array $row ): array {
		$row['id']              = (int) ( $row['id'] ?? 0 );
		$row['order_id']        = is_null( $row['order_id'] ?? null ) ? null : (int) $row['order_id'];
		$row['matched_rule_id'] = is_null( $row['matched_rule_id'] ?? null ) ? null : (int) $row['matched_rule_id'];

		return $row;
	}

	/**
	 * Throw when the last database operation failed.
	 *
	 * @param string $message The exception message.
	 * @throws \RuntimeException When the database reported an error.
	 */
	private function throw_on_database_error( string $message ): void {
		global $wpdb;

		if ( '' !== (string) $wpdb->last_error ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message is a fixed internal string.
			throw new \RuntimeException( $message );
		}
	}
}
