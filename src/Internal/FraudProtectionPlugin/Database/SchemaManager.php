<?php
/**
 * SchemaManager class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Proxies\LegacyProxy;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's database tables.
 *
 * The plugin is deployed as an MU-plugin, so there is no activation hook:
 * schema installation is version-gated and runs from `register()` (WordPress
 * `init`), and only while the merchant lists feature is enabled — sites with
 * the feature off get no tables at all.
 *
 * Migrations must be forward-safe: rollbacks of the plugin are not self-serve
 * on WoA, so a newer schema must keep working under an older plugin version.
 */
class SchemaManager {

	/**
	 * Option holding the currently installed schema version.
	 */
	public const DB_VERSION_OPTION = 'woocommerce_fraud_protection_db_version';

	/**
	 * Schema version written by this build. Bump when the schema changes,
	 * including fixes to the schema string itself: the bump is what resets
	 * the retry state on sites where a previous installation gave up.
	 */
	public const SCHEMA_VERSION = 2;

	/**
	 * Option holding the schema installation retry state: an array with
	 * `schema_version` (the version the attempts target), `attempts`,
	 * `last_attempt` (Unix timestamp) and `last_error` (the database error(s)
	 * from the most recent failed attempt). Present only while installation
	 * is failing or after it gave up, it is deleted on success.
	 */
	public const DB_INSTALL_STATE_OPTION = 'woocommerce_fraud_protection_db_install_state';

	/**
	 * Give up after this many failed installation attempts: combined with
	 * the retry interval this is roughly a day of retries, after which the
	 * failure is likely deterministic (e.g. a config issue) and retrying
	 * forever would just log forever.
	 */
	private const MAX_INSTALL_ATTEMPTS = 24;

	/**
	 * Minimum time between installation attempts, in seconds.
	 */
	private const INSTALL_RETRY_INTERVAL = HOUR_IN_SECONDS;

	/**
	 * First keywords of the lines of a dbDelta CREATE TABLE statement that are
	 * not column definitions, used when extracting the declared column names.
	 */
	private const NON_COLUMN_LINE_KEYWORDS = array( 'CREATE', 'PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL', 'CONSTRAINT', 'FOREIGN' );

	/**
	 * Merchant lists feature gate instance.
	 *
	 * @var MerchantListsFeature
	 */
	private MerchantListsFeature $merchant_lists_feature;

	/**
	 * Legacy proxy instance, used to reach `$wpdb` and `dbDelta()`.
	 *
	 * @var LegacyProxy
	 */
	private LegacyProxy $legacy_proxy;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param MerchantListsFeature $merchant_lists_feature The merchant lists feature gate instance.
	 * @param LegacyProxy          $legacy_proxy           The legacy proxy instance.
	 */
	final public function init( MerchantListsFeature $merchant_lists_feature, LegacyProxy $legacy_proxy ): void {
		$this->merchant_lists_feature = $merchant_lists_feature;
		$this->legacy_proxy           = $legacy_proxy;
	}

	/**
	 * Install or upgrade the schema if needed. To be run at WordPress `init`.
	 */
	public function register(): void {
		if ( ! $this->merchant_lists_feature->is_enabled() ) {
			return;
		}

		$this->maybe_install_schema();
	}

	/**
	 * Get the name of the sessions table, including the site prefix.
	 *
	 * @return string
	 */
	public function get_sessions_table_name(): string {
		return $this->legacy_proxy->get_global( 'wpdb' )->prefix . 'wc_fraud_protection_sessions';
	}

	/**
	 * Get the name of the merchant rules table, including the site prefix.
	 *
	 * @return string
	 */
	public function get_rules_table_name(): string {
		return $this->legacy_proxy->get_global( 'wpdb' )->prefix . 'wc_fraud_protection_rules';
	}

	/**
	 * Whether the installed schema version is at least the one this build needs.
	 *
	 * While this is false (installation pending, failing, or given up),
	 * consumers of the sessions table should skip their reads and writes.
	 *
	 * A stored version newer than this build's counts as installed: that is
	 * the rollback scenario, and migrations are additive (forward-safe), so a
	 * newer schema always satisfies an older build's needs. Requiring an
	 * exact match instead would make a rolled-back build re-run dbDelta and
	 * re-stamp the version downwards for nothing.
	 *
	 * @return bool
	 */
	public function is_schema_installed(): bool {
		return (int) get_option( self::DB_VERSION_OPTION, 0 ) >= self::SCHEMA_VERSION;
	}

