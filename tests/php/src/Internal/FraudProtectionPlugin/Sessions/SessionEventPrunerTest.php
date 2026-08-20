<?php
/**
 * SessionEventPrunerTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\MerchantListsFeature;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
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
	 * Mock logger.
	 *
	 * @var FraudProtectionLogger&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->event_store = $this->createMock( SessionEventStore::class );
		$this->logger      = $this->createMock( FraudProtectionLogger::class );
		$this->logger->method( 'log' )->willReturnCallback( array( FraudProtectionController::class, 'log' ) );
		$this->sut         = new SessionEventPruner();
		$this->sut->init( $this->event_store, new MerchantListsFeature(), $this->logger );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		remove_all_actions( SessionEventPruner::PRUNE_ACTION_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( SessionEventPruner::PRUNE_ACTION_HOOK );
		}
		parent::tearDown();
	}

	/**
	 * @testdox Should prune with the retention period.
	 */
	public function test_prunes_with_the_retention_period(): void {
		$this->event_store
			->expects( $this->once() )
			->method( 'prune_older_than' )
			->with( SessionEventPruner::RETENTION_DAYS )
			->willReturn( 5 );

		$this->sut->handle_wc_fraud_protection_prune_sessions();

		$this->assertLogged( 'info', 'Pruned 5 session event(s)' );
	}

	/**
	 * @testdox Should not prune when the feature gate is off.
	 */
	public function test_does_not_prune_when_feature_disabled(): void {
		$disabled_feature = $this->createMock( MerchantListsFeature::class );
		$disabled_feature->method( 'is_enabled' )->willReturn( false );

		$sut = new SessionEventPruner();
		$sut->init( $this->event_store, $disabled_feature, $this->logger );

		$this->event_store
			->expects( $this->never() )
			->method( 'prune_older_than' );

		$sut->handle_wc_fraud_protection_prune_sessions();
	}

	/**
	 * @testdox Should log a warning and not throw when pruning fails.
	 */
	public function test_fails_open_when_pruning_throws(): void {
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
	 * @testdox Should schedule the recurring job on an admin request, and not schedule a second one.
	 */
	public function test_admin_request_schedules_the_job_once(): void {
		set_current_screen( 'dashboard' );

		$this->sut->register();
		$first = as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK );
		$this->assertNotFalse( $first, 'An admin request should schedule the pruning job' );

		$this->sut->register();
		$this->assertSame( $first, as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ), 'A second admin request must not schedule a duplicate job' );

		set_current_screen( 'front' );
	}

	/**
	 * @testdox Should unschedule the recurring job when the feature gate is off.
	 */
	public function test_unschedules_the_job_when_feature_disabled(): void {
		set_current_screen( 'dashboard' );

		$this->sut->register();
		$this->assertNotFalse( as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ) );

		$disabled_feature = $this->createMock( MerchantListsFeature::class );
		$disabled_feature->method( 'is_enabled' )->willReturn( false );

		$sut = new SessionEventPruner();
		$sut->init( $this->event_store, $disabled_feature, $this->logger );
		$sut->register();

		$this->assertFalse( as_next_scheduled_action( SessionEventPruner::PRUNE_ACTION_HOOK ), 'Turning the gate off in code should unschedule the pruning job' );

		set_current_screen( 'front' );
	}
}
