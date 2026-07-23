<?php
/**
 * FraudProtectionReporter class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportSource;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\OrderEventsTracker;

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
	 * `report_id` is required: the report's idempotency key — a non-empty string of 255 characters
	 * or fewer, minted by the caller and reused when re-sending the same logical report. An invalid
	 * one skips the report. `occurred_at` is the event time; when null it defaults to now.
	 *
	 * Must be called after the session ID has been persisted to order meta
	 * (i.e. after `woocommerce_store_api_checkout_order_processed`).
	 *
	 * @param \WC_Order           $order       The order to report on.
	 * @param ReportSource        $source      The source of the event.
	 * @param string              $report_id   Required idempotency key; a non-empty string of 255 characters or fewer.
	 * @param ?ReportContextData  $context     The normalized event context, or null to skip.
	 * @param ?\DateTimeInterface $occurred_at Event time; defaults to now when null.
	 * @param string              $notes       Free-form notes. Must not contain raw gateway or customer data.
	 *
	 * @return void
	 */
	public function report( \WC_Order $order, ReportSource $source, string $report_id, ?ReportContextData $context, ?\DateTimeInterface $occurred_at = null, string $notes = '' ): void {
		if ( ! FraudProtectionController::feature_is_enabled() ) {
			return;
		}

		$report_id = self::validate_report_id( $report_id );
		if ( is_null( $report_id ) ) {
			FraudProtectionController::log(
				'error',
				'Skipping report: a non-empty report_id of 255 characters or fewer is required.',
				array(),
				true
			);
			return;
		}

		// from_array() returns null for an unmappable event; skip rather than fatal at the caller.
		if ( is_null( $context ) ) {
			FraudProtectionController::log( 'warning', 'Fraud protection report received no reportable context; skipping.' );
			return;
		}

		$this->order_events_tracker->fraud_protection_report( $order, $source, $report_id, $context, $occurred_at, $notes );
	}

	/**
	 * Validate the required report_id, or null when unusable.
	 *
	 * The report's idempotency key is opaque: a non-empty string of 255 characters or fewer, returned
	 * verbatim. It is never transformed, so the caller's exact value reaches the endpoint and a retry
	 * reuses the same key; sanitizing it could collapse two distinct ids into one. An unusable value
	 * returns null so report() skips instead of sending a request the endpoint would reject.
	 *
	 * @param string $report_id Raw idempotency key.
	 * @return ?string The report_id unchanged, or null when empty or too long.
	 */
	private static function validate_report_id( string $report_id ): ?string {
		if ( '' === trim( $report_id ) || strlen( $report_id ) > 255 ) {
			return null;
		}

		return $report_id;
	}
}
