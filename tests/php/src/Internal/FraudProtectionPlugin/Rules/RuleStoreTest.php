<?php
/**
 * RuleStoreTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\DuplicateRuleException;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules\RuleStore;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Rule;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\RuleStatus;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the RuleStore class.
 */
class RuleStoreTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var RuleStore
	 */
	private $sut;

	/**
	 * Schema manager used to create the table.
	 *
	 * @var SchemaManager
	 */
	private $schema_manager;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->schema_manager = new SchemaManager();
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ), wc_get_container()->get( FraudProtectionLogger::class ) );

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema_manager->get_rules_table_schema() );

		$this->sut = new RuleStore();
		$this->sut->init( $this->schema_manager );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_rules_table_name() );
		parent::tearDown();
	}

	/**
	 * An email equals condition document.
	 *
	 * @param string $email The email value.
	 * @return array
	 */
	private function email_condition( string $email ): array {
		return array(
			'field'    => 'email',
			'operator' => 'equals',
			'value'    => $email,
		);
	}

	/**
	 * @testdox The merchant rules page filters by action, type, exact value and dates, and orders newest first.
	 */
	public function test_active_rules_page_filters_and_paginates(): void {
		$old_allow      = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'old@example.com' ) );
		$same_day_allow = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'same-day@example.com' ) );
		$new_block      = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'new@example.com' ) );
		$ip_allow       = $this->sut->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '2001:db8::1',
			)
		);
		$disabled_allow = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'disabled@example.com' ) );
		$this->set_created_at( $old_allow->id, '2026-01-01 00:00:00' );
		$this->set_created_at( $same_day_allow->id, '2026-01-01 23:59:59' );
		$this->set_created_at( $new_block->id, '2026-02-01 00:00:00' );
		$this->set_created_at( $ip_allow->id, '2026-01-01 12:00:00' );
		$this->set_created_at( $disabled_allow->id, '2026-01-01 12:00:00' );
		$this->sut->update_rule( $disabled_allow->id, status: RuleStatus::Disabled );

		$page = $this->sut->get_active_rules_page(
			array(
				'action' => 'allow',
				'type'   => 'email',
				'from'   => '2026-01-01 00:00:00',
				'to'     => '2026-01-01 23:59:59',
			),
			1,
			1
		);

		$this->assertSame( 2, $page['total'] );
		$this->assertSame( 2, $page['pages'] );
		$this->assertSame( 'same-day@example.com', $page['items'][0]->conditions['value'] );

		$second_page = $this->sut->get_active_rules_page(
			array(
				'action' => 'allow',
				'type'   => 'email',
				'from'   => '2026-01-01 00:00:00',
				'to'     => '2026-01-01 23:59:59',
			),
			2,
			1
		);

		$this->assertSame( 2, $second_page['total'] );
		$this->assertSame( 2, $second_page['pages'] );
		$this->assertSame( 'old@example.com', $second_page['items'][0]->conditions['value'] );

		$exact_value_page = $this->sut->get_active_rules_page(
			array(
				'type'  => 'email',
				'value' => 'OLD@EXAMPLE.COM',
			)
		);
		$this->assertSame( 1, $exact_value_page['total'] );
		$this->assertSame( 'old@example.com', $exact_value_page['items'][0]->conditions['value'] );

		$email_value_only_page = $this->sut->get_active_rules_page(
			array( 'value' => 'OLD@EXAMPLE.COM' )
		);
		$this->assertSame( 1, $email_value_only_page['total'] );
		$this->assertSame( 'old@example.com', $email_value_only_page['items'][0]->conditions['value'] );

		$ip_value_only_page = $this->sut->get_active_rules_page(
			array( 'value' => '2001:DB8::1' )
		);
		$this->assertSame( 1, $ip_value_only_page['total'] );
		$this->assertSame( '2001:db8::1', $ip_value_only_page['items'][0]->conditions['value'] );
	}

	/**
	 * @testdox The merchant rules page sorts each visible column on the server and uses the rule ID as a tie-breaker.
	 */
	public function test_active_rules_page_sorts_by_requested_column(): void {
		$allow_email = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'zulu@example.com' ) );
		$block_ip    = $this->sut->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '192.0.2.10',
			)
		);
		$block_email = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'alpha@example.com' ) );
		$allow_ip    = $this->sut->create_rule(
			FraudDecision::Allow,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '10.0.0.1',
			)
		);
		$this->set_created_at( $allow_email->id, '2026-01-02 00:00:00' );
		$this->set_created_at( $block_ip->id, '2026-01-03 00:00:00' );
		$this->set_created_at( $block_email->id, '2026-01-01 00:00:00' );
		$this->set_created_at( $allow_ip->id, '2026-01-04 00:00:00' );

		$expected = array(
			'action'     => array(
				'asc'  => array( $allow_email->id, $allow_ip->id, $block_ip->id, $block_email->id ),
				'desc' => array( $block_email->id, $block_ip->id, $allow_ip->id, $allow_email->id ),
			),
			'value'      => array(
				'asc'  => array( $allow_ip->id, $block_ip->id, $block_email->id, $allow_email->id ),
				'desc' => array( $allow_email->id, $block_email->id, $block_ip->id, $allow_ip->id ),
			),
			'type'       => array(
				'asc'  => array( $allow_email->id, $block_email->id, $block_ip->id, $allow_ip->id ),
				'desc' => array( $allow_ip->id, $block_ip->id, $block_email->id, $allow_email->id ),
			),
			'created_at' => array(
				'asc'  => array( $block_email->id, $allow_email->id, $block_ip->id, $allow_ip->id ),
				'desc' => array( $allow_ip->id, $block_ip->id, $allow_email->id, $block_email->id ),
			),
		);

		foreach ( $expected as $orderby => $directions ) {
			foreach ( $directions as $order => $expected_ids ) {
				$page = $this->sut->get_active_rules_page( orderby: $orderby, order: $order );
				$this->assertSame(
					$expected_ids,
					array_map( fn( Rule $rule ) => $rule->id, $page['items'] ),
					"Unexpected {$orderby} {$order} order"
				);
			}
		}

		$fallback = $this->sut->get_active_rules_page( orderby: 'unknown', order: 'sideways' );
		$this->assertSame(
			$expected['created_at']['desc'],
			array_map( fn( Rule $rule ) => $rule->id, $fallback['items'] )
		);
	}

	/**
	 * @testdox Value and type sorting works without database JSON functions.
	 */
	public function test_active_rules_page_sorting_supports_minimum_database_versions(): void {
		$email    = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'zulu@example.com' ) );
		$ip       = $this->sut->create_rule(
			FraudDecision::Block,
			array(
				'field'    => 'ip',
				'operator' => 'equals',
				'value'    => '10.0.0.1',
			)
		);
		$quoted_z = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'quoted-z@example.com' ) );
		$quoted_a = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'quoted-a@example.com' ) );
		$this->set_conditions(
			$quoted_z->id,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'a"z@example.com',
			)
		);
		$this->set_conditions(
			$quoted_a->id,
			array(
				'field'    => 'email',
				'operator' => 'equals',
				'value'    => 'a"a@example.com',
			)
		);

		$reject_json_functions = static function ( string $query ): string {
			if ( preg_match( '/JSON_(?:EXTRACT|UNQUOTE)|SUBSTRING_INDEX|conditions\s+LIKE/i', $query ) ) {
				throw new \RuntimeException( 'Condition JSON cannot be parsed in SQL.' );
			}
			return $query;
		};
		add_filter( 'query', $reject_json_functions );

		try {
			$value_ascending  = $this->sut->get_active_rules_page( orderby: 'value', order: 'asc' );
			$value_descending = $this->sut->get_active_rules_page( orderby: 'value', order: 'desc' );
			$type_page        = $this->sut->get_active_rules_page( orderby: 'type', order: 'asc' );
		} finally {
			remove_filter( 'query', $reject_json_functions );
		}

		$this->assertSame( array( $ip->id, $quoted_a->id, $quoted_z->id, $email->id ), array_map( fn( Rule $rule ) => $rule->id, $value_ascending['items'] ) );
		$this->assertSame( array( $email->id, $quoted_z->id, $quoted_a->id, $ip->id ), array_map( fn( Rule $rule ) => $rule->id, $value_descending['items'] ) );
		$this->assertSame( array( $email->id, $quoted_z->id, $quoted_a->id, $ip->id ), array_map( fn( Rule $rule ) => $rule->id, $type_page['items'] ) );
	}

	/**
	 * Get a rule row straight from the table.
	 *
	 * @param int $id The rule id.
	 * @return ?array The row as an associative array, or null if not found.
	 */
	private function row_for( int $id ): ?array {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Set the creation time for a stored rule.
	 *
	 * @param int    $id         Rule ID.
	 * @param string $created_at UTC database timestamp.
	 */
	private function set_created_at( int $id, string $created_at ): void {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET created_at = %s WHERE id = %d", $created_at, $id ) );
	}

	/**
	 * Replace stored conditions to cover legacy values that current writes reject.
	 *
	 * @param int                  $id         Rule ID.
	 * @param array<string, mixed> $conditions Conditions document.
	 */
	private function set_conditions( int $id, array $conditions ): void {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET conditions = %s WHERE id = %d", wp_json_encode( $conditions ), $id ) );
	}

	/**
	 * @testdox Should create an active rule with normalized conditions, a condition hash and audit data.
	 */
	public function test_creates_rule_with_normalized_conditions(): void {
		$user_id = $this->factory->user->create();
		wp_set_current_user( $user_id );

		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( ' Fraudster@Example.COM ' ), 'session-123' );

		$this->assertSame( FraudDecision::Block, $rule->action );
		$this->assertSame( RuleStatus::Active, $rule->status );
		$this->assertSame( 'fraudster@example.com', $rule->conditions['value'] );
		$this->assertSame( 'session-123', $rule->source_session_id );
		$this->assertSame( $user_id, $rule->created_by );
		$this->assertNotEmpty( $rule->created_at );

		$row = $this->row_for( $rule->id );
		$this->assertSame( 64, strlen( (string) $row['condition_hash'] ), 'The condition hash must be stored' );
	}

	/**
	 * @testdox Should store the source meta as JSON when given.
	 */
	public function test_creates_rule_with_source_meta(): void {
		$rule = $this->sut->create_rule(
			FraudDecision::Allow,
			$this->email_condition( 'good@example.com' ),
			'session-456',
			array(
				'verdict'    => 'block',
				'risk_score' => 0.97,
			)
		);

		$this->assertSame( 'block', $rule->source_meta['verdict'] );
		$this->assertSame( 0.97, $rule->source_meta['risk_score'] );
	}

	/**
	 * @testdox Should reject invalid conditions with an InvalidArgumentException.
	 */
	public function test_create_rejects_invalid_conditions(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->sut->create_rule( FraudDecision::Block, array( 'field' => 'email' ) );
	}

	/**
	 * @testdox Should reject a non-actionable action with an InvalidArgumentException.
	 */
	public function test_create_rejects_non_actionable_action(): void {
		$this->expectException( \InvalidArgumentException::class );

		$this->sut->create_rule( FraudDecision::Challenge, $this->email_condition( 'someone@example.com' ) );
	}

	/**
	 * @testdox Should reject a rule whose normalized conditions duplicate an existing live rule.
	 */
	public function test_create_rejects_duplicate_conditions(): void {
		$existing = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'fraudster@example.com' ) );

		try {
			// Textually different, identical once normalized.
			$this->sut->create_rule( FraudDecision::Allow, $this->email_condition( ' FRAUDSTER@example.com' ) );
			$this->fail( 'A DuplicateRuleException was expected' );
		} catch ( DuplicateRuleException $e ) {
			$this->assertSame( $existing->id, $e->existing_rule_id, 'The exception must carry the id of the existing duplicate rule' );
		}
	}

	/**
	 * @testdox Should seed new allow rules above all block rules and new block rules at the bottom.
	 */
	public function test_seeds_allow_rules_above_block_rules(): void {
		$block_1 = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'bad1@example.com' ) );
		$block_2 = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'bad2@example.com' ) );
		$allow_1 = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'good1@example.com' ) );
		$allow_2 = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'good2@example.com' ) );
		$block_3 = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'bad3@example.com' ) );

		$ordered_ids = array_map(
			fn( Rule $rule ) => $rule->id,
			$this->sut->get_active_rules()
		);

		$this->assertSame(
			array( $allow_1->id, $allow_2->id, $block_1->id, $block_2->id, $block_3->id ),
			$ordered_ids,
			'Allow rules must evaluate before block rules, in creation order within each band'
		);
	}

	/**
	 * @testdox Should soft-delete a rule: kept in the table with a null hash, excluded from the active ruleset.
	 */
	public function test_delete_is_a_soft_delete(): void {
		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'fraudster@example.com' ) );

		$this->assertTrue( $this->sut->delete_rule( $rule->id ) );

		$row = $this->row_for( $rule->id );
		$this->assertSame( 'deleted', $row['status'] );
		$this->assertNull( $row['condition_hash'], 'The condition hash must be nulled on soft delete' );

		$this->assertSame( array(), $this->sut->get_active_rules() );
		$this->assertSame( RuleStatus::Deleted, $this->sut->get_rule( $rule->id )->status, 'The deleted rule must remain readable by id' );
		$this->assertFalse( $this->sut->delete_rule( $rule->id ), 'Deleting an already deleted rule must report false' );
	}

	/**
	 * @testdox Should allow re-creating the conditions of a soft-deleted rule.
	 */
	public function test_deleted_rule_conditions_can_be_recreated(): void {
		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'fraudster@example.com' ) );
		$this->sut->delete_rule( $rule->id );

		$recreated = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'fraudster@example.com' ) );

		$this->assertNotSame( $rule->id, $recreated->id );
	}

	/**
	 * @testdox Should update the action, status and conditions of a rule.
	 */
	public function test_updates_rule(): void {
		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );

		$updated = $this->sut->update_rule(
			$rule->id,
			FraudDecision::Allow,
			$this->email_condition( 'Someone-Else@example.com' ),
			RuleStatus::Disabled
		);

		$this->assertSame( FraudDecision::Allow, $updated->action );
		$this->assertSame( RuleStatus::Disabled, $updated->status );
		$this->assertSame( 'someone-else@example.com', $updated->conditions['value'] );
		$this->assertNotEmpty( $updated->updated_at );
		$this->assertSame( array(), $this->sut->get_active_rules(), 'A disabled rule must not be part of the active ruleset' );
	}

	/**
	 * @testdox Should accept an update that keeps the rule's own conditions unchanged.
	 */
	public function test_update_accepts_own_conditions(): void {
		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );

		$updated = $this->sut->update_rule( $rule->id, FraudDecision::Allow, $this->email_condition( 'someone@example.com' ) );

		$this->assertSame( FraudDecision::Allow, $updated->action );
	}

	/**
	 * @testdox Should reject an update whose conditions duplicate another live rule.
	 */
	public function test_update_rejects_duplicate_conditions(): void {
		$first  = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'first@example.com' ) );
		$second = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'second@example.com' ) );

		try {
			$this->sut->update_rule( $second->id, null, $this->email_condition( 'first@example.com' ) );
			$this->fail( 'A DuplicateRuleException was expected' );
		} catch ( DuplicateRuleException $e ) {
			$this->assertSame( $first->id, $e->existing_rule_id, 'The exception must carry the id of the existing duplicate rule' );
		}
	}

	/**
	 * @testdox Should reject setting the deleted status through update_rule().
	 */
	public function test_update_rejects_deleted_status(): void {
		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );

		$this->expectException( \InvalidArgumentException::class );

		$this->sut->update_rule( $rule->id, null, null, RuleStatus::Deleted );
	}

	/**
	 * @testdox Should report success for an update that changes nothing, not mistake it for a deleted rule.
	 */
	public function test_update_with_no_effective_change_still_returns_the_rule(): void {
		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );

		// Two identical updates within the same second: the second changes no
		// column values, so MySQL reports zero affected rows. It must still
		// report the live rule, not null.
		$this->sut->update_rule( $rule->id, FraudDecision::Allow );
		$updated = $this->sut->update_rule( $rule->id, FraudDecision::Allow );

		$this->assertNotNull( $updated, 'A no-op update of a live rule must not read as not-found' );
		$this->assertSame( FraudDecision::Allow, $updated->action );
	}

	/**
	 * @testdox Should report a soft-deleted or unknown rule as not found on update.
	 */
	public function test_update_reports_missing_rules_as_null(): void {
		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );
		$this->sut->delete_rule( $rule->id );

		$this->assertNull( $this->sut->update_rule( $rule->id, FraudDecision::Allow ) );
		$this->assertNull( $this->sut->update_rule( 99999, FraudDecision::Allow ) );
	}

	/**
	 * @testdox Should serve the active ruleset from cache until a rule write invalidates it.
	 */
	public function test_active_rules_are_cached_and_invalidated_on_write(): void {
		global $wpdb;

		$rule = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );
		$this->assertCount( 1, $this->sut->get_active_rules() );

		// A direct table write, bypassing the store, must not be visible: the
		// ruleset is served from the cache populated above.
		$table = $this->schema_manager->get_rules_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d", 'disabled', $rule->id ) );
		$this->assertCount( 1, $this->sut->get_active_rules(), 'The cached ruleset must still be served' );

		$this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'other@example.com' ) );
		$ordered = $this->sut->get_active_rules();
		$this->assertCount( 1, $ordered, 'The write must invalidate the cache, revealing the direct status change' );
		$this->assertSame( 'other@example.com', $ordered[0]->conditions['value'] );
	}

	/**
	 * @testdox Should count rule creations in cumulative windows regardless of current status.
	 */
	public function test_creation_counts_use_cumulative_windows(): void {
		global $wpdb;

		$rules = array(
			'allow-1d'  => $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'allow-1d@example.com' ) ),
			'allow-7d'  => $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'allow-7d@example.com' ) ),
			'allow-30d' => $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'allow-30d@example.com' ) ),
			'allow-old' => $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'allow-old@example.com' ) ),
			'block-1d'  => $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'block-1d@example.com' ) ),
			'block-7d'  => $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'block-7d@example.com' ) ),
			'block-30d' => $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'block-30d@example.com' ) ),
			'block-old' => $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'block-old@example.com' ) ),
		);

		$this->set_created_at( $rules['allow-7d']->id, gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) ) );
		$this->set_created_at( $rules['allow-30d']->id, gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ) );
		$this->set_created_at( $rules['allow-old']->id, gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) ) );
		$this->set_created_at( $rules['block-7d']->id, gmdate( 'Y-m-d H:i:s', time() - ( 3 * DAY_IN_SECONDS ) ) );
		$this->set_created_at( $rules['block-30d']->id, gmdate( 'Y-m-d H:i:s', time() - ( 20 * DAY_IN_SECONDS ) ) );
		$this->set_created_at( $rules['block-old']->id, gmdate( 'Y-m-d H:i:s', time() - ( 31 * DAY_IN_SECONDS ) ) );
		$this->assertNotNull( $this->sut->update_rule( $rules['allow-7d']->id, status: RuleStatus::Disabled ) );
		$this->assertTrue( $this->sut->delete_rule( $rules['allow-30d']->id ) );

		$queries_before = $wpdb->num_queries;
		$result         = $this->sut->get_creation_counts();

		$this->assertSame( 1, $wpdb->num_queries - $queries_before, 'Rule creation counts must use one query' );
		$this->assertSame(
			array(
				'allow_rules_created_1d'  => 1,
				'allow_rules_created_7d'  => 2,
				'allow_rules_created_30d' => 3,
				'block_rules_created_1d'  => 1,
				'block_rules_created_7d'  => 2,
				'block_rules_created_30d' => 3,
			),
			$result
		);
	}

	/**
	 * @testdox Should return zero rule-creation counts when no rules exist.
	 */
	public function test_creation_counts_return_zeroes_without_rules(): void {
		$this->assertSame(
			array(
				'allow_rules_created_1d'  => 0,
				'allow_rules_created_7d'  => 0,
				'allow_rules_created_30d' => 0,
				'block_rules_created_1d'  => 0,
				'block_rules_created_7d'  => 0,
				'block_rules_created_30d' => 0,
			),
			$this->sut->get_creation_counts()
		);
	}

	/**
	 * @testdox Should throw when the rule-creation aggregate query fails.
	 */
	public function test_creation_counts_throw_on_database_failure(): void {
		global $wpdb;

		$original_wpdb = $wpdb;
		$wpdb          = $this->createMock( \wpdb::class ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Direct database failure boundary.
		$wpdb->method( 'prepare' )->willReturn( 'SELECT failed' );
		$wpdb->expects( $this->once() )->method( 'get_row' )->willReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Rule creation count query failed.' );

		try {
			$this->sut->get_creation_counts();
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test database.
		}
	}

	/**
	 * @testdox Should count only active allow and block rules.
	 */
	public function test_active_counts_include_only_active_rules(): void {
		$this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'active-allow@example.com' ) );
		$disabled_allow = $this->sut->create_rule( FraudDecision::Allow, $this->email_condition( 'disabled-allow@example.com' ) );
		$this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'active-block@example.com' ) );
		$deleted_block = $this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'deleted-block@example.com' ) );

		$this->assertNotNull( $this->sut->update_rule( $disabled_allow->id, status: RuleStatus::Disabled ) );
		$this->assertTrue( $this->sut->delete_rule( $deleted_block->id ) );

		$this->assertSame(
			array(
				'allow_rules_total' => 1,
				'block_rules_total' => 1,
			),
			$this->sut->get_active_counts()
		);
	}

	/**
	 * @testdox Should return zero active rule counts when no rules exist.
	 */
	public function test_active_counts_return_zeroes_without_rules(): void {
		$this->assertSame(
			array(
				'allow_rules_total' => 0,
				'block_rules_total' => 0,
			),
			$this->sut->get_active_counts()
		);
	}

	/**
	 * @testdox Should throw when the active-rule count query fails.
	 */
	public function test_active_counts_throw_on_database_failure(): void {
		global $wpdb;

		$original_wpdb = $wpdb;
		$wpdb          = $this->createMock( \wpdb::class ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Direct database failure boundary.
		$wpdb->method( 'prepare' )->willReturn( 'SELECT failed' );
		$wpdb->expects( $this->once() )->method( 'get_row' )->willReturn( null );

		$this->expectException( \RuntimeException::class );
		$this->expectExceptionMessage( 'Active rule count query failed.' );

		try {
			$this->sut->get_active_counts();
		} finally {
			$wpdb = $original_wpdb; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore the test database.
		}
	}

	/**
	 * @testdox Should log and skip active rows that cannot be interpreted as rules.
	 */
	public function test_skips_uninterpretable_rows(): void {
		global $wpdb;

		$this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );

		$table = $this->schema_manager->get_rules_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (action, status, position, conditions, condition_hash, created_at) VALUES (%s, %s, %d, %s, %s, %s)", 'weird', 'active', 0, 'not json', 'hash-1', gmdate( 'Y-m-d H:i:s' ) ) );
		wp_cache_flush();

		$rules = $this->sut->get_active_rules();

		$this->assertCount( 1, $rules, 'The uninterpretable row must be skipped' );
		$this->assertSame( 'someone@example.com', $rules[0]->conditions['value'] );
		$this->assertLogged( 'warning', 'Skipping uninterpretable rule row' );
	}
}
