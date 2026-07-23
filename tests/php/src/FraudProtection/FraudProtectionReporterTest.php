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
	 * @testdox report() delegates to the order events tracker with report_id and occurred_at when a context is provided.
	 */
	public function test_report_delegates_to_tracker_when_context_provided(): void {
		$order       = $this->createMock( \WC_Order::class );
		$occurred_at = new \DateTimeImmutable( '2026-06-03T12:00:00Z' );
		$context     = ReportContextData::from_array(
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
				'rep_1',
				$this->identicalTo( $context ),
				$this->identicalTo( $occurred_at ),
				'some notes'
			);

		$this->sut->report( $order, ReportSource::Chargeback, 'rep_1', $context, $occurred_at, 'some notes' );
	}

	/**
	 * @testdox report() logs a warning and skips the tracker when the context is null.
	 */
	public function test_report_logs_warning_and_skips_tracker_when_context_null(): void {
		$order = $this->createMock( \WC_Order::class );

		$this->tracker->expects( $this->never() )
			->method( 'fraud_protection_report' );

		$this->sut->report( $order, ReportSource::Chargeback, 'rep_1', null );

		$this->assertLogged( 'warning', 'Fraud protection report received no reportable context; skipping.' );
	}

	/**
	 * @testdox report() skips and logs when report_id is empty, whitespace-only, or too long.
	 * @dataProvider invalid_report_id_data
	 *
	 * @param string $report_id An unacceptable report_id value.
	 */
	public function test_report_skips_when_report_id_invalid( string $report_id ): void {
		$order   = $this->createMock( \WC_Order::class );
		$context = ReportContextData::from_array(
			array(
				'type'   => 'dispute',
				'result' => 'lost',
			)
		);

		$this->tracker->expects( $this->never() )
			->method( 'fraud_protection_report' );

		$this->sut->report( $order, ReportSource::Chargeback, $report_id, $context );

		$this->assertLogged( 'error', 'Skipping report: a non-empty report_id of 255 characters or fewer is required.' );
	}

	/**
	 * report_id values report() must reject.
	 *
	 * @return array<string, array{0: string}>
	 */
	public function invalid_report_id_data(): array {
		return array(
			'empty string'    => array( '' ),
			'whitespace only' => array( '   ' ),
			'over 255 chars'  => array( str_repeat( 'a', 256 ) ),
		);
	}

	/**
	 * @testdox report() accepts a report_id at the 255-character boundary.
	 */
	public function test_report_accepts_report_id_at_length_boundary(): void {
		$order   = $this->createMock( \WC_Order::class );
		$id      = str_repeat( 'a', 255 );
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
				$id,
				$this->identicalTo( $context ),
				$this->isNull(),
				''
			);

		$this->sut->report( $order, ReportSource::Chargeback, $id, $context );
	}
}
