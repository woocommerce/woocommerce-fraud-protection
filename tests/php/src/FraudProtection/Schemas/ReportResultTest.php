<?php
/**
 * ReportResultTest class file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\Tests\FraudProtection\Schemas;

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

		$grouped = array_map(
			$to_values,
			array_merge(
				ReportResult::PAYMENT_RESULTS,
				ReportResult::DISPUTE_RESULTS,
				ReportResult::REFUND_RESULTS
			)
		);
		$all = array_map( $to_values, ReportResult::cases() );

		sort( $grouped );
		sort( $all );

		$this->assertSame(
			$all,
			$grouped,
			'PAYMENT_RESULTS + DISPUTE_RESULTS + REFUND_RESULTS must equal every ReportResult case, with none omitted or double-counted — RESULTS_BY_TYPE validation relies on the groups being a complete, disjoint partition.'
		);
	}
}
