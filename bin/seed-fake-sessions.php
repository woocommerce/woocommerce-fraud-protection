<?php
/**
 * Seed the Fraud Protection sessions table with fake checkout-attempt rows.
 *
 * A development helper for filling the checkout-attempts list UI with a
 * realistic spread of rows: every merchant-facing outcome, varied payment
 * methods, emails, IP addresses, countries and billing details, spread across
 * the last 30 days. It writes directly into the sessions table, mirroring the
 * columns that SessionEventRecorder produces for a real verify.
 *
 * Copy this file to the target host (anywhere the `wp` command can read it) and
 * run it from the WordPress install with WP-CLI:
 *
 *   wp eval-file seed-fake-sessions.php [count] [reset]
 *
 *   count  Number of rows to insert. Default 40.
 *   reset  Pass the literal word `reset` to delete existing rows first.
 *
 * Examples:
 *   wp eval-file seed-fake-sessions.php            # 40 rows, appended
 *   wp eval-file seed-fake-sessions.php 100        # 100 rows, appended
 *   wp eval-file seed-fake-sessions.php 60 reset   # delete all rows, then 60
 *
 * It needs only WordPress and the sessions table, not the plugin's PHP classes,
 * so it runs from any path the `wp` command can read - no npm, wp-env, Composer
 * or plugin checkout required on the host.
 *
 * The sessions table must already exist (merchant-facing features enabled and
 * the schema installed). If it is missing, install it first:
 *
 *   wp wc fraud-protection database install
 *
 * This is a dev/diagnostic utility. `reset` deletes every existing session row
 * (including real ones), so use it with care, and never run this on a store
 * whose recorded sessions you need to keep.
 *
 * @package WooCommerce\FraudProtection
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WP-CLI eval-file script, not a loaded plugin file.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery -- Direct writes are the whole point of a seeding script.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding does not cache.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- Only our own table name is interpolated.

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

global $wpdb;

$args  = isset( $args ) && is_array( $args ) ? $args : array();
$count = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 40;
$reset = in_array( 'reset', $args, true );

$table = $wpdb->prefix . 'wc_fraud_protection_sessions';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
	WP_CLI::error(
		"The sessions table ({$table}) does not exist. Enable merchant-facing features and install the schema first:\n" .
		'  wp wc fraud-protection database install'
	);
}

if ( $reset ) {
	// DELETE rather than TRUNCATE: it needs no extra privileges, so it works on
	// locked-down managed hosts too.
	$deleted = $wpdb->query( 'DELETE FROM ' . $table );
	WP_CLI::log( sprintf( 'Deleted %d existing session row(s).', is_numeric( $deleted ) ? (int) $deleted : 0 ) );
}

/*
 * Outcome recipes. The merchant-facing outcome is derived (not stored) from the
 * trigger, enforced status and received decision by SessionOutcome::from_row():
 *   - allow_rule  trigger                       -> "Allowed by rules"
 *   - block_rule  trigger                       -> "Blocked by rules"
 *   - final_status = blocked (no rule)          -> "Blocked automatically"
 *   - decision = block, allowed (no rule)       -> "Allowed, flagged"
 *   - anything else allowed                     -> "Allowed"
 * `weight` shapes the distribution; `rule` marks rows that carry a matched rule.
 */
$recipes = array(
	array(
		'label'        => 'Allowed',
		'decision'     => 'allow',
		'final_status' => 'allowed',
		'trigger'      => 'blackbox',
		'rule'         => false,
		'weight'       => 12,
	),
	array(
		'label'        => 'Allowed (verify error)',
		'decision'     => 'allow',
		'final_status' => 'allowed',
		'trigger'      => 'verify_error',
		'rule'         => false,
		'weight'       => 3,
	),
	array(
		'label'        => 'Allowed, flagged',
		'decision'     => 'block',
		'final_status' => 'allowed',
		'trigger'      => 'blackbox',
		'rule'         => false,
		'weight'       => 8,
	),
	array(
		'label'        => 'Blocked automatically',
		'decision'     => 'block',
		'final_status' => 'blocked',
		'trigger'      => 'blackbox',
		'rule'         => false,
		'weight'       => 6,
	),
	array(
		'label'        => 'Allowed by rules',
		'decision'     => 'allow',
		'final_status' => 'allowed',
		'trigger'      => 'allow_rule',
		'rule'         => true,
		'weight'       => 4,
	),
	array(
		'label'        => 'Blocked by rules',
		'decision'     => 'block',
		'final_status' => 'blocked',
		'trigger'      => 'block_rule',
		'rule'         => true,
		'weight'       => 4,
	),
);

