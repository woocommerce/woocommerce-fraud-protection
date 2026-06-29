<?php
/**
 * SessionDataCollector class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\Address;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\CustomerData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\OrderData;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Schemas\SessionInfo;

defined( 'ABSPATH' ) || exit;

/**
 * Collects comprehensive session and order data for fraud protection analysis.
 *
 * This class provides manual data collection for fraud protection events, gathering
 * session, customer, order, address, and payment information in the exact nested format
 * required by the WPCOM fraud protection service. All data collection is designed to
 * degrade gracefully when fields are unavailable, ensuring checkout never fails due to
 * missing fraud protection data.
 */
class SessionDataCollector {

	/**
	 * SessionClearanceManager instance.
	 *
	 * @var SessionClearanceManager
	 */
	private SessionClearanceManager $session_clearance_manager;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionClearanceManager $session_clearance_manager The session clearance manager instance.
	 */
	final public function init( SessionClearanceManager $session_clearance_manager ): void {
		$this->session_clearance_manager = $session_clearance_manager;
	}

	/**
	 * Collect comprehensive session and order data for fraud protection.
	 *
	 * This method is called manually at specific points in the checkout/payment flow
	 * to gather all relevant data for fraud analysis. It returns data in the nested
	 * format expected by the WPCOM fraud protection service.
	 *
	 * @param string|null $event_type Optional event type identifier (e.g., 'checkout_started', 'payment_attempt').
	 * @param array       $event_data Optional event-specific additional context data.
	 */
	public function collect( ?string $event_type = null, array $event_data = array() ): void {
		// Ensure cart and session are loaded.
		$this->session_clearance_manager->ensure_cart_loaded();

		$data = array(
			'event_type' => $event_type,
			'timestamp'  => gmdate( 'Y-m-d H:i:s' ),
			'event_data' => $event_data,
		);

		$wc = function_exists( 'WC' ) ? WC() : null;

		// Save the collected data in the session for fraud analysis tracking, preserving multiple calls.
		if ( $wc instanceof \WooCommerce && $wc->session instanceof \WC_Session ) {
			// Retrieve existing data array or initialize if not present.
			$collected_data = $wc->session->get( 'fraud_protection_collected_data' );
			if ( ! is_array( $collected_data ) ) {
				$collected_data = array();
			}
			$collected_data[] = $data;
			$collected_data   = $this->trim_to_max_size( $collected_data );
			$wc->session->set( 'fraud_protection_collected_data', $collected_data );
		} else {
			FraudProtectionController::log(
				'error',
				'Attempted to save fraud protection data, but no valid WooCommerce session exists.',
				array(
					'context'    => 'SessionDataCollector::collect',
					'event_type' => $event_type,
					'event_data' => $event_data,
				)
			);
		}
	}

	/**
	 * Clear all collected fraud protection events from the session.
	 *
	 * Called after a successful payment to ensure events from a completed
	 * order do not carry over to subsequent orders in the same session.
	 */
	public function clear_collected_events(): void {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( $wc instanceof \WooCommerce && $wc->session instanceof \WC_Session ) {
			$wc->session->set( 'fraud_protection_collected_data', null );
		}
	}

	/**
	 * Get all collected fraud protection data from the session.
	 *
	 * Retrieves the array of collected event data stored during this session.
	 * Returns an empty array if no data has been collected or session is unavailable.
	 *
	 * @param int $order_id Optional order ID to include order data in the response.
	 * @return array Array of collected fraud protection event data.
	 */
	public function get_collected_data( int $order_id = 0 ): array {
		$wc = function_exists( 'WC' ) ? WC() : null;

		$data = array(
			'wc_version'       => isset( $wc->version ) ? (string) $wc->version : '',
			'session'          => $this->get_session_data()->to_array(),
			'customer'         => $this->get_customer_data()->to_array(),
			'order'            => $this->get_order_data( $order_id )->to_array(),
			'collected_events' => array(),
		);

		// Calculate base data size to ensure total response stays under limit.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Used for size calculation only.
		$base_size = strlen( serialize( $data ) );

		if ( $wc instanceof \WooCommerce && $wc->session instanceof \WC_Session ) {
			$collected_data = $wc->session->get( 'fraud_protection_collected_data' );
			if ( is_array( $collected_data ) ) {
				$data['collected_events'] = $this->trim_to_max_size( $collected_data, $base_size );
			}
		}

		return $data;
	}