	/**
	 * Run dbDelta when the stored schema version is older than the current one.
	 *
	 * Fail-open: any failure is logged and the version option is left
	 * untouched, so a later request retries; nothing is thrown. Retries are
	 * throttled through {@see self::DB_INSTALL_STATE_OPTION} to at most one
	 * attempt per {@see self::INSTALL_RETRY_INTERVAL}, and abandoned after
	 * {@see self::MAX_INSTALL_ATTEMPTS} failures: a failure persisting that
	 * long is likely deterministic, and retrying forever would log forever.
	 * A schema version bump resets the state, so a build that fixes the
	 * migration gets a fresh round of attempts. The state is deleted on
	 * success.
	 */
	private function maybe_install_schema(): void {
		if ( $this->is_schema_installed() ) {
			return;
		}

		$state = $this->get_install_state();

		if ( $state['attempts'] >= self::MAX_INSTALL_ATTEMPTS ) {
			return;
		}

		if ( time() - $state['last_attempt'] < self::INSTALL_RETRY_INTERVAL ) {
			return;
		}

		// Claim the retry slot before attempting, so concurrent requests and
		// repeated failures are bounded to one attempt (and one log entry)
		// per interval.
		++$state['attempts'];
		$state['last_attempt'] = time();
		update_option( self::DB_INSTALL_STATE_OPTION, $state, false );

		try {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';

			$schemas = array(
				$this->get_sessions_table_name() => $this->get_sessions_table_schema(),
				$this->get_rules_table_name()    => $this->get_rules_table_schema(),
			);

			// Collect each table's database errors as dbDelta runs:
			// `$wpdb->last_error` cannot report them afterwards, because every
			// query (the other table's dbDelta queries, the verification
			// queries below) resets it. wpdb also appends every failed query
			// to the cumulative `$EZSQL_ERROR` global, so slicing that around
			// each dbDelta call yields exactly the errors that call produced.
			$db_errors = array();
			foreach ( $schemas as $table => $schema ) {
				$error_count = count( $this->get_query_errors() );
				$this->legacy_proxy->call_function( 'dbDelta', $schema );
				$db_errors[ $table ] = implode( ' | ', array_filter( array_slice( $this->get_query_errors(), $error_count ) ) );
			}

			$wpdb = $this->legacy_proxy->get_global( 'wpdb' );

			// dbDelta executes its queries without checking their results, so a
			// failed CREATE or ALTER is silent: we'll verify the outcome before
			// recording the schema version as installed.
			//
			// Table existence alone covers fresh installs (CREATE TABLE is atomic,
			// a created table has all its columns) but not upgrades, where dbDelta
			// ALTERs the pre-existing table column by column, so every schema-declared
			// column is verified too.
			//
			// Indexes are verified by name only: a missing index is never
			// ignored, since one added by a migration can carry correctness
			// semantics (e.g. a UNIQUE constraint), not just performance.
			//
			// Definitions - column types, signedness, nullability, index
			// uniqueness and column lists - are deliberately not compared.
			// Their DESCRIBE/SHOW INDEX representation is server- and
			// version-sensitive (e.g. integer display widths on MariaDB vs
			// MySQL 8), so a false mismatch would permanently withhold the
			// version stamp here. And the only remedy this loop has is
			// re-running dbDelta, which cannot repair every definition drift
			// (it never issues nullability-only changes, and its own type
			// comparison has the same cross-server gaps), so a flagged
			// definition could retry futilely until the give-up. Presence by
			// name is exactly the invariant dbDelta can always restore and
			// that compares equal on any server.
			foreach ( $schemas as $table => $schema ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
					$this->record_failed_attempt( $state, $db_errors[ $table ] );
					FraudProtectionController::log(
						'error',
						sprintf( 'Table creation failed: %s does not exist after dbDelta.', $table ),
						array(
							'event_source'    => 'schema_manager',
							'schema_db_error' => $db_errors[ $table ],
							'attempts'        => $state['attempts'],
						),
						true
					);
					return;
				}

				$missing_columns = array_diff( $this->get_schema_column_names( $schema ), $this->get_table_column_names( $table ) );
				if ( array() !== $missing_columns ) {
					$this->record_failed_attempt( $state, $db_errors[ $table ] );
					FraudProtectionController::log(
						'error',
						sprintf( 'Table upgrade failed: %s is missing columns after dbDelta: %s.', $table, implode( ', ', $missing_columns ) ),
						array(
							'event_source'    => 'schema_manager',
							'schema_db_error' => $db_errors[ $table ],
							'attempts'        => $state['attempts'],
						),
						true
					);
					return;
				}

				$missing_indexes = array_diff( $this->get_schema_index_names( $schema ), $this->get_table_index_names( $table ) );
				if ( array() !== $missing_indexes ) {
					$this->record_failed_attempt( $state, $db_errors[ $table ] );
					FraudProtectionController::log(
						'error',
						sprintf( 'Table upgrade failed: %s is missing indexes after dbDelta: %s.', $table, implode( ', ', $missing_indexes ) ),
						array(
							'event_source'    => 'schema_manager',
							'schema_db_error' => $db_errors[ $table ],
							'attempts'        => $state['attempts'],
						),
						true
					);
					return;
				}
			}

			update_option( self::DB_VERSION_OPTION, self::SCHEMA_VERSION );
			delete_option( self::DB_INSTALL_STATE_OPTION );

			FraudProtectionController::log(
				'info',
				sprintf( 'Database schema installed (version %d).', self::SCHEMA_VERSION )
			);
		} catch ( \Throwable $e ) {
			$this->record_failed_attempt( $state, $e->getMessage() );
			FraudProtectionController::log(
				'error',
				'Database schema installation failed',
				array(
					'event_source'      => 'schema_manager',
					'attempts'          => $state['attempts'],
					'exception'         => $e,
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
		}
	}

	/**
	 * Get the schema installation retry state.
	 *
	 * @return array{schema_version: int, attempts: int, last_attempt: int, last_error: string}
	 */
	private function get_install_state(): array {
		$state = get_option( self::DB_INSTALL_STATE_OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		// A version bump means a new build, possibly one that fixes the
		// migration: forget the failure history and start a fresh round.
		if ( self::SCHEMA_VERSION !== (int) ( $state['schema_version'] ?? 0 ) ) {
			$state = array();
		}

		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'attempts'       => max( 0, (int) ( $state['attempts'] ?? 0 ) ),
			'last_attempt'   => max( 0, (int) ( $state['last_attempt'] ?? 0 ) ),
			'last_error'     => (string) ( $state['last_error'] ?? '' ),
		);
	}

	/**
	 * Persist the database error of a failed attempt into the retry state.
	 *
	 * The attempt count and timestamp were already persisted when the slot
	 * was claimed; only the error is added here.
	 *
	 * @param array  $state The retry state claimed for this attempt.
	 * @param string $error The error message from the failed attempt.
	 */
	private function record_failed_attempt( array $state, string $error ): void {
		$state['last_error'] = $error;
		update_option( self::DB_INSTALL_STATE_OPTION, $state, false );
	}

	/**
	 * Get the error strings of every failed database query so far in the request.
	 *
	 * `$wpdb->last_error` only holds the error of the most recent query
	 * (every query resets it, successful ones included), so it cannot report
	 * a dbDelta failure once any later query has run. wpdb also appends each
	 * failed query to the `$EZSQL_ERROR` global, which is cumulative for the
	 * whole request and immune to that wiping: counting its entries before a
	 * dbDelta call and slicing after yields exactly that call's errors.
	 *
	 * `$EZSQL_ERROR` is not formally documented as public API, but it has
	 * behaved identically since WP 0.71, core writes it solely for external
	 * consumers (nothing in core reads it), and WPCS/Plugin Check list it as
	 * a protected WordPress global. The reliance here is diagnostics-only
	 * anyway: if it ever stopped being populated, the recorded errors would
	 * come back empty while the schema verification itself is unaffected.
	 *
	 * @return string[] One error string per failed query, oldest first.
	 */
	private function get_query_errors(): array {
		// wpdb only creates the global on the first failed query; default it
		// so it is readable through the proxy before any failure has happened.
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['EZSQL_ERROR'] ??= array();

		$errors = $this->legacy_proxy->get_global( 'EZSQL_ERROR' );
		if ( ! is_array( $errors ) ) {
			return array();
		}

		return array_map(
			static function ( $error ): string {
				return is_array( $error ) ? (string) ( $error['error_str'] ?? '' ) : '';
			},
			array_values( $errors )
		);
	}

	/**
	 * Get the column names a dbDelta schema string declares.
	 *
	 * Column definition lines start with the column name; every other line of
	 * the statement (`CREATE TABLE`, index definitions, the closing
	 * parenthesis) starts with a keyword or punctuation and is skipped.
	 *
	 * @param string $schema The dbDelta CREATE TABLE statement.
	 * @return string[] The declared column names.
	 */
	private function get_schema_column_names( string $schema ): array {
		$columns = array();

		foreach ( explode( "\n", $schema ) as $line ) {
			if ( 1 !== preg_match( '/^\s*(\w+)\s/', $line, $matches ) ) {
				continue;
			}
			if ( in_array( strtoupper( $matches[1] ), self::NON_COLUMN_LINE_KEYWORDS, true ) ) {
				continue;
			}
			$columns[] = $matches[1];
		}

		return $columns;
	}

	/**
	 * Get the column names of an existing table.
	 *
	 * @param string $table The table name.
	 * @return string[] The column names.
	 */
	private function get_table_column_names( string $table ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$columns = $this->legacy_proxy->get_global( 'wpdb' )->get_col( 'SHOW COLUMNS FROM ' . $table );

		return is_array( $columns ) ? $columns : array();
	}

	/**
	 * Get the index names a dbDelta schema string declares.
	 *
	 * Index definition lines start with `PRIMARY KEY`, `KEY` or `UNIQUE KEY`
	 * (or their FULLTEXT/SPATIAL/INDEX variants). The primary key has no name
	 * of its own and is reported as `PRIMARY`, mirroring `SHOW INDEX` output.
	 * Names are lowercased for comparison.
	 *
	 * @param string $schema The dbDelta CREATE TABLE statement.
	 * @return string[] The declared index names, lowercased.
	 */
	private function get_schema_index_names( string $schema ): array {
		$indexes = array();

		foreach ( explode( "\n", $schema ) as $line ) {
			if ( 1 === preg_match( '/^\s*PRIMARY\s+KEY/i', $line ) ) {
				$indexes[] = 'primary';
				continue;
			}
			if ( 1 === preg_match( '/^\s*(?:UNIQUE\s+|FULLTEXT\s+|SPATIAL\s+)?(?:KEY|INDEX)\s+`?(\w+)`?/i', $line, $matches ) ) {
				$indexes[] = strtolower( $matches[1] );
			}
		}

		return $indexes;
	}

	/**
	 * Get the index names of an existing table.
	 *
	 * One name per index: `SHOW INDEX` returns one row per indexed column, so
	 * multi-column indexes are deduplicated. Names are lowercased for
	 * comparison; the primary key is reported as `primary`.
	 *
	 * @param string $table The table name.
	 * @return string[] The index names, lowercased.
	 */
	private function get_table_index_names( string $table ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->legacy_proxy->get_global( 'wpdb' )->get_results( 'SHOW INDEX FROM ' . $table, ARRAY_A );
		if ( ! is_array( $rows ) ) {
			return array();
		}

		$names = array();
		foreach ( $rows as $row ) {
			$name = is_array( $row ) ? ( $row['Key_name'] ?? null ) : null;
			if ( is_string( $name ) && '' !== $name ) {
				$names[] = strtolower( $name );
			}
		}

		return array_values( array_unique( $names ) );
	}

	/**
	 * Get the dbDelta schema for the sessions table.
	 *
	 * One row per recorded verify event, plain-inserted: `session_id` is
	 * indexed but not unique (repeated session IDs keep separate rows, and it
	 * is nullable for the rare no-session verify). Enum-valued columns hold
	 * the backing values of string-backed PHP enums (`FraudDecision`,
	 * `SessionFinalStatus`, `SessionTrigger`). The trigger column is named
	 * `trigger_type` because `trigger` is a MySQL reserved word.
	 *
	 * `metadata` is reserved for gateway-supplied per-session data (JSON
	 * object, `LONGTEXT` per WooCommerce core's convention for JSON blobs).
	 * Nothing writes it yet: the collection mechanism will be added later.
	 *
	 * `matched_rule_id` is the id of the merchant rule that decided the
	 * outcome, for both allow and block results, or `NULL` when no rule
	 * matched. Indexed to support a "sessions affected by this rule" filter.
	 * Rule deletion is a soft delete, so the id never dangles and no
	 * referential integrity is needed.
	 *
	 * Indexes on text columns are capped at 191 chars (WooCommerce core's
	 * `$max_index_length`): under utf8mb4 that is 764 bytes, within the
	 * 767-byte InnoDB index limit of the oldest MySQL versions WooCommerce
	 * supports.
	 *
	 * @return string
	 */
	public function get_sessions_table_schema(): string {
		$table   = $this->get_sessions_table_name();
		$collate = $this->legacy_proxy->get_global( 'wpdb' )->get_charset_collate();

		return "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	session_id VARCHAR(64) NULL,
	recorded_at DATETIME NOT NULL,
	source VARCHAR(32) NOT NULL DEFAULT '',
	decision VARCHAR(16) NOT NULL,
	final_status VARCHAR(32) NOT NULL,
	trigger_type VARCHAR(16) NOT NULL,
	risk_score DOUBLE NULL,
	email VARCHAR(254) NOT NULL DEFAULT '',
	ip VARCHAR(45) NOT NULL DEFAULT '',
	ip_country VARCHAR(2) NOT NULL DEFAULT '',
	billing_country VARCHAR(2) NOT NULL DEFAULT '',
	billing_state VARCHAR(100) NOT NULL DEFAULT '',
	billing_city VARCHAR(100) NOT NULL DEFAULT '',
	billing_postcode VARCHAR(20) NOT NULL DEFAULT '',
	billing_name VARCHAR(255) NOT NULL DEFAULT '',
	order_id BIGINT UNSIGNED NULL,
	payment_method VARCHAR(64) NOT NULL DEFAULT '',
	matched_rule_id BIGINT UNSIGNED NULL,
	metadata LONGTEXT NULL,
	reported_at DATETIME NULL,
	PRIMARY KEY  (id),
	KEY session_id (session_id),
	KEY email (email(191)),
	KEY recorded_at (recorded_at),
	KEY matched_rule_id (matched_rule_id)
) {$collate};";
	}

