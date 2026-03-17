<?php
/**
 * OrderEventsTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks order lifecycle events and reports them to the Blackbox API.
 *
 * 3rd-party plugins can report events via the global `wc_fraud_protection_report()` function,
 * which delegates to this class.
 *
 * Fire-and-forget: failures are logged but never affect the order flow.
 *
 * @internal
 */
class OrderEventsTracker {

	/**
	 * API client instance.
	 *
	 * @var ApiClient
	 */
	private ApiClient $api_client;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param ApiClient $api_client The API client instance.
	 */
	final public function init( ApiClient $api_client ): void {
		$this->api_client = $api_client;
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {}

	/**
	 * Report events to the Blackbox API.
	 *
	 * Called by the global `wc_fraud_protection_report()` function.
	 * Must be called after the session ID has been persisted to order meta
	 * (i.e. after `woocommerce_store_api_checkout_order_processed`).
	 *
	 * @internal
	 * @param \WC_Order $order  The order to report on.
	 * @param string    $status The status of the event. Use ApiClient::REPORT_STATUS_GOOD or ApiClient::REPORT_STATUS_BAD.
	 * @param string    $notes  The notes of the event.
	 */
	public function fraud_protection_report( \WC_Order $order, string $status, string $notes ): void {
		try {
			if ( ! in_array( $status, ApiClient::VALID_REPORT_STATUSES, true ) ) {
				FraudProtectionController::log(
					'warning',
					sprintf( 'Invalid report status "%s", skipping report.', $status )
				);
				return;
			}

			$session_id = $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY );
			if ( ! is_string( $session_id ) || '' === $session_id ) {
				FraudProtectionController::log(
					'warning',
					'Missing session ID in order meta, skipping Blackbox API report.'
				);
				return;
			}

			$this->api_client->report(
				$session_id,
				array(
					'label'  => $status,
					'source' => 'payment_gateway_event',
					'notes'  => sanitize_text_field( $notes ),
				)
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'Failed to report 3rd party event to Blackbox API: ' . $e->getMessage(),
				array( 'error' => $e->getTraceAsString() )
			);
		}
	}
}
