<?php
/**
 * SessionEventPrunerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventPruner;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventStore;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the SessionEventPruner class.
 */
class SessionEventPrunerTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var SessionEventPruner
	 */
	private $sut;

	/**
	 * Mock session event store.
	 *
	 * @var SessionEventStore&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $event_store;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->event_store = $this->createMock( SessionEventStore::class );
		$this->sut         = new SessionEventPruner();
		$this->sut->init( $this->event_store, new MerchantListsFeature() );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		delete_option( MerchantListsFeature::OPTION_NAME );
		remove_all_actions( SessionEventPruner::PRUNE_ACTION_HOOK );
		remove_all_actions( 'add_option_' . MerchantListsFeature::OPTION_NAME );
		remove_all_actions( 'update_option_' . MerchantListsFeature::OPTION_NAME );
		remove_all_actions( 'delete_option_' . MerchantListsFeature::OPTION_NAME );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( SessionEventPruner::PRUNE_ACTION_HOOK );
		}
		parent::tearDown();
	}

	/**
	 * @testdox Should prune with the retention period when the feature is enabled.
	 */
	public function test_prunes_when_feature_enabled(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$this->event_store
			->expects( $this->once() )
			->method( 'prune_older_than' )
			->with( SessionEventPruner::RETENTION_DAYS )
			->willReturn( 5 );

		$this->sut->handle_wc_fraud_protection_prune_sessions();

		$this->assertLogged( 'info', 'Pruned 5 session event(s)' );
	}

	/**
	 * @testdox Should not prune when the feature is disabled.
	 */
	public function test_does_not_prune_when_feature_disabled(): void {
		$this->event_store
			->expects( $this->never() )
			->method( 'prune_older_than' );

		$this->sut->handle_wc_fraud_protection_prune_sessions();
	}

	/**
	 * @testdox Should log a warning and not throw when pruning fails.
	 */
	public function test_fails_open_when_pruning_throws(): void {
		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );

		$this->event_store
			->method( 'prune_older_than' )
			->willThrowException( new \RuntimeException( 'database exploded' ) );

		$this->sut->handle_wc_fraud_protection_prune_sessions();

		$this->assertLogged( 'warning', 'Session event pruning failed' );
	}

	/**
	 * @testdox Should register the pruning action callback.
	 */
	public function test_registers_pruning_action(): void {
		$this->sut->register();

		$this->assertNotFalse( has_action( SessionEventPruner::PRUNE_ACTION_HOOK, array( $this->sut, 'handle_wc_fraud_protection_prune_sessions' ) ) );
	}

	/**
	 * @testdox Should schedule the recurring job when the feature option is switched on, and unschedule it when switched off.
	 */
	public function test_option_change_reconciles_the_schedule(): void {
		$this->sut->register();

		$this->assertFalse( as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ), 'Nothing should be scheduled while the feature is off' );

		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );
		$this->assertNotFalse( as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ), 'Enabling the option should schedule the pruning job without an admin request' );

		update_option( MerchantListsFeature::OPTION_NAME, 'no' );
		$this->assertFalse( as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ), 'Disabling the option should unschedule the pruning job' );
	}

	/**
	 * @testdox Should unschedule the recurring job when the feature option is deleted.
	 */
	public function test_option_deletion_reconciles_the_schedule(): void {
		$this->sut->register();

		update_option( MerchantListsFeature::OPTION_NAME, 'yes' );
		$this->assertNotFalse( as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ) );

		delete_option( MerchantListsFeature::OPTION_NAME );
		$this->assertFalse( as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ), 'Deleting the option (a missing option means feature off) should unschedule the pruning job' );
	}
}