// Expand the recipes into a weighted pool to pick from per row.
$weighted = array();
foreach ( $recipes as $index => $recipe ) {
	$weighted = array_merge( $weighted, array_fill( 0, $recipe['weight'], $index ) );
}

// The `wfp_demo_*` ids pair with the demo mu-plugin (wfp-demo-gateway-icons.php),
// which registers placeholder gateways carrying WooCommerce's brand icons, so the
// Provider column shows icons. Without that mu-plugin they render as plain text.
// `bacs` and `cod` are real offline gateways with no icon, kept as text examples.
$payment_methods = array(
	'wfp_demo_visa',
	'wfp_demo_mastercard',
	'wfp_demo_amex',
	'wfp_demo_applepay',
	'wfp_demo_googlepay',
	'wfp_demo_ideal',
	'wfp_demo_sepa',
	'wfp_demo_bancontact',
	'wfp_demo_discover',
	'wfp_demo_alipay',
	'bacs',
	'cod',
);
$sources         = array( 'checkout', 'store-api', 'shortcode', 'add-payment-method' );
$domains         = array( 'example.com', 'test.dev', 'shopmail.test', 'inbox.example' );

$people = array(
	array( 'Ava', 'Bennett' ),
	array( 'Liam', 'Carter' ),
	array( 'Noah', 'Diaz' ),
	array( 'Emma', 'Fischer' ),
	array( 'Olivia', 'Grant' ),
	array( 'Mateo', 'Hansen' ),
	array( 'Sofia', 'Ivanova' ),
	array( 'Lucas', 'Jansen' ),
	array( 'Mia', 'Kowalski' ),
	array( 'Yuki', 'Tanaka' ),
);

// Billing/IP samples, IPs from the reserved documentation ranges (never real).
$locations = array(
	array(
		'ip'       => '203.0.113.10',
		'country'  => 'US',
		'state'    => 'CA',
		'city'     => 'San Francisco',
		'postcode' => '94110',
	),
	array(
		'ip'       => '198.51.100.23',
		'country'  => 'GB',
		'state'    => '',
		'city'     => 'London',
		'postcode' => 'EC1A 1BB',
	),
	array(
		'ip'       => '192.0.2.44',
		'country'  => 'DE',
		'state'    => '',
		'city'     => 'Berlin',
		'postcode' => '10115',
	),
	array(
		'ip'       => '203.0.113.77',
		'country'  => 'FR',
		'state'    => '',
		'city'     => 'Paris',
		'postcode' => '75001',
	),
	array(
		'ip'       => '198.51.100.5',
		'country'  => 'ES',
		'state'    => '',
		'city'     => 'Madrid',
		'postcode' => '28001',
	),
	array(
		'ip'       => '192.0.2.130',
		'country'  => 'BR',
		'state'    => 'SP',
		'city'     => 'Sao Paulo',
		'postcode' => '01310-100',
	),
	array(
		'ip'       => '203.0.113.201',
		'country'  => 'CA',
		'state'    => 'ON',
		'city'     => 'Toronto',
		'postcode' => 'M5H 2N2',
	),
	array(
		'ip'       => '198.51.100.240',
		'country'  => 'AU',
		'state'    => 'NSW',
		'city'     => 'Sydney',
		'postcode' => '2000',
	),
);

$format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' );

$tally    = array();
$inserted = 0;

