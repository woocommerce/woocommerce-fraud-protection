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
	 * Report events to the Blackbox API.
	 *
	 * Called by the global `wc_fraud_protection_report()` function.
	 * Must be called after the session ID has been persisted to order meta
	 * (i.e. after `woocommerce_store_api_checkout_order_processed`).
	 *
	 * @internal
	 * @param \WC_Order $order  The order to report on.
	 * @param string    $source The source of the event. Use ApiClient::REPORT_SOURCE_* constants.
	 * @param string    $status The status of the event. Use ApiClient::REPORT_STATUS_GOOD or ApiClient::REPORT_STATUS_BAD.
	 * @param string    $notes  The notes of the event.
	 */
	public function fraud_protection_report( \WC_Order $order, string $source, string $status, string $notes ): void {
		try {
			if ( ! in_array( $source, ApiClient::VALID_REPORT_SOURCES, true ) ) {
				FraudProtectionController::log(
					'warning',
					sprintf( 'Invalid report source "%s", skipping report.', $source )
				);
				return;
			}

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
					'source' => $source,
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

	public function on_order_status_failed( $order_id ) {
		$order = wc_get_order( $order_id );

		$payment_method = $order->get_payment_method();
		if ( $payment_method === 'woocommerce_payments' ) {
			$intent_id = $order->get_meta( '_intent_id' );

			$request = \WCPay\Core\Server\Request\Get_Intention::create( $intent_id );
			$request->set_hook_args( $order );
			$intent = $request->send();

			switch ( $intent->get_status() ) {
				// case WooCommerce\Payments\Intent_Status::CANCELED:
				// 	$this->mark_payment_capture_cancelled( $order, $intent_data );
				// 	break;
				// case \WCPay\Constants\Intent_Status::PROCESSING:
				// case \WCPay\Constants\Intent_Status::REQUIRES_CAPTURE:
				// 	if ( Rule::FRAUD_OUTCOME_REVIEW === $intent_data['fraud_outcome'] ) {
				// 		$this->mark_order_held_for_review_for_fraud( $order, $intent_data );
				// 	}
				// 	break;
				case \WCPay\Constants\Intent_Status::REQUIRES_ACTION:
				case \WCPay\Constants\Intent_Status::REQUIRES_PAYMENT_METHOD:
					if ( $intent->get_last_payment_error() ) {
						$this->fraud_protection_report( $order, 'api','bad', $intent->get_last_payment_error()['message'] );
					}
					break;
			}

		}
	}
}
