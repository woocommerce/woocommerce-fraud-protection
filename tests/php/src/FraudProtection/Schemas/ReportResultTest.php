<?php
/**
 * ReportResultTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection\Schemas;

use Automattic\WooCommerce\FraudProtection\Schemas\EventPhase;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportResult;
use Automattic\WooCommerce\FraudProtection\Tests\FraudProtectionUnitTestCase;

/**
 * Tests for the ReportResult enum.
 *
 * @covers \Automattic\WooCommerce\FraudProtection\Schemas\ReportResult
 */
class ReportResultTest extends FraudProtectionUnitTestCase {

	/**
	 * @testdox The per-type result groups partition every case exactly once.
	 */
	public function test_result_groups_partition_all_cases(): void {
		$to_values = static fn( ReportResult $result ): string => $result->value;

		$grouped = array();
		foreach ( EventPhase::cases() as $phase ) {
			$grouped = array_merge( $grouped, ReportResult::for_phase( $phase ) );
		}
		$grouped = array_map( $to_values, $grouped );
		$all     = array_map( $to_values, ReportResult::cases() );

		sort( $grouped );
		sort( $all );

		$this->assertSame(
			$all,
			$grouped,
			'ReportResult::for_phase() across every EventPhase must together equal every ReportResult case, with none omitted or double-counted - for_phase() must be a complete, disjoint partition of the cases.'
		);
	}
}
