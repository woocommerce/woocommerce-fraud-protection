<?php
/**
 * Seed example merchant rules for local development.
 *
 * Run it from the host:
 *
 *   npm run env -- run cli wp eval-file \
 *     wp-content/plugins/woocommerce-fraud-protection/bin/seed-fake-rules.php
 *   npm run env -- run cli wp eval-file \
 *     wp-content/plugins/woocommerce-fraud-protection/bin/seed-fake-rules.php -- reset
 *
 * The examples use reserved test values and are safe to use on a local site.
 *
 * @package WooCommerce\FraudProtection
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WP-CLI eval-file script, not a loaded plugin file.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$wcfp_container      = wc_get_container();
$wcfp_schema_manager = $wcfp_container->get( Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager::class );

if ( ! $wcfp_schema_manager->is_schema_installed() ) {
	WP_CLI::error( 'Rules data is not available. Install the Fraud Protection database schema first.' );
}

$wcfp_rule_store = $wcfp_container->get( Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore::class );
$wcfp_marker     = 'wcfp_pr1_fake_rules_v1';
$wcfp_rules      = array(
	'allow-email' => array(
		'action'     => Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision::Allow,
		'conditions' => array(
			'field'    => 'email',
			'operator' => 'equals',
			'value'    => 'allow@example.test',
		),
	),
	'block-email' => array(
		'action'     => Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision::Block,
		'conditions' => array(
			'field'    => 'email',
			'operator' => 'equals',
			'value'    => 'block@example.test',
		),
	),
	'allow-ip'    => array(
		'action'     => Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision::Allow,
		'conditions' => array(
			'field'    => 'ip',
			'operator' => 'equals',
			'value'    => '192.0.2.10',
		),
	),
	'block-ip'    => array(
		'action'     => Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision::Block,
		'conditions' => array(
			'field'    => 'ip',
			'operator' => 'equals',
			'value'    => '198.51.100.10',
		),
	),
);

$wcfp_existing = array();
foreach ( $wcfp_rule_store->get_active_rules() as $wcfp_rule ) {
	if ( ! is_array( $wcfp_rule->source_meta ) || ! hash_equals( $wcfp_marker, (string) ( $wcfp_rule->source_meta['seed_marker'] ?? '' ) ) ) {
		continue;
	}

	$wcfp_key = $wcfp_rule->source_meta['seed_rule'] ?? null;
	if ( is_string( $wcfp_key ) ) {
		$wcfp_existing[ $wcfp_key ] = true;
	}
}

$wcfp_command = isset( $args[0] ) ? trim( (string) $args[0] ) : '';
if ( 'reset' === $wcfp_command ) {
	$wcfp_deleted = 0;
	foreach ( $wcfp_rule_store->get_active_rules() as $wcfp_rule ) {
		if ( is_array( $wcfp_rule->source_meta ) && hash_equals( $wcfp_marker, (string) ( $wcfp_rule->source_meta['seed_marker'] ?? '' ) ) && $wcfp_rule_store->delete_rule( $wcfp_rule->id ) ) {
			++$wcfp_deleted;
		}
	}

	WP_CLI::success( sprintf( 'Reset %d example rules.', $wcfp_deleted ) );
	exit( 0 );
}

if ( '' !== $wcfp_command ) {
	WP_CLI::error( 'Use reset or omit the argument.' );
}

$wcfp_created = 0;
foreach ( $wcfp_rules as $wcfp_key => $wcfp_definition ) {
	if ( isset( $wcfp_existing[ $wcfp_key ] ) ) {
		continue;
	}

	$wcfp_rule_store->create_rule(
		$wcfp_definition['action'],
		$wcfp_definition['conditions'],
		null,
		array(
			'seed_marker' => $wcfp_marker,
			'seed_rule'   => $wcfp_key,
		)
	);
	++$wcfp_created;
}

WP_CLI::success( sprintf( 'Seeded %d example rules.', $wcfp_created ) );
