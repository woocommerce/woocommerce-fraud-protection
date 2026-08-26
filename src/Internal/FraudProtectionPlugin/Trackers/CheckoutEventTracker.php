<?php
/**
 * CheckoutEventTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks checkout events for fraud protection analysis.
 *
 * This class provides methods to track both WooCommerce Blocks (Store API) and traditional
 * shortcode checkout events for fraud protection. Event-specific data is passed to the
 * SessionDataCollector which handles session data storage internally.
 */
class CheckoutEventTracker {
	/**
	 * Failure log message.
	 */
	private const FAILURE_MESSAGE = 'Checkout event tracker callback failed';

	/**
	 * Event source for failure logs.
	 */
	private const EVENT_SOURCE = 'checkout_event_tracker';
	/**
	 * Session data collector instance.
	 *
	 * @var SessionDataCollector
	 */
	private SessionDataCollector $session_data_collector;

	/**
	 * Fraud Protection logger instance.
	 *
	 * @var FraudProtectionLogger
	 */
	private FraudProtectionLogger $logger;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionDataCollector  $session_data_collector The session data collector instance.
	 * @param FraudProtectionLogger $logger                 The Fraud Protection logger instance.
	 */
	final public function init( SessionDataCollector $session_data_collector, FraudProtectionLogger $logger ): void {
		$this->session_data_collector = $session_data_collector;
		$this->logger                 = $logger;
	}

