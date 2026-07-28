<?php
/**
 * RuleStore class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\RuleStatus;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for merchant rules.
 *
 * Writes validate and normalize the condition document ({@see RuleConditions})
 * and enforce condition uniqueness across live rules through the condition
 * hash. Deleting is a soft delete: the row is kept (session rows reference it
 * via `matched_rule_id`) with its hash nulled so the uniqueness constraint
 * only applies to live rules.
 *
 * New rules are seeded into two position bands (allow rules above block
 * rules) so allow rules keep working as the recourse for enforcement
 * false positives unless the merchant deliberately reorders them. Within a
 * band, new rules land at the bottom.
 *
 * The active ruleset is loaded with a single query and cached in the object
 * cache; every write invalidates the cache (with a short TTL as the backstop
 * for a repopulation racing a write), so steady-state evaluation costs
 * zero queries on sites with a persistent object cache.
 *
 * Callers are expected to gate on {@see SchemaManager::is_schema_installed()}:
 * the store itself assumes the rules table exists.
 */
class RuleStore {

	/**
	 * Object cache group for rules data.
	 */
	private const CACHE_GROUP = 'wc_fraud_protection';

	/**
	 * Object cache key holding the active ruleset rows.
	 */
	private const ACTIVE_RULES_CACHE_KEY = 'active_rules';

