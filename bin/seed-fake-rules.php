<?php
/**
 * Seed matching merchant rules so the checkout-attempts list shows rule chips.
 *
 * A development helper that reads emails and IP addresses already present in the
 * sessions table and creates active `equals` merchant rules targeting a few of
 * them - a mix of allow and block, on both the email and IP fields - so the
 * per-value rule chips (and the "Allowed/Blocked by rules" filter) light up on
 * the matching rows. Run the session seeder (bin/seed-fake-sessions.php) first.
 *
 * Copy this file to the target host (anywhere the `wp` command can read it, but
 * NOT under mu-plugins/ or plugins/, which auto-load too early) and run it with
 * WP-CLI from the WordPress install:
 *
 *   wp eval-file seed-fake-rules.php [per_field] [reset]
 *
 *   per_field  How many emails and how many IPs to target. Default 4 (so up to
 *              8 rules). Alternates block/allow within each field.
 *   reset      Pass the literal word `reset` to first delete the rules this
 *              script previously created (tagged via source_session_id).
 *
 * Examples:
 *   wp eval-file seed-fake-rules.php               # up to 4 email + 4 IP rules
 *   wp eval-file seed-fake-rules.php 6             # up to 6 of each
 *   wp eval-file seed-fake-rules.php 4 reset       # remove prior fake rules, reseed
 *
 * Rules are matched against the recorded values as-is, so chips appear only for
 * rows whose email/IP a rule targets. The rows' historical Status is unchanged:
 * the chips reflect current rules, the badge reflects what happened at the time.
 *
 * This is a dev/diagnostic utility. It only ever removes rows it created itself
 * (via the `wcfp_fake_seed` marker), never merchant rules made in the UI.
 *
 * @package WooCommerce\FraudProtection
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WP-CLI eval-file script, not a loaded plugin file.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct writes are the whole point of a seeding script.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding does not cache.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Only our own table names are interpolated.

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

global $wpdb;

$args      = isset( $args ) && is_array( $args ) ? $args : array();
$per_field = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 4;
$reset     = in_array( 'reset', $args, true );

$sessions_table = $wpdb->prefix . 'wc_fraud_protection_sessions';
$rules_table    = $wpdb->prefix . 'wc_fraud_protection_rules';

// The tag stored in source_session_id so `reset` can find rows this script made.
$seed_marker = 'wcfp_fake_seed';

foreach ( array( $sessions_table, $rules_table ) as $required_table ) {
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $required_table ) ) ) !== $required_table ) {
		WP_CLI::error(
			"Table {$required_table} does not exist. Enable merchant-facing features and install the schema first:\n" .
			'  wp wc fraud-protection database install'
		);
	}
}

if ( $reset ) {
	$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$rules_table} WHERE source_session_id = %s", $seed_marker ) );
	WP_CLI::log( sprintf( 'Deleted %d previously seeded rule(s).', is_numeric( $deleted ) ? (int) $deleted : 0 ) );
}

// Only target values that appear on rows the list actually shows (last 30 days).
$cutoff = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

$emails = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT email FROM {$sessions_table} WHERE email <> '' AND recorded_at >= %s ORDER BY email LIMIT %d",
		$cutoff,
		$per_field
	)
);
$ips    = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT DISTINCT ip FROM {$sessions_table} WHERE ip <> '' AND recorded_at >= %s ORDER BY ip LIMIT %d",
		$cutoff,
		$per_field
	)
);

if ( empty( $emails ) && empty( $ips ) ) {
	WP_CLI::error( 'No session emails or IPs found in the last 30 days. Seed sessions first with seed-fake-sessions.php.' );
}

// Build the target list: alternate block/allow within each field so the list
// shows both chip styles. IPs start with allow so the two fields differ.
$rule_targets = array();
foreach ( array_values( $emails ) as $i => $email ) {
	$rule_targets[] = array(
		'field'  => 'email',
		'action' => ( 0 === $i % 2 ) ? 'block' : 'allow',
		'raw'    => (string) $email,
	);
}
foreach ( array_values( $ips ) as $i => $ip ) {
	$rule_targets[] = array(
		'field'  => 'ip',
		'action' => ( 0 === $i % 2 ) ? 'allow' : 'block',
		'raw'    => (string) $ip,
	);
}

// New rules go after any existing ones in evaluation order.
$position = ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(position), 0) FROM {$rules_table} WHERE status <> %s", 'deleted' ) ) ) + 1;

$format  = array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s' );
$created = 0;
$skipped = 0;
$now     = gmdate( 'Y-m-d H:i:s' );

foreach ( $rule_targets as $target ) {
	// Normalize the value exactly as RuleConditions does, so it keys the same
	// way the finder normalizes the session's value when matching.
	if ( 'email' === $target['field'] ) {
		$value = strtolower( trim( $target['raw'] ) );
		if ( '' === $value || mb_strlen( $value ) > 254 || 1 !== preg_match( '/^\S+@\S+$/', $value ) ) {
			continue;
		}
	} else {
		if ( false === filter_var( trim( $target['raw'] ), FILTER_VALIDATE_IP ) ) {
			continue;
		}
		$packed = inet_pton( trim( $target['raw'] ) );
		if ( false === $packed ) {
			continue;
		}
		$value = (string) inet_ntop( $packed );
	}

	$conditions = array(
		'field'    => $target['field'],
		'operator' => 'equals',
		'value'    => $value,
	);
	ksort( $conditions );
	$hash = hash( 'sha256', (string) wp_json_encode( $conditions ) );

	// A rule with these exact conditions already exists (a prior run, or a real
	// merchant rule): the value is already targeted, so skip it.
	if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$rules_table} WHERE condition_hash = %s", $hash ) ) ) {
		++$skipped;
		continue;
	}

	$data = array(
		'action'            => $target['action'],
		'status'            => 'active',
		'position'          => $position,
		'conditions'        => (string) wp_json_encode( $conditions ),
		'condition_hash'    => $hash,
		'action_meta'       => null,
		'source_meta'       => (string) wp_json_encode( array( 'fake_seed' => true ) ),
		'created_at'        => $now,
		'created_by'        => null,
		'updated_at'        => null,
		'updated_by'        => null,
		'source_session_id' => $seed_marker,
	);

	if ( false !== $wpdb->insert( $rules_table, $data, $format ) ) {
		++$created;
		WP_CLI::log( sprintf( '  %-5s %-5s rule #%d -> %s', $target['action'], $target['field'], (int) $wpdb->insert_id, $value ) );
		++$position;
	}
}

// The active-rule set is object-cached (RuleStore: key `active_rules`, group
// `wc_fraud_protection`); clear it so the list sees the new rules immediately,
// even behind a persistent object cache.
wp_cache_delete( 'active_rules', 'wc_fraud_protection' );

WP_CLI::log( sprintf( 'Created %d rule(s); skipped %d already-targeted value(s).', $created, $skipped ) );
WP_CLI::success( 'Done. Hard-refresh the checkout attempts list to see the rule chips.' );