	/**
	 * Get current billing country from customer data.
	 *
	 * @return string|null Current billing country code or null if unavailable.
	 */
	public function get_current_billing_country(): ?string {
		return $this->get_billing_address()->get_country();
	}

	/**
	 * Get current shipping country from customer data.
	 *
	 * @return string|null Current shipping country code or null if unavailable.
	 */
	public function get_current_shipping_country(): ?string {
		return $this->get_shipping_address()->get_country();
	}

	/**
	 * Get session data as a SessionInfo schema object.
	 *
	 * @return SessionInfo
	 */
	private function get_session_data(): SessionInfo {
		try {
			return SessionInfo::from_request( $this->session_clearance_manager->get_identity_id() );
		} catch ( \Throwable $e ) {
			return SessionInfo::empty();
		}
	}

	/**
	 * Get customer data as a CustomerData schema object.
	 *
	 * @return CustomerData
	 */
	private function get_customer_data(): CustomerData {
		try {
			if ( WC()->customer instanceof \WC_Customer ) {
				$customer = WC()->customer;
				$billing  = Address::from_wc_customer_billing( $customer );
				$shipping = Address::from_wc_customer_shipping( $customer );
				return CustomerData::from_wc_customer( $customer, $billing, $shipping );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Graceful degradation.
		}
		return CustomerData::empty();
	}

	/**
	 * Get billing address as an Address schema object.
	 *
	 * @return Address
	 */
	private function get_billing_address(): Address {
		try {
			if ( WC()->customer instanceof \WC_Customer ) {
				return Address::from_wc_customer_billing( WC()->customer );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Graceful degradation.
		}
		return Address::empty();
	}

	/**
	 * Get shipping address as an Address schema object.
	 *
	 * @return Address
	 */
	private function get_shipping_address(): Address {
		try {
			if ( WC()->customer instanceof \WC_Customer ) {
				return Address::from_wc_customer_shipping( WC()->customer );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Graceful degradation.
		}
		return Address::empty();
	}

	/**
	 * Get order data as an OrderData schema object.
	 *
	 * @param int $order_id_from_event Optional order ID from event data.
	 * @return OrderData
	 */
	private function get_order_data( int $order_id_from_event = 0 ): OrderData {
		try {
			if ( WC()->cart instanceof \WC_Cart && WC()->customer instanceof \WC_Customer ) {
				return OrderData::from_cart( $order_id_from_event, WC()->cart, WC()->customer );
			}
			return OrderData::empty( $order_id_from_event );
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Graceful degradation.
		}
		return OrderData::empty( $order_id_from_event );
	}

	/**
	 * Trim collected data array to ensure it stays within 1 MB size limit.
	 *
	 * Removes oldest entries from the array until the serialized size is under the limit.
	 * Always keeps at least one entry (the most recent).
	 *
	 * @param array $data      Array of collected event data.
	 * @param int   $base_size Size in bytes of additional data that will be combined with this array.
	 * @return array Trimmed array that fits within the size limit.
	 */
	private function trim_to_max_size( array $data, int $base_size = 0 ): array {
		$max_size_bytes = 1 * 1024 * 1024 - $base_size; // 1 MB minus base data size.
		$data_count     = count( $data );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Used for size calculation only.
		$data_size = strlen( serialize( $data ) );

		while ( $data_count > 1 && $data_size > $max_size_bytes ) {
			array_shift( $data );
			$data_count = count( $data );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Used for size calculation only.
			$data_size = strlen( serialize( $data ) );
		}

		return $data;
	}
}
