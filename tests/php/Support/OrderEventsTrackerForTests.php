<?php
/**
 * OrderEventsTrackerForTests file.
 */

declare( strict_types = 1 );

namespace Automattic\WooCommerce\FraudProtection\Tests\Support;

use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\OrderEventsTracker;

/**
 * Test double for {@see OrderEventsTracker} that records report calls in memory.
 *
 * Records every {@see fraud_protection_report()} invocation (and its arguments)
 * instead of contacting the Blackbox API, so a test can assert whether — and with
 * what — the tracker was called. Install it through the WooCommerce testing
 * container (`wc_get_container()->replace( OrderEventsTracker::class, ... )`) so
 * the class under test receives it via dependency injection.
 */
class OrderEventsTrackerForTests extends OrderEventsTracker {

	/**
	 * Arguments of each {@see fraud_protection_report()} call, in invocation order.
	 *
	 * @var array<int, array{order: \WC_Order, source: string, context: ReportContextData, notes: string}>
	 */
	public array $reports = array();

	/**
	 * Record the report call instead of sending it to the Blackbox API.
	 *
	 * @param \WC_Order         $order   The order to report on.
	 * @param string            $source  The source of the event.
	 * @param ReportContextData $context The normalized event context.
	 * @param string            $notes   Free-form notes.
	 * @return void
	 */
	public function fraud_protection_report( \WC_Order $order, string $source, ReportContextData $context, string $notes = '' ): void {
		$this->reports[] = array(
			'order'   => $order,
			'source'  => $source,
			'context' => $context,
			'notes'   => $notes,
		);
	}
}