	/**
	 * Get the dbDelta schema for the merchant rules table. One row
	 * per merchant rule (allow and block rules in a single ordered ruleset).
	 *
	 * `action` and `status` hold the backing values of the `FraudDecision` and
	 * `RuleStatus` enums, `conditions` is the JSON condition document
	 * (validated at write time, evaluated in PHP, never queried in SQL) and
	 * `condition_hash` is the SHA-256 of its normalized form, unique so
	 * duplicate rules are rejected at insert time. The hash is nullable only
	 * for soft-deleted rows: it is set to `NULL` on deletion (MySQL unique
	 * keys admit multiple `NULL`s) so uniqueness constrains live rules only.
	 *
	 * `position` is the evaluation order (lower first) and deliberately has
	 * no database default: an insert that forgets to set it must fail loudly
	 * rather than silently land at top priority.
	 *
	 * `action_meta` (future outcome parameters), `source_meta` (creation-time
	 * context captured when the rule is created from a session row) and
	 * `source_session_id` (the Blackbox session the rule was created from)
	 * are nullable; sessions are pruned while rules never expire, so
	 * `source_meta` preserves the rule's provenance after the source session
	 * row is gone.
	 *
	 * @return string
	 */
	public function get_rules_table_schema(): string {
		$table   = $this->get_rules_table_name();
		$collate = $this->legacy_proxy->get_global( 'wpdb' )->get_charset_collate();

		return "CREATE TABLE {$table} (
	id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
	action VARCHAR(16) NOT NULL,
	status VARCHAR(16) NOT NULL DEFAULT 'active',
	position INT NOT NULL,
	conditions LONGTEXT NOT NULL,
	condition_hash CHAR(64) NULL,
	action_meta LONGTEXT NULL,
	source_meta LONGTEXT NULL,
	created_at DATETIME NOT NULL,
	created_by BIGINT UNSIGNED NULL,
	updated_at DATETIME NULL,
	updated_by BIGINT UNSIGNED NULL,
	source_session_id VARCHAR(64) NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY condition_hash (condition_hash),
	KEY status_position (status, position)
) {$collate};";
	}
}