	/**
	 * Lifetime of the cached active ruleset. Writes invalidate the cache
	 * eagerly; the TTL is the backstop for a repopulation racing a write
	 * (read rows, concurrent write invalidates, stale rows get cached),
	 * which on a persistent object cache would otherwise keep serving the
	 * stale ruleset until the next write.
	 */
	private const ACTIVE_RULES_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

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
	 * Create a new active rule.
	 *
	 * @param FraudDecision $action            The action to apply when the rule matches (Allow or Block).
	 * @param array         $conditions        The condition document; validated and normalized before storing.
	 * @param ?string       $source_session_id The Blackbox session id the rule is created from, if any.
	 * @param ?array        $source_meta       Creation-time context to preserve, if any.
	 * @return Rule The created rule.
	 * @throws \InvalidArgumentException When the action is not actionable or the conditions are invalid.
	 * @throws DuplicateRuleException When a live rule with the same normalized conditions exists.
	 * @throws \RuntimeException When the insert fails.
	 */
	public function create_rule( FraudDecision $action, array $conditions, ?string $source_session_id = null, ?array $source_meta = null ): Rule {
		global $wpdb;

		if ( ! in_array( $action, FraudDecision::ACTIONABLE, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Rule action must be actionable, "%s" given.', esc_html( $action->value ) ) );
		}

		$normalized = RuleConditions::validate_and_normalize( $conditions );
		if ( is_null( $normalized ) ) {
			throw new \InvalidArgumentException( 'Invalid rule conditions.' );
		}

		$hash        = RuleConditions::hash( $normalized );
		$existing_id = $this->find_rule_id_by_hash( $hash );
		if ( ! is_null( $existing_id ) ) {
			throw new DuplicateRuleException( 'A rule with the same conditions already exists.', $existing_id );
		}

		$user_id = get_current_user_id();

		$columns = array(
			'action'            => $action->value,
			'status'            => RuleStatus::Active->value,
			'position'          => $this->seed_position( $action ),
			'conditions'        => (string) wp_json_encode( $normalized ),
			'condition_hash'    => $hash,
			'source_meta'       => is_null( $source_meta ) ? null : (string) wp_json_encode( $source_meta ),
			'created_at'        => gmdate( 'Y-m-d H:i:s' ),
			'created_by'        => $user_id > 0 ? $user_id : null,
			'source_session_id' => is_null( $source_session_id ) ? null : mb_substr( sanitize_text_field( $source_session_id ), 0, 64 ),
		);

		if ( false === $this->run_write_query( $this->build_insert_sql( $columns ) ) ) {
			// The unique hash key is the backstop for concurrent creations:
			// re-check so a lost race reports as a duplicate, not a failure.
			$existing_id = $this->find_rule_id_by_hash( $hash );
			if ( ! is_null( $existing_id ) ) {
				throw new DuplicateRuleException( 'A rule with the same conditions already exists.', $existing_id );
			}
			throw new \RuntimeException( 'Failed to insert the rule: ' . esc_html( $wpdb->last_error ) );
		}

		$rule = $this->get_rule( (int) $wpdb->insert_id );
		if ( is_null( $rule ) ) {
			throw new \RuntimeException( 'Failed to read back the created rule.' );
		}

		return $rule;
	}

	/**
	 * Update a rule.
	 *
	 * Only the given (non-null) aspects are changed. Soft-deleted rules are
	 * not updatable and report as not found.
	 *
	 * @param int            $id         The rule id.
	 * @param ?FraudDecision $action     New action, if changing.
	 * @param ?array         $conditions New condition document, if changing; validated and normalized.
	 * @param ?RuleStatus    $status     New status, if changing; `Deleted` is rejected (use {@see delete_rule()}).
	 * @param ?int           $position   New evaluation position, if changing.
	 * @return ?Rule The updated rule, or null when no live rule has the given id.
	 * @throws \InvalidArgumentException When a given value is invalid.
	 * @throws DuplicateRuleException When the new conditions duplicate another live rule.
	 * @throws \RuntimeException When the update fails.
	 */
	public function update_rule( int $id, ?FraudDecision $action = null, ?array $conditions = null, ?RuleStatus $status = null, ?int $position = null ): ?Rule {
		global $wpdb;

		$rule = $this->get_rule( $id );
		if ( is_null( $rule ) || RuleStatus::Deleted === $rule->status ) {
			return null;
		}

		if ( ! is_null( $action ) && ! in_array( $action, FraudDecision::ACTIONABLE, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Rule action must be actionable, "%s" given.', esc_html( $action->value ) ) );
		}

		if ( RuleStatus::Deleted === $status ) {
			throw new \InvalidArgumentException( 'Rules are deleted through delete_rule(), not by updating the status.' );
		}

		$user_id = get_current_user_id();
		$changes = array(
			'updated_at' => gmdate( 'Y-m-d H:i:s' ),
			'updated_by' => $user_id > 0 ? $user_id : null,
		);

		if ( ! is_null( $action ) ) {
			$changes['action'] = $action->value;
		}

		if ( ! is_null( $status ) ) {
			$changes['status'] = $status->value;
		}

		if ( ! is_null( $position ) ) {
			$changes['position'] = $position;
		}

		$new_hash = null;
		if ( ! is_null( $conditions ) ) {
			$normalized = RuleConditions::validate_and_normalize( $conditions );
			if ( is_null( $normalized ) ) {
				throw new \InvalidArgumentException( 'Invalid rule conditions.' );
			}

			$new_hash    = RuleConditions::hash( $normalized );
			$existing_id = $this->find_rule_id_by_hash( $new_hash );
			if ( ! is_null( $existing_id ) && $existing_id !== $id ) {
				throw new DuplicateRuleException( 'A rule with the same conditions already exists.', $existing_id );
			}

			$changes['conditions']     = (string) wp_json_encode( $normalized );
			$changes['condition_hash'] = $new_hash;
		}

		if ( false === $this->run_write_query( $this->build_update_sql( $changes, $id ) ) ) {
			// The unique hash key is the backstop for a concurrent write of the
			// same conditions: re-check so a lost race reports as a duplicate,
			// not a failure.
			if ( ! is_null( $new_hash ) ) {
				$existing_id = $this->find_rule_id_by_hash( $new_hash );
				if ( ! is_null( $existing_id ) && $existing_id !== $id ) {
					throw new DuplicateRuleException( 'A rule with the same conditions already exists.', $existing_id );
				}
			}
			throw new \RuntimeException( 'Failed to update the rule: ' . esc_html( $wpdb->last_error ) );
		}

		// The write predicate excludes rows soft-deleted after the read above,
		// but its affected-rows count cannot distinguish that from a no-op
		// update (mysqli reports rows changed, not rows matched), so the row's
		// current status is what tells whether the update applied.
		$rule = $this->get_rule( $id );

		return ! is_null( $rule ) && RuleStatus::Deleted !== $rule->status ? $rule : null;
	}

	/**
	 * Soft-delete a rule.
	 *
	 * The row is kept with `status = deleted` and its condition hash nulled,
	 * so session rows referencing the rule keep their historical context and
	 * the hash uniqueness constraint stops applying to it.
	 *
	 * @param int $id The rule id.
	 * @return bool True when the rule was deleted, false when no live rule has the given id.
	 */
	public function delete_rule( int $id ): bool {
		$user_id = get_current_user_id();
		$changes = array(
			'status'         => RuleStatus::Deleted->value,
			'condition_hash' => null,
			'updated_at'     => gmdate( 'Y-m-d H:i:s' ),
			'updated_by'     => $user_id > 0 ? $user_id : null,
		);

		// No read-then-write: the write predicate only matches live rules, and
		// deleting a live rule always changes its status, so the affected-rows
		// count alone reports whether a live rule existed — concurrent double
		// deletes cannot both report success.
		$affected = $this->run_write_query( $this->build_update_sql( $changes, $id ) );

		return false !== $affected && $affected > 0;
	}

	/**
	 * Get a rule by id, whatever its status (soft-deleted included, so the
	 * sessions UI can render the content of a deleted matched rule).
	 *
	 * @param int $id The rule id.
	 * @return ?Rule The rule, or null when the id does not exist (or the row is not interpretable).
	 */
	public function get_rule( int $id ): ?Rule {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? Rule::from_row( $row ) : null;
	}

	/**
	 * Get the active rules in evaluation order (position, then id).
	 *
	 * The backing rows are loaded with a single query and kept in the object
	 * cache until the next rule write. Rows that cannot be interpreted as
	 * rules are logged and skipped, so one corrupt row never disables the
	 * rest of the ruleset.
	 *
	 * @return Rule[] The active rules, evaluation order.
	 */
	public function get_active_rules(): array {
		global $wpdb;

		$rows = wp_cache_get( self::ACTIVE_RULES_CACHE_KEY, self::CACHE_GROUP );

		if ( ! is_array( $rows ) ) {
			$table = $this->schema_manager->get_rules_table_name();

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status = %s ORDER BY position, id", RuleStatus::Active->value ), ARRAY_A );
			$rows = is_array( $rows ) ? $rows : array();

			wp_cache_set( self::ACTIVE_RULES_CACHE_KEY, $rows, self::CACHE_GROUP, self::ACTIVE_RULES_CACHE_TTL );
		}

		$rules = array();
		foreach ( $rows as $row ) {
			$rule = Rule::from_row( $row );
			if ( is_null( $rule ) ) {
				FraudProtectionController::log(
					'warning',
					'Skipping uninterpretable rule row during evaluation.',
					array(
						'event_source' => 'rule_store',
						'rule_id'      => (int) ( $row['id'] ?? 0 ),
					)
				);
				continue;
			}
			$rules[] = $rule;
		}

		return $rules;
	}

	/**
	 * Find the id of the live rule with the given condition hash, if any.
	 *
	 * @param string $hash The condition hash.
	 * @return ?int The rule id, or null when no live rule has the hash.
	 */
	private function find_rule_id_by_hash( string $hash ): ?int {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE condition_hash = %s", $hash ) );

		return is_null( $id ) ? null : (int) $id;
	}

	/**
	 * Get the seed position for a new rule, keeping allow rules above block rules.
	 *
	 * A new allow rule lands at the bottom of the allow band: right above the
	 * topmost live block rule, whose band is shifted down to make room. A new
	 * block rule lands at the very bottom. Soft-deleted rules are ignored.
	 *
	 * @param FraudDecision $action The action of the new rule.
	 * @return int The position to insert the rule at.
	 */
	private function seed_position( FraudDecision $action ): int {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();

		if ( FraudDecision::Allow === $action ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$first_block_position = $wpdb->get_var( $wpdb->prepare( "SELECT MIN(position) FROM {$table} WHERE status != %s AND action = %s", RuleStatus::Deleted->value, FraudDecision::Block->value ) );

			if ( ! is_null( $first_block_position ) ) {
				$position = (int) $first_block_position;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET position = position + 1 WHERE status != %s AND position >= %d", RuleStatus::Deleted->value, $position ) );

				return $position;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max_position = $wpdb->get_var( $wpdb->prepare( "SELECT MAX(position) FROM {$table} WHERE status != %s", RuleStatus::Deleted->value ) );

		return is_null( $max_position ) ? 1 : (int) $max_position + 1;
	}

	/**
	 * Build a prepared INSERT statement for the rules table.
	 *
	 * @param array<string, mixed> $columns Column values; null values insert SQL NULL.
	 * @return string The prepared SQL.
	 */
	private function build_insert_sql( array $columns ): string {
		global $wpdb;

		$placeholders = array();
		$values       = array();
		foreach ( $columns as $value ) {
			if ( is_null( $value ) ) {
				$placeholders[] = 'NULL';
			} elseif ( is_int( $value ) ) {
				$placeholders[] = '%d';
				$values[]       = $value;
			} else {
				$placeholders[] = '%s';
				$values[]       = $value;
			}
		}

		$sql = 'INSERT INTO ' . $this->schema_manager->get_rules_table_name() . ' (' . implode( ', ', array_keys( $columns ) ) . ')
			VALUES (' . implode( ', ', $placeholders ) . ')';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->prepare( $sql, $values );
	}

	/**
	 * Build a prepared UPDATE statement for a live (non-deleted) rules table row.
	 *
	 * The `status != deleted` predicate makes the soft-delete precondition
	 * atomic: a row soft-deleted by a concurrent request is simply not
	 * matched, so no write can mutate, reactivate, or re-delete a deleted
	 * rule regardless of what a caller's earlier read saw.
	 *
	 * @param array<string, mixed> $changes Column values to set; null values set SQL NULL.
	 * @param int                  $id      The rule id.
	 * @return string The prepared SQL.
	 */
	private function build_update_sql( array $changes, int $id ): string {
		global $wpdb;

		$assignments = array();
		$values      = array();
		foreach ( $changes as $column => $value ) {
			if ( is_null( $value ) ) {
				$assignments[] = $column . ' = NULL';
			} elseif ( is_int( $value ) ) {
				$assignments[] = $column . ' = %d';
				$values[]      = $value;
			} else {
				$assignments[] = $column . ' = %s';
				$values[]      = $value;
			}
		}

		$values[] = $id;
		$values[] = RuleStatus::Deleted->value;
		$sql      = 'UPDATE ' . $this->schema_manager->get_rules_table_name() . ' SET ' . implode( ', ', $assignments ) . ' WHERE id = %d AND status != %s';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->prepare( $sql, $values );
	}

	/**
	 * Run a write query and invalidate the cached ruleset.
	 *
	 * The cache is invalidated even on failure: a multi-statement write (e.g.
	 * the position shift plus the insert) may have partially executed.
	 *
	 * @param string $sql The prepared SQL to run.
	 * @return int|bool The `$wpdb->query()` result.
	 */
	private function run_write_query( string $sql ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$result = $wpdb->query( $sql );

		wp_cache_delete( self::ACTIVE_RULES_CACHE_KEY, self::CACHE_GROUP );

		return $result;
	}
}
