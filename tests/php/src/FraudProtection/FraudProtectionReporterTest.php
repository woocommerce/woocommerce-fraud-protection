<?php
/**
 * FraudProtectionReporterTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection;

use Automattic\WooCommerce\FraudProtection\FraudProtectionReporter;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportSource;
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
	 * Mock order events tracker injected into the reporter.
	 *
	 * @var OrderEventsTracker&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $tracker;

	/**
	 * Set up test fixtures.
	 */
	public function setUp(): void {
		parent::setUp();

		$this->tracker = $this->createMock( OrderEventsTracker::class );

		$this->sut = new FraudProtectionReporter();
		$this->sut->init( $this->tracker );
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

		$this->tracker->expects( $this->once() )
			->method( 'fraud_protection_report' )
			->with(
				$this->identicalTo( $order ),
				$this->identicalTo( ReportSource::Chargeback ),
				$this->identicalTo( $context ),
				$this->identicalTo( 'some notes' )
			);

		$this->sut->report( $order, ReportSource::Chargeback, $context, 'some notes' );
	}

	/**
	 * @testdox report() logs a warning and skips the tracker when the context is null.
	 */
	public function test_report_logs_warning_and_skips_tracker_when_context_null(): void {
		$order = $this->createMock( \WC_Order::class );

		$this->tracker->expects( $this->never() )
			->method( 'fraud_protection_report' );

		$this->sut->report( $order, ReportSource::Chargeback, null );

		$this->assertLogged( 'warning', 'Fraud protection report received no reportable context; skipping.' );
	}
}
