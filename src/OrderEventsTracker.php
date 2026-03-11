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
 * Listens to WooCommerce order_refunded hook and 3rd party plugins that report events to the Blackbox API.
 * Sends event data to the report endpoint so Blackbox can correlate
 * outcomes with the original fraud-check session.
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
	public function register(): void {
		/**
		 * Action hook to report events to the Blackbox API.
		 * This hook must be called only after the SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY is set to the order meta.
		 * Which happens when woocommerce_store_api_checkout_order_processed hook is called.
		 *
		 * @param \WC_Order $order The order to report on.
		 * @param string $status   The status of the event. Either 'good' or 'bad'.
		 * @param string $notes    The notes of the event.
		 */
		add_action( 'woocommerce_fraud_protection_report', array( $this, 'on_fraud_protection_report' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'on_order_refunded' ), 10, 2 );
	}

	/**
	 * Allow 3rd party plugins to report events to the Blackbox API.
	 * Useful when a payment fails and you want to report the event to the Blackbox API.
	 *
	 * @internal
	 * @param \WC_Order $order The order to report on.
	 * @param string $status   The status of the event. Either 'good' or 'bad'.
	 * @param string $notes    The notes of the event.
	 */
	public function on_fraud_protection_report( \WC_Order $order, string $status, string $notes ): void {
		try{
			$session_id = $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY );
			if ( ! is_string( $session_id ) || '' === $session_id ) {
				return;
			}

			$this->api_client->report(
				$session_id,
				array(
					'label'  => $status,
					'source' => 'payment_gateway_event',
					'notes'  => $notes,
				)
			);
		}
		catch( \Throwable $e ){
			FraudProtectionController::log(
				'error',
				'Failed to report 3rd party event to Blackbox API: ' . $e->getMessage(),
				array( 'error' => $e->getTraceAsString() )
			);
		}
	}

	/**
	 * Report an order refund event to the Blackbox API.
	 *
	 * @internal
	 *
	 * @param int $order_id  The order ID.
	 * @param int $refund_id The refund ID.
	 */
	public function on_order_refunded( int $order_id, int $refund_id ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}

			$session_id = $order->get_meta( SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY );
			if ( ! is_string( $session_id ) || '' === $session_id ) {
				return;
			}

			$refund = wc_get_order( $refund_id );
			if ( ! $refund instanceof \WC_Order_Refund ) {
				return;
			}

			$this->api_client->report(
				$session_id,
				array(
					'label'  => 'good',
					'source' => 'order_refunded',
					'notes'  => $refund->get_reason(),
				)
			);
		} catch( \Throwable $e ) {
			FraudProtectionController::log(
				'error',
				'Failed to report order refund event to Blackbox API: ' . $e->getMessage(),
				array( 'error' => $e->getTraceAsString() )
			);
		}
	}
}
