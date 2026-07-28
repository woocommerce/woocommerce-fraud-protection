<?php
/**
 * RuleStoreTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Rules;

use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
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
		$this->schema_manager->init( new MerchantListsFeature(), wc_get_container()->get( LegacyProxy::class ) );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'DROP TABLE IF EXISTS ' . $this->schema_manager->get_rules_table_name() );
		wp_set_current_user( 0 );
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
	 * Get a rule row straight from the table.
	 *
	 * @param int $id The rule id.
	 * @return ?array The row as an associative array, or null if not found.
	 */
	private function row_for( int $id ): ?array {
		global $wpdb;

		$table = $this->schema_manager->get_rules_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
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
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s WHERE id = %d", 'disabled', $rule->id ) );
		$this->assertCount( 1, $this->sut->get_active_rules(), 'The cached ruleset must still be served' );

		$this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'other@example.com' ) );
		$ordered = $this->sut->get_active_rules();
		$this->assertCount( 1, $ordered, 'The write must invalidate the cache, revealing the direct status change' );
		$this->assertSame( 'other@example.com', $ordered[0]->conditions['value'] );
	}

	/**
	 * @testdox Should log and skip active rows that cannot be interpreted as rules.
	 */
	public function test_skips_uninterpretable_rows(): void {
		global $wpdb;

		$this->sut->create_rule( FraudDecision::Block, $this->email_condition( 'someone@example.com' ) );

		$table = $this->schema_manager->get_rules_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (action, status, position, conditions, condition_hash, created_at) VALUES (%s, %s, %d, %s, %s, %s)", 'weird', 'active', 0, 'not json', 'hash-1', gmdate( 'Y-m-d H:i:s' ) ) );
		wp_cache_flush();

		$rules = $this->sut->get_active_rules();

		$this->assertCount( 1, $rules, 'The uninterpretable row must be skipped' );
		$this->assertSame( 'someone@example.com', $rules[0]->conditions['value'] );
		$this->assertLogged( 'warning', 'Skipping uninterpretable rule row' );
	}
}