	/**
	 * Register checkout event tracking hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'track_order_placed_from_shortcode' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'track_order_placed_from_store_api' ), 10, 1 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'track_shortcode_checkout_field_update' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this, 'track_blocks_checkout_update' ), 10, 0 );
		add_action( 'template_redirect', array( $this, 'track_checkout_page_loaded' ), 10, 0 );
		add_action( 'woocommerce_order_status_changed', array( $this, 'clear_events_on_successful_payment' ), 10, 3 );
	}

	/**
	 * Track checkout page loaded event.
	 *
	 * Collects session data when the checkout page is initially loaded.
	 * This captures the initial session state before any user interactions.
	 *
	 * @internal
	 * @return void
	 */
	public function track_checkout_page_loaded(): void {
		try {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
				return;
			}

			$event_name = is_checkout_pay_page() ? 'pay_for_order_page_loaded' : 'checkout_page_loaded';
			$this->session_data_collector->collect( $event_name, array() );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'template_redirect', $e );
		}
	}

	/**
	 * Track Store API customer update event (WooCommerce Blocks checkout).
	 *
	 * Triggered when customer information is updated via the Store API endpoint
	 * /wc/store/v1/cart/update-customer during Blocks checkout flow.
	 *
	 * @internal
	 * @return void
	 */
	public function track_blocks_checkout_update(): void {
		try {
			// At this point we don't have any payment or shipping data, so we pass an empty array.
			$this->session_data_collector->collect( 'checkout_update', array() );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'woocommerce_store_api_checkout_update_customer_from_request', $e );
		}
	}

	/**
	 * Track shortcode checkout field update event.
	 *
	 * Triggered when checkout fields are updated via AJAX (woocommerce_update_order_review).
	 * Only dispatches event when billing or shipping country changes to reduce unnecessary API calls.
	 *
	 * @internal
	 *
	 * @param mixed $posted_data Serialized checkout form data. Non-string values are ignored.
	 * @return void
	 */
	public function track_shortcode_checkout_field_update( $posted_data ): void {
		try {
			if ( ! is_string( $posted_data ) ) {
				return;
			}

			// Parse the posted data to extract relevant fields.
			$data = array();
			if ( $posted_data ) {
				parse_str( $posted_data, $data );
			}

			// Get current customer countries using SessionDataCollector.
			$current_billing_country  = $this->session_data_collector->get_current_billing_country();
			$current_shipping_country = $this->session_data_collector->get_current_shipping_country();

			// Get posted countries.
			$posted_billing_country  = $data['billing_country'] ?? '';
			$posted_shipping_country = $data['shipping_country'] ?? '';

			// Check if billing country changed.
			$billing_changed = ! empty( $posted_billing_country ) && $posted_billing_country !== $current_billing_country;

			// Check if shipping country changed.
			$ship_to_different = ! empty( $data['ship_to_different_address'] );
			if ( $ship_to_different ) {
				// User wants different shipping address - check if shipping country changed.
				$shipping_changed = ! empty( $posted_shipping_country ) && $posted_shipping_country !== $current_shipping_country;
			} else {
				// User wants same address for billing and shipping.
				// If current shipping country exists and differs from billing country, it's a change.
				$effective_billing_country = ! empty( $posted_billing_country ) ? $posted_billing_country : $current_billing_country;
				$shipping_changed          = ! empty( $current_shipping_country ) && $current_shipping_country !== $effective_billing_country;
			}

			// Only dispatch if either country changed.
			if ( $billing_changed || $shipping_changed ) {
				$event_data = $this->format_checkout_event_data( 'field_update', $data );
				$this->session_data_collector->collect( 'checkout_update', $event_data );
			}
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'woocommerce_checkout_update_order_review', $e );
		}
	}

	/**
	 * Build checkout event-specific data.
	 *
	 * Prepares the checkout event data including action type and any changed fields.
	 *
	 * @param string $action Action type (field_update, store_api_update).
	 * @param array  $collected_event_data Posted form data or event context (may include session data).
	 * @return array Checkout event data.
	 */
	private function format_checkout_event_data( string $action, array $collected_event_data ): array {
		$event_data = array( 'action' => $action );

		// Extract and merge all checkout field groups.
		$event_data = array_merge(
			$event_data,
			$this->extract_billing_fields( $collected_event_data ),
			$this->extract_shipping_fields( $collected_event_data ),
			$this->extract_payment_method( $collected_event_data ),
		);

		return $event_data;
	}

	/**
	 * Extract billing fields from posted data.
	 *
	 * @param array $posted_data Posted form data.
	 * @return array Billing fields.
	 */
	private function extract_billing_fields( array $posted_data ): array {
		$field_map = array(
			'billing_email'      => 'sanitize_email',
			'billing_first_name' => 'sanitize_text_field',
			'billing_last_name'  => 'sanitize_text_field',
			'billing_country'    => 'sanitize_text_field',
			'billing_address_1'  => 'sanitize_text_field',
			'billing_address_2'  => 'sanitize_text_field',
			'billing_city'       => 'sanitize_text_field',
			'billing_state'      => 'sanitize_text_field',
			'billing_postcode'   => 'sanitize_text_field',
			'billing_phone'      => 'sanitize_text_field',
		);

		$extracted_fields = $this->extract_fields_by_map( $field_map, $posted_data );

		// Store API uses 'email' instead of 'billing_email'.
		if ( empty( $extracted_fields['billing_email'] ) && is_string( $posted_data['email'] ?? null ) && ! empty( $posted_data['email'] ) ) {
			$extracted_fields['email'] = sanitize_email( $posted_data['email'] );
		}

		return $extracted_fields;
	}

	/**
	 * Extract shipping fields from posted data.
	 *
	 * @param array $posted_data Posted form data.
	 * @return array Shipping fields.
	 */
	private function extract_shipping_fields( array $posted_data ): array {
		if ( ! isset( $posted_data['ship_to_different_address'] ) || ! $posted_data['ship_to_different_address'] ) {
			return array();
		}

		$field_map = array(
			'shipping_first_name' => 'sanitize_text_field',
			'shipping_last_name'  => 'sanitize_text_field',
			'shipping_country'    => 'sanitize_text_field',
			'shipping_address_1'  => 'sanitize_text_field',
			'shipping_address_2'  => 'sanitize_text_field',
			'shipping_city'       => 'sanitize_text_field',
			'shipping_state'      => 'sanitize_text_field',
			'shipping_postcode'   => 'sanitize_text_field',
		);

		return $this->extract_fields_by_map( $field_map, $posted_data );
	}

	/**
	 * Extract and sanitize fields from posted data using a field map.
	 *
	 * Generic extraction method that iterates through a field map and extracts
	 * non-empty fields from posted data, applying the appropriate sanitization
	 * function to each field.
	 *
	 * @param array $field_map    Map of field names to sanitization functions.
	 * @param array $posted_data  Posted form data.
	 * @return array Extracted and sanitized fields.
	 */
	private function extract_fields_by_map( array $field_map, array $posted_data ): array {
		$extracted_fields = array();

		foreach ( $field_map as $field_name => $sanitize_function ) {
			$value = $posted_data[ $field_name ] ?? null;
			if ( is_string( $value ) && ! empty( $value ) ) {
				$extracted_fields[ $field_name ] = $sanitize_function( wp_unslash( $value ) );
			}
		}

		return $extracted_fields;
	}

	/**
	 * Extract payment method data from posted data.
	 *
	 * Extracts payment method ID and retrieves the readable gateway name.
	 *
	 * @param array $posted_data Posted form data.
	 * @return array Payment method data with ID and name, or empty array if not found.
	 */
	private function extract_payment_method( array $posted_data ): array {
		$payment_data = array();
		$gateway_id   = $posted_data['payment_method'] ?? null;

		if ( is_string( $gateway_id ) && ! empty( $gateway_id ) ) {
			$gateways             = WC()->payment_gateways()->payment_gateways();
			$payment_gateway_name = isset( $gateways[ $gateway_id ] ) ? $gateways[ $gateway_id ]->get_title() : $gateway_id;

			$payment_data['payment'] = array(
				'payment_gateway_type' => $gateway_id,
				'payment_gateway_name' => $payment_gateway_name,
			);
		}

		return $payment_data;
	}

	/**
	 * Track successful order placement.
	 *
	 * Called when an order is successfully placed, with or without payment.
	 * Works for both shortcode and Store API checkout flows.
	 *
	 * @param int       $order_id The order ID.
	 * @param \WC_Order $order    The order object.
	 * @return void
	 */
	public function track_order_placed( int $order_id, \WC_Order $order ): void {
		$customer_id = $order->get_customer_id();
		$event_data  = array(
			'order_id'       => $order_id,
			'payment_method' => $order->get_payment_method(),
			'total'          => (float) $order->get_total(),
			'currency'       => $order->get_currency(),
			'customer_id'    => $customer_id ? $customer_id : 'guest',
			'status'         => $order->get_status(),
		);

		$this->session_data_collector->collect( 'order_placed', $event_data );
	}

	/**
	 * Adapter for woocommerce_checkout_order_processed hook.
	 *
	 * This hook provides ($order_id, $posted_data, $order) but we only need order_id and order.
	 *
	 * @internal
	 *
	 * @param mixed $order_id    The order ID.
	 * @param mixed $posted_data The posted checkout data (unused).
	 * @param mixed $order       The order object.
	 * @return void
	 */
	public function track_order_placed_from_shortcode( $order_id, $posted_data, $order ): void {
		try {
			$this->track_order_placed( $order_id, $order );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'woocommerce_checkout_order_processed', $e );
		}
	}

	/**
	 * Adapter for woocommerce_store_api_checkout_order_processed hook.
	 *
	 * This hook only provides the order object, we extract the order_id from it.
	 *
	 * @internal
	 *
	 * @param mixed $order The order object.
	 * @return void
	 */
	public function track_order_placed_from_store_api( $order ): void {
		try {
			$this->track_store_api_order_placed( $order );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'woocommerce_store_api_checkout_order_processed', $e );
		}
	}

	/**
	 * Track a Store API order after validating its type.
	 *
	 * @param \WC_Order $order The order object.
	 * @return void
	 */
	private function track_store_api_order_placed( \WC_Order $order ): void {
		$this->track_order_placed( $order->get_id(), $order );
	}

	/**
	 * Clear collected session events when an order payment succeeds.
	 *
	 * Hooked to `woocommerce_order_status_changed` with guards to only fire
	 * on initial checkout transitions (checkout-draft/pending/failed → processing/completed/on-hold).
	 * This ensures events from a completed order do not carry over to subsequent
	 * orders in the same session, while preserving events across payment retries.
	 *
	 * This covers online (same-request and redirect) and offline gateways, since
	 * the status transition happens during the customer's request where the WC
	 * session is available. In non-customer contexts (webhook, cron),
	 * `WC()->session` is unavailable and `clear_collected_events()` is a no-op.
	 *
	 * @internal
	 *
	 * @param mixed $order_id   The order ID.
	 * @param mixed $old_status The old order status.
	 * @param mixed $new_status The new order status.
	 * @return void
	 */
	public function clear_events_on_successful_payment( $order_id, $old_status, $new_status ): void {
		try {
			$this->clear_events_for_successful_payment( $old_status, $new_status );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'woocommerce_order_status_changed', $e );
		}
	}

	/**
	 * Clear events after a successful payment status transition.
	 *
	 * @param string $old_status The old order status.
	 * @param string $new_status The new order status.
	 * @return void
	 */
	private function clear_events_for_successful_payment( string $old_status, string $new_status ): void {
		$initial_checkout_statuses               = array( 'checkout-draft', 'pending', 'failed' );
		$successful_checkout_transition_statuses = array( 'processing', 'completed', 'on-hold' );

		// Skip for transitions starting on non initial checkout statuses.
		if ( ! in_array( $old_status, $initial_checkout_statuses, true ) ) {
			return;
		}

		// Skip for transitions ending on non checkout success statuses (e.g., 'failed' or 'cancelled').
		if ( ! in_array( $new_status, $successful_checkout_transition_statuses, true ) ) {
			return;
		}

		$this->session_data_collector->clear_collected_events();
	}

	/**
	 * Log a tracker callback failure.
	 *
	 * @param string     $hook The WordPress hook the failing callback is registered on.
	 * @param \Throwable $e    The caught throwable.
	 * @return void
	 */
	private function log_tracker_failure( string $hook, \Throwable $e ): void {
		$this->logger->log(
			'error',
			self::FAILURE_MESSAGE,
			array(
				'event_source'      => self::EVENT_SOURCE,
				'hook'              => $hook,
				'exception_class'   => $e::class,
				'exception_message' => $e->getMessage(),
				'exception_file'    => $e->getFile(),
				'exception_line'    => $e->getLine(),
			),
			true
		);
	}
}
