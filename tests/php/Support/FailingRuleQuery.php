<?php
/**
 * FailingRuleQuery class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;

/**
 * Makes one of the rules store read queries fail at the database while a callback runs.
 *
 * Every other query runs normally.
 */
class FailingRuleQuery {

	/**
	 * Run a callback while the active ruleset query fails.
	 *
	 * @param SchemaManager $schema_manager The schema manager that names the rules table.
	 * @param callable      $callback       The code to run.
	 * @return mixed The callback result.
	 */
	public static function active_ruleset( SchemaManager $schema_manager, callable $callback ) {
		return self::fail_matching(
			$schema_manager,
			static fn( string $query ): bool => str_ends_with( $query, 'ORDER BY position, id' ),
			$callback
		);
	}

	/**
	 * Run a callback while the rows query of a rules list page fails.
	 *
	 * The count query of the page still succeeds.
	 *
	 * @param SchemaManager $schema_manager The schema manager that names the rules table.
	 * @param callable      $callback       The code to run.
	 * @return mixed The callback result.
	 */
	public static function list_page_rows( SchemaManager $schema_manager, callable $callback ) {
		return self::fail_matching(
			$schema_manager,
			static fn( string $query ): bool => 1 === preg_match( '/ LIMIT \d+ OFFSET \d+$/', $query ),
			$callback
		);
	}

	/**
	 * Run a callback while the rules table reads accepted by a matcher fail.
	 *
	 * @param SchemaManager $schema_manager The schema manager that names the rules table.
	 * @param callable      $matches        Receives a rules table read query and returns whether it must fail.
	 * @param callable      $callback       The code to run.
	 * @return mixed The callback result.
	 */
	private static function fail_matching( SchemaManager $schema_manager, callable $matches, callable $callback ) {
		global $wpdb;

		$prefix     = 'SELECT * FROM ' . $schema_manager->get_rules_table_name() . ' WHERE status = ';
		$fail_query = static function ( $query ) use ( $prefix, $matches ) {
			return is_string( $query ) && str_starts_with( $query, $prefix ) && $matches( $query )
				? 'SELECT * FROM missing_table_for_failed_rule_query'
				: $query;
		};

		add_filter( 'query', $fail_query );
		$suppressed = $wpdb->suppress_errors();
		try {
			return $callback();
		} finally {
			$wpdb->suppress_errors( $suppressed );
			remove_filter( 'query', $fail_query );
		}
	}
}
