<?php
/**
 * SessionBlockingHandler class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Handles session blocking logic via WooCommerce hooks.
 *
 * This class implements fraud protection blocking by hooking into:
 * - Add to cart validation
 * - Payment gateway availability
 * - Store API request filtering
 *
 * @since 10.5.0
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class SessionBlockingHandler {

	/**
	 * Session clearance manager instance.
	 *
	 * @var SessionClearanceManager
	 */
	private SessionClearanceManager $session_manager;

	/**
	 * Blocked session notice instance.
	 *
	 * @var BlockedSessionNotice
	 */
	private BlockedSessionNotice $blocked_notice;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionClearanceManager $session_manager The session clearance manager instance.
	 * @param BlockedSessionNotice    $blocked_notice  The blocked session notice instance.
	 */
	final public function init( SessionClearanceManager $session_manager, BlockedSessionNotice $blocked_notice ): void {
		$this->session_manager = $session_manager;
		$this->blocked_notice  = $blocked_notice;
	}

	/**
	 * Register hooks for session blocking.
	 *
	 * This method should only be called when fraud protection is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		// Block add-to-cart when session is blocked.
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 1, 5 );

		// Return empty payment gateways when session is blocked.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'filter_payment_gateways' ), 999, 1 );

		// Block Store API requests when session is blocked.
		add_filter( 'rest_pre_dispatch', array( $this, 'filter_store_api_requests' ), 10, 3 );
	}

	/**
	 * Validate add-to-cart action based on session status.
	 *
	 * Returns false with an error notice when the session is blocked,
	 * preventing items from being added to the cart.
	 *
	 * @internal
	 *
	 * @param bool  $passed        Whether validation passed.
	 * @param int   $_product_id   Product ID being added (unused).
	 * @param int   $_quantity     Quantity being added (unused).
	 * @param int   $_variation_id Variation ID (0 if not a variation) (unused).
	 * @param array $_variations   Variation attributes (unused).
	 * @return bool False if session is blocked, original value otherwise.
	 */
	public function validate_add_to_cart( $passed, $_product_id, $_quantity, $_variation_id = 0, $_variations = array() ): bool {
		if ( $this->session_manager->is_session_blocked() ) {
			wc_add_notice( $this->blocked_notice->get_message_html( 'purchase' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Filter available payment gateways based on session status.
	 *
	 * Returns an empty array when the session is blocked, effectively
	 * disabling all payment methods.
	 *
	 * @internal
	 *
	 * @param array $gateways Available payment gateways.
	 * @return array Empty array if session is blocked, original gateways otherwise.
	 */
	public function filter_payment_gateways( $gateways ) {
		if ( $this->session_manager->is_session_blocked() ) {
			return array();
		}

		return $gateways;
	}

	/**
	 * Filter Store API requests to block cart/checkout operations when session is blocked.
	 *
	 * Intercepts Store API requests and returns a 403 error for write operations
	 * (POST, PUT, PATCH, DELETE) on cart and checkout routes when the session is blocked.
	 *
	 * @internal
	 *
	 * @param mixed            $result  Response to replace the requested version with. Can be anything
	 *                                  a normal endpoint can return, or null to not hijack the request.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed|\WP_Error Original result or WP_Error if session is blocked.
	 */
	public function filter_store_api_requests( $result, $server, $request ) {
		// Only intercept if no result has been set yet.
		if ( null !== $result ) {
			return $result;
		}

		$route = $request->get_route();

		// Only intercept Store API routes.
		if ( strpos( $route, '/wc/store/v1/' ) !== 0 ) {
			return $result;
		}

		// Only block write operations.
		$method = $request->get_method();
		if ( ! in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
			return $result;
		}

		// Check if this is a route we want to block.
		$blocked_route_prefixes = array(
			'/wc/store/v1/cart/add-item',
			'/wc/store/v1/cart/remove-item',
			'/wc/store/v1/cart/update-item',
			'/wc/store/v1/cart/apply-coupon',
			'/wc/store/v1/cart/remove-coupon',
			'/wc/store/v1/cart/select-shipping-rate',
			'/wc/store/v1/cart/update-customer',
			'/wc/store/v1/checkout',
		);

		$should_block = false;
		foreach ( $blocked_route_prefixes as $prefix ) {
			if ( strpos( $route, $prefix ) === 0 ) {
				$should_block = true;
				break;
			}
		}

		if ( ! $should_block ) {
			return $result;
		}

		// Ensure session is loaded before checking status.
		$this->session_manager->ensure_cart_loaded();

		if ( $this->session_manager->is_session_blocked() ) {
			return new \WP_Error(
				'woocommerce_rest_forbidden',
				$this->blocked_notice->get_message_plaintext( 'purchase' ),
				array( 'status' => 403 )
			);
		}

		return $result;
	}
}
