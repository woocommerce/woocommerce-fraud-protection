<?php
/**
 * FraudProtectionReporter class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\OrderEventsTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Fraud protection reporter class.
 */
class FraudProtectionReporter {

	/**
	 * Order events tracker instance.
	 *
	 * @var OrderEventsTracker
	 */
	private OrderEventsTracker $order_events_tracker;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param OrderEventsTracker $order_events_tracker The order events tracker instance.
	 */
	final public function init( OrderEventsTracker $order_events_tracker ): void {
		$this->order_events_tracker = $order_events_tracker;
	}

	/**
	 * Report a normalized payment-outcome event to the Blackbox API.
	 *
	 * This is the public API for 3rd-party plugins (e.g. payment gateways) to report
	 * payment, dispute, and refund outcomes correlated with the original fraud-check
	 * session. Build `$context` with `ReportContextData::from_array()`, which returns null for an
	 * unmappable event — passing that here is safe and simply skips the report.
	 *
	 * Must be called after the session ID has been persisted to order meta
	 * (i.e. after `woocommerce_store_api_checkout_order_processed`).
	 *
	 * @param \WC_Order          $order   The order to report on.
	 * @param string             $source  The source of the event. Use ApiClient::REPORT_SOURCE_* constants; an unknown value defaults to REPORT_SOURCE_API.
	 * @param ?ReportContextData $context The normalized event context, or null to skip.
	 * @param string             $notes   Free-form notes. Must not contain raw gateway or customer data.
	 *
	 * @return void
	 */
	public function report( \WC_Order $order, string $source, ?ReportContextData $context, string $notes = '' ): void {
		if ( ! FraudProtectionController::feature_is_enabled() ) {
			return;
		}

		// from_array() returns null for an unmappable event; skip rather than fatal at the caller.
		if ( is_null( $context ) ) {
			FraudProtectionController::log( 'warning', 'Fraud protection report received no reportable context; skipping.' );
			return;
		}

		$this->order_events_tracker->fraud_protection_report( $order, $source, $context, $notes );
	}
}
