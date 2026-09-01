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

	private const COLLECTED_DATA_KEY             = 'fraud_protection_collected_data';
	private const COLLECTED_EVENTS_TRUNCATED_KEY = 'fraud_protection_collected_events_truncated';
	private const MAX_EVENT_NODES                = 64;
	private const MAX_EVENT_CONTENT_NODES        = self::MAX_EVENT_NODES - 1;
	private const MAX_EVENT_COUNT                = 256;
	private const MAX_HISTORY_BYTES              = 256 * 1024;
	private const MAX_KEY_BYTES                  = 128;
	private const MAX_TYPE_BYTES                 = 64;
	private const MAX_VALUE_BYTES                = 1024;

	/**
	 * SessionIdentityManager instance.
	 *
	 * @var SessionIdentityManager
	 */
	private SessionIdentityManager $session_identity_manager;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionIdentityManager $session_identity_manager The session identity manager instance.
	 */
	final public function init( SessionIdentityManager $session_identity_manager ): void {
		$this->session_identity_manager = $session_identity_manager;
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
		$this->session_identity_manager->ensure_cart_loaded();

		$data = $this->normalize_event(
			array(
				'event_type' => $event_type,
				'timestamp'  => gmdate( 'Y-m-d H:i:s' ),
				'event_data' => $event_data,
			)
		);

		$wc = function_exists( 'WC' ) ? WC() : null;

		// Save the collected data in the session for fraud analysis tracking, preserving multiple calls.
		if ( $wc instanceof \WooCommerce && $wc->session instanceof \WC_Session ) {
			// Retrieve existing data array or initialize if not present.
			$collected_data = $wc->session->get( self::COLLECTED_DATA_KEY );
			if ( ! is_array( $collected_data ) ) {
				$collected_data = array();
			}
			$collected_data[] = $data;
			$history_trimmed  = false;
			$collected_data   = $this->trim_history( $collected_data, $history_trimmed );
			$wc->session->set( self::COLLECTED_DATA_KEY, $collected_data );
			if ( $history_trimmed ) {
				$wc->session->set( self::COLLECTED_EVENTS_TRUNCATED_KEY, true );
			}
		} else {
			FraudProtectionController::log(
				'error',
				'Attempted to save fraud protection data, but no valid WooCommerce session exists.',
				array(
					'context'    => 'SessionDataCollector::collect',
					'event_type' => $event_type,
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
			$wc->session->set( self::COLLECTED_DATA_KEY, null );
			$wc->session->set( self::COLLECTED_EVENTS_TRUNCATED_KEY, null );
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
		$wc    = function_exists( 'WC' ) ? WC() : null;
		$order = null;
		if ( $order_id > 0 ) {
			try {
				$loaded_order = \wc_get_order( $order_id );
				if ( $loaded_order instanceof \WC_Order ) {
					$order = $loaded_order;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Graceful degradation.
			}
		}

		$data = array(
			'wc_version'       => isset( $wc->version ) ? (string) $wc->version : '',
			'session'          => $this->get_session_data()->to_array(),
			'customer'         => $this->get_customer_data( $order )->to_array(),
			'order'            => $this->get_order_data( $order )->to_array(),
			'collected_events' => array(),
		);

		if ( $wc instanceof \WooCommerce && $wc->session instanceof \WC_Session ) {
			$collected_data = $wc->session->get( self::COLLECTED_DATA_KEY );
			if ( is_array( $collected_data ) ) {
				$normalized_events = array();
				foreach ( $collected_data as $event ) {
					$normalized_events[] = $this->normalize_event( $event );
				}

				$history_trimmed          = false;
				$data['collected_events'] = $this->trim_history( $normalized_events, $history_trimmed );
				if ( $history_trimmed || true === $wc->session->get( self::COLLECTED_EVENTS_TRUNCATED_KEY ) ) {
					$data['collected_events_truncated'] = true;
				}
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
			return SessionInfo::from_request( $this->session_identity_manager->get_identity_id() );
		} catch ( \Throwable $e ) {
			return SessionInfo::empty();
		}
	}

	/**
	 * Get customer data as a CustomerData schema object.
	 *
	 * @param ?\WC_Order $order Selected order, if any.
	 * @return CustomerData
	 */
	private function get_customer_data( ?\WC_Order $order = null ): CustomerData {
		try {
			if ( $order instanceof \WC_Order ) {
				$billing  = Address::from_wc_order_billing( $order );
				$shipping = Address::from_wc_order_shipping( $order );
				return CustomerData::from_wc_order( $order, $billing, $shipping );
			}
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
	 * @param ?\WC_Order $order Selected order, if any.
	 * @return OrderData
	 */
	private function get_order_data( ?\WC_Order $order = null ): OrderData {
		$order_id = 0;
		try {
			if ( $order instanceof \WC_Order ) {
				$order_id = $order->get_id();
				return OrderData::from_order( $order );
			}
			if ( WC()->cart instanceof \WC_Cart && WC()->customer instanceof \WC_Customer ) {
				return OrderData::from_cart( 0, WC()->cart, WC()->customer );
			}
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			// Graceful degradation.
		}
		return OrderData::empty( $order_id );
	}

	/**
	 * Normalize one event to the retained data limits.
	 *
	 * @param mixed $event Event value.
	 * @return array<string|int, mixed> Normalized event.
	 */
	private function normalize_event( mixed $event ): array {
		$changed = false;
		if ( ! is_array( $event ) ) {
			$event   = array(
				'event_type' => null,
				'timestamp'  => null,
				'event_data' => $event,
			);
			$changed = true;
		}

		$already_truncated = true === ( $event['event_data_truncated'] ?? false );
		if ( array_key_exists( 'event_data_truncated', $event ) ) {
			unset( $event['event_data_truncated'] );
			$changed = $changed || ! $already_truncated;
		}

		if ( ! array_key_exists( 'event_type', $event ) ) {
			$event   = array( 'event_type' => null ) + $event;
			$changed = true;
		}

		$nodes      = 1;
		$normalized = $this->normalize_event_array( $event, $nodes, $changed, true );
		if ( $changed || $already_truncated ) {
			$normalized['event_data_truncated'] = true;
		}

		return $normalized;
	}

	/**
	 * Normalize an event array within the shared node budget.
	 *
	 * @param array<string|int, mixed> $data    Event data.
	 * @param int                      $nodes   Consumed content nodes.
	 * @param bool                     $changed Whether normalization changed the event.
	 * @param bool                     $root    Whether this is the event envelope.
	 * @return array<string|int, mixed> Normalized data.
	 */
	private function normalize_event_array( array $data, int &$nodes, bool &$changed, bool $root = false ): array {
		$normalized = array();
		foreach ( $data as $key => $value ) {
			$normalized_key = $key;
			if ( is_string( $key ) ) {
				$normalized_key = $this->normalize_text( $key, self::MAX_KEY_BYTES, $changed );
			}

			if ( array_key_exists( $normalized_key, $normalized ) ) {
				$changed = true;
				continue;
			}

			if ( $nodes >= self::MAX_EVENT_CONTENT_NODES ) {
				$changed = true;
				break;
			}

			if ( $root && ( 'event_type' === $normalized_key || 'timestamp' === $normalized_key ) ) {
				++$nodes;
				$normalized[ $normalized_key ] = $this->normalize_event_label( $value, $changed );
				continue;
			}

			if ( is_array( $value ) ) {
				if ( self::MAX_EVENT_CONTENT_NODES - $nodes <= 1 ) {
					$normalized[ $normalized_key ] = null;
					++$nodes;
					$changed = true;
					continue;
				}

				++$nodes;
				$normalized[ $normalized_key ] = $this->normalize_event_array( $value, $nodes, $changed );
				continue;
			}

			++$nodes;
			$normalized[ $normalized_key ] = $this->normalize_event_value( $value, $changed );
		}

		return $normalized;
	}

	/**
	 * Normalize an event type or timestamp.
	 *
	 * @param mixed $value   Value to normalize.
	 * @param bool  $changed Whether normalization changed the event.
	 * @return string|null Normalized label.
	 */
	private function normalize_event_label( mixed $value, bool &$changed ): ?string {
		if ( null === $value ) {
			return null;
		}

		if ( ! is_string( $value ) ) {
			$changed = true;
			return null;
		}

		return $this->normalize_text( $value, self::MAX_TYPE_BYTES, $changed );
	}

	/**
	 * Normalize an event scalar.
	 *
	 * @param mixed $value   Value to normalize.
	 * @param bool  $changed Whether normalization changed the event.
	 * @return bool|float|int|string|null Normalized value.
	 */
	private function normalize_event_value( mixed $value, bool &$changed ): bool|float|int|string|null {
		if ( null === $value || is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}

		if ( is_float( $value ) && is_finite( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			return $this->normalize_text( $value, self::MAX_VALUE_BYTES, $changed );
		}

		$changed = true;
		return null;
	}

	/**
	 * Return a valid UTF-8 byte prefix.
	 *
	 * @param string $value     Value to normalize.
	 * @param int    $max_bytes Maximum bytes.
	 * @param bool   $changed   Whether normalization changed the event.
	 * @return string Normalized string.
	 */
	private function normalize_text( string $value, int $max_bytes, bool &$changed ): string {
		$normalized = wp_check_invalid_utf8( $value, true );
		if ( strlen( $normalized ) > $max_bytes ) {
			$low  = 0;
			$high = mb_strlen( $normalized, 'UTF-8' );
			while ( $low < $high ) {
				$mid       = intdiv( $low + $high + 1, 2 );
				$candidate = mb_substr( $normalized, 0, $mid, 'UTF-8' );
				if ( strlen( $candidate ) <= $max_bytes ) {
					$low = $mid;
				} else {
					$high = $mid - 1;
				}
			}
			$normalized = mb_substr( $normalized, 0, $low, 'UTF-8' );
		}
		if ( $normalized !== $value ) {
			$changed = true;
		}

		return $normalized;
	}

	/**
	 * Trim event history to the count and serialized-byte limits.
	 *
	 * @param array<int, mixed> $data    Event history.
	 * @param bool              $trimmed Whether an event was removed.
	 * @return array<int, mixed> Trimmed event history.
	 */
	private function trim_history( array $data, bool &$trimmed ): array {
		$data       = array_values( $data );
		$data_count = count( $data );
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Used for size calculation only.
		$data_size = strlen( serialize( $data ) );

		while ( $data_count > 1 && ( $data_count > self::MAX_EVENT_COUNT || $data_size > self::MAX_HISTORY_BYTES ) ) {
			array_shift( $data );
			$trimmed    = true;
			$data_count = count( $data );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Used for size calculation only.
			$data_size = strlen( serialize( $data ) );
		}

		return $data;
	}
}
