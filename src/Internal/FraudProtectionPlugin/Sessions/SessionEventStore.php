<?php
/**
 * SessionEventStore class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;

defined( 'ABSPATH' ) || exit;

/**
 * Persistence for recorded session events (the sessions log).
 *
 * One row per Blackbox session: `record_event()` upserts on `session_id`,
 * incrementing the `attempts` counter and refreshing `last_seen` on repeats.
 * Events with no session ID cannot be deduplicated and insert a new row each
 * time (the unique index allows multiple NULLs).
 */
class SessionEventStore {

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
	 * Record a session event, upserting by session ID.
	 *
	 * On a repeated session ID the volatile fields (verdict, final status,
	 * trigger, contact and billing data, order ID, source) take the latest
	 * value, `attempts` is incremented, `last_seen` is refreshed, and
	 * `first_seen` is preserved. A null risk score or order ID never
	 * overwrites a previously recorded value.
	 *
	 * @param array<string, mixed> $event The event data to record: session_id, source, verdict, final_status,
	 *                                    trigger_type, risk_score (nullable float), email, ip, ip_country,
	 *                                    billing_country/state/city/postcode/name, order_id and payment_method.
	 * @return bool True on success, false on database failure.
	 */
	public function record_event( array $event ): bool {
		global $wpdb;

		$table = $this->schema_manager->get_sessions_table_name();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$columns = array(
			'session_id'       => '' === $event['session_id'] ? null : $event['session_id'],
			'first_seen'       => $now,
			'last_seen'        => $now,
			'source'           => $event['source'],
			'verdict'          => $event['verdict'],
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
			VALUES (' . implode( ', ', $placeholders ) . ')
			ON DUPLICATE KEY UPDATE
				last_seen = VALUES(last_seen),
				attempts = attempts + 1,
				source = VALUES(source),
				verdict = VALUES(verdict),
				final_status = VALUES(final_status),
				trigger_type = VALUES(trigger_type),
				risk_score = COALESCE(VALUES(risk_score), risk_score),
				email = VALUES(email),
				ip = VALUES(ip),
				ip_country = VALUES(ip_country),
				billing_country = VALUES(billing_country),
				billing_state = VALUES(billing_state),
				billing_city = VALUES(billing_city),
				billing_postcode = VALUES(billing_postcode),
				billing_name = VALUES(billing_name),
				order_id = COALESCE(VALUES(order_id), order_id),
				payment_method = VALUES(payment_method)';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return false !== $wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	/**
	 * Get a recorded event row by its Blackbox session ID.
	 *
	 * @param string $session_id The Blackbox session ID.
	 * @return ?array<string, mixed> The row as an associative array, or null if not found.
	 */
	public function get_by_session_id( string $session_id ): ?array {
		global $wpdb;

		$table = $this->schema_manager->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE session_id = %s", $session_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Count the recorded event rows.
	 *
	 * @return int
	 */
	public function count_events(): int {
		global $wpdb;

		$table = $this->schema_manager->get_sessions_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/**
	 * Delete event rows whose `last_seen` is older than the given number of days.
	 *
	 * Deletes in batches to keep individual queries small.
	 *
	 * @param int $days Retention period in days.
	 * @return int The number of rows deleted.
	 */
	public function prune_older_than( int $days ): int {
		global $wpdb;

		$table  = $this->schema_manager->get_sessions_table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$total  = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s LIMIT 1000", $cutoff ) );
			if ( false === $deleted ) {
				break;
			}
			$total += (int) $deleted;
		} while ( 1000 <= $deleted );

		return $total;
	}
}
