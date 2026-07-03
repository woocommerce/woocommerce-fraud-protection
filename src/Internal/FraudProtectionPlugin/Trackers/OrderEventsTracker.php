<?php
/**
 * OrderEventsTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportSource;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\ApiClient;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks order lifecycle events and reports them to the Blackbox API.
 *
 * 3rd-party plugins report events by resolving `FraudProtectionReporter` from
 * `wc_get_container()` and calling its `report()` method, which delegates to this class.
 *
 * Fire-and-forget: failures are logged but never affect the order flow.
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
	 * Report a normalized payment-outcome event to the Blackbox API.
	 *
	 * Called by `FraudProtectionReporter::report()`. The plugin sends only the
	 * normalized `context`, enriched with order-derived gateway and order ID.
	 *
	 * Must be called after the session ID has been persisted to order meta
	 * (i.e. after `woocommerce_store_api_checkout_order_processed`).
	 *
	 * @param \WC_Order         $order   The order to report on.
	 * @param ReportSource      $source  The source of the event.
	 * @param ReportContextData $context The normalized event context.
	 * @param string            $notes   Free-form notes. Must not contain raw gateway or customer data.
	 */
	public function fraud_protection_report( \WC_Order $order, ReportSource $source, ReportContextData $context, string $notes = '' ): void {
		$session_id = '';
		try {
			$session_id = $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY );
			if ( ! is_string( $session_id ) || '' === $session_id ) {
				FraudProtectionController::log(
					'warning',
					'Missing session ID in order meta, skipping Blackbox API report.'
				);
				return;
			}

			$context = $context->with_order_defaults( $order->get_id(), $order->get_payment_method() );

			$this->api_client->report(
				$session_id,
				array(
					'source'  => $source->value,
					'notes'   => sanitize_text_field( $notes ),
					'context' => $context->to_array(),
				)
			);
		} catch ( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'Failed to report 3rd party event to Blackbox API',
				array(
					'event_source'      => 'order_event_report',
					'session_id'        => $session_id,
					'order_id'          => $order->get_id(),
					'error'             => $e->getTraceAsString(),
					'exception_class'   => $e::class,
					'exception_message' => $e->getMessage(),
					'exception_file'    => $e->getFile(),
					'exception_line'    => $e->getLine(),
				),
				true
			);
		}
	}
}
