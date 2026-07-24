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
 * One row per recorded verify event: `record_event()` plain-inserts every
 * event, so repeated session IDs keep one row each and a decision change
 * across repeated attempts is never lost. Attempt counts are a read-time
 * aggregate, not a stored column.
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
	 * Record a session event as a new row.
	 *
	 * Every event is a plain insert: repeated session IDs are not folded, so
	 * each attempt keeps its own decision and final status.
	 *
	 * @param array<string, mixed> $event The event data to record: session_id, source, decision, final_status,
	 *                                    trigger_type, risk_score (nullable float), email, ip, ip_country,
	 *                                    billing_country/state/city/postcode/name, order_id and payment_method.
	 * @return bool True on success, false on database failure.
	 */
	public function record_event( array $event ): bool {
		global $wpdb;

		$table = $this->schema_manager->get_sessions_table_name();

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
	 * Delete event rows whose `recorded_at` is older than the given number of days.
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
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE recorded_at < %s LIMIT 1000", $cutoff ) );
			if ( false === $deleted ) {
				break;
			}
			$total += (int) $deleted;
		} while ( 1000 <= $deleted );

		return $total;
	}
}