// Real merchant-rule ids by action, so a rule-decided row references a rule that
// actually exists instead of a fabricated id. Empty when the rules table is
// absent or holds no rule of that action; those rows then store a null
// matched_rule_id (the outcome still reads from trigger_type).
$rules_table = $wpdb->prefix . 'wc_fraud_protection_rules';
$rule_ids    = array(
	'allow' => array(),
	'block' => array(),
);
if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rules_table ) ) ) === $rules_table ) {
	foreach ( (array) $wpdb->get_results( 'SELECT id, action FROM ' . $rules_table, ARRAY_A ) as $rule ) {
		$rule_action = (string) ( $rule['action'] ?? '' );
		if ( isset( $rule_ids[ $rule_action ] ) ) {
			$rule_ids[ $rule_action ][] = (int) $rule['id'];
		}
	}
}

for ( $i = 0; $i < $count; $i++ ) {
	$recipe   = $recipes[ $weighted[ wp_rand( 0, count( $weighted ) - 1 ) ] ];
	$person   = $people[ wp_rand( 0, count( $people ) - 1 ) ];
	$location = $locations[ wp_rand( 0, count( $locations ) - 1 ) ];

	$email = strtolower( $person[0] . '.' . $person[1] . '@' . $domains[ wp_rand( 0, count( $domains ) - 1 ) ] );

	// Occasionally place the IP in a different country than billing, a common
	// mismatch signal, otherwise keep them aligned.
	$ip_country = ( 0 === wp_rand( 0, 4 ) )
		? $locations[ wp_rand( 0, count( $locations ) - 1 ) ]['country']
		: $location['country'];

	$risk_score = ( 'block' === $recipe['decision'] )
		? wp_rand( 6500, 9900 ) / 10000
		: wp_rand( 0, 3500 ) / 10000;

	$order_id = ( 'allowed' === $recipe['final_status'] && 1 === wp_rand( 0, 1 ) ) ? wp_rand( 1000, 99999 ) : null;

	// A rule-decided outcome references a real rule of the matching action when
	// one exists; otherwise it records no rule rather than a fabricated id.
	$rule_action     = 'allow_rule' === $recipe['trigger'] ? 'allow' : 'block';
	$matched_rule_id = ( $recipe['rule'] && array() !== $rule_ids[ $rule_action ] )
		? $rule_ids[ $rule_action ][ wp_rand( 0, count( $rule_ids[ $rule_action ] ) - 1 ) ]
		: null;

	$data = array(
		'session_id'       => 'sess_' . bin2hex( random_bytes( 12 ) ),
		'recorded_at'      => gmdate( 'Y-m-d H:i:s', time() - wp_rand( 0, 29 * DAY_IN_SECONDS ) ),
		'source'           => $sources[ wp_rand( 0, count( $sources ) - 1 ) ],
		'decision'         => $recipe['decision'],
		'final_status'     => $recipe['final_status'],
		'trigger_type'     => $recipe['trigger'],
		'risk_score'       => $risk_score,
		'email'            => $email,
		'ip'               => $location['ip'],
		'ip_country'       => $ip_country,
		'billing_country'  => $location['country'],
		'billing_state'    => $location['state'],
		'billing_city'     => $location['city'],
		'billing_postcode' => $location['postcode'],
		'billing_name'     => $person[0] . ' ' . $person[1],
		'order_id'         => $order_id,
		'payment_method'   => $payment_methods[ wp_rand( 0, count( $payment_methods ) - 1 ) ],
		'matched_rule_id'  => $matched_rule_id,
	);

	if ( false !== $wpdb->insert( $table, $data, $format ) ) {
		++$inserted;
		$tally[ $recipe['label'] ] = ( $tally[ $recipe['label'] ] ?? 0 ) + 1;
	}
}

WP_CLI::log( sprintf( 'Inserted %d fake session row(s) into %s:', $inserted, $table ) );
ksort( $tally );
foreach ( $tally as $label => $rows ) {
	WP_CLI::log( sprintf( '  %-24s %d', $label, $rows ) );
}
WP_CLI::log( sprintf( 'Table now holds %d row(s).', (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table ) ) );
WP_CLI::success( 'Done. Open the checkout attempts page (hard-refresh) to see them.' );
