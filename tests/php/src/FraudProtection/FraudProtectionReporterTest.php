<?php
/**
 * FraudProtectionReporterTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\FraudProtectionReporter;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Tests\Support\OrderEventsTrackerForTests;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\OrderEventsTracker;

/**
 * Tests for the FraudProtectionReporter class.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\FraudProtectionReporter
 */
class FraudProtectionReporterTest extends FraudProtectionUnitTestCase {

	/**
	 * The System Under Test.
	 *
	 * @var FraudProtectionReporter
	 */
	private $sut;

	/**
	 * In-memory order events tracker injected into the reporter.
	 *
	 * @var OrderEventsTrackerForTests
	 */
	private $tracker;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		// Replace the order events tracker in the container with an in-memory
		// double, then rebuild the reporter so it is injected with that double.
		$this->tracker = new OrderEventsTrackerForTests();
		$container     = wc_get_container();
		$container->replace( OrderEventsTracker::class, $this->tracker );
		$container->reset_all_resolved();

		$this->sut = $container->get( FraudProtectionReporter::class );
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tearDown(): void {
		wc_get_container()->reset_replacement( OrderEventsTracker::class );

		parent::tearDown();
	}

	/**
	 * @testdox report() delegates to the order events tracker when a context is provided.
	 */
	public function test_report_delegates_to_tracker_when_context_provided(): void {
		$order   = $this->createMock( \WC_Order::class );
		$context = ReportContextData::from_array(
			array(
				'type'   => 'dispute',
				'result' => 'lost',
			)
		);

		$this->sut->report( $order, ApiClient::REPORT_SOURCE_CHARGEBACK, $context, 'some notes' );

		$this->assertCount( 1, $this->tracker->reports, 'The tracker should be invoked exactly once.' );

		$report = $this->tracker->reports[0];
		$this->assertSame( $order, $report['order'], 'The order should be forwarded unchanged.' );
		$this->assertSame( ApiClient::REPORT_SOURCE_CHARGEBACK, $report['source'], 'The source should be forwarded unchanged.' );
		$this->assertSame( $context, $report['context'], 'The context should be forwarded unchanged.' );
		$this->assertSame( 'some notes', $report['notes'], 'The notes should be forwarded unchanged.' );
	}

	/**
	 * @testdox report() logs a warning and skips the tracker when the context is null.
	 */
	public function test_report_logs_warning_and_skips_tracker_when_context_null(): void {
		$order = $this->createMock( \WC_Order::class );

		$this->sut->report( $order, ApiClient::REPORT_SOURCE_CHARGEBACK, null );

		$this->assertLogged( 'warning', 'Fraud protection report received no reportable context; skipping.' );
		$this->assertCount( 0, $this->tracker->reports, 'The tracker must not be invoked when there is no reportable context.' );
	}
}
