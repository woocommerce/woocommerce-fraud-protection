<?php
/**
 * CartEventTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\QuantityValue;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks cart events for fraud protection analysis.
 *
 * This class provides methods to track cart events (add, update, remove, restore)
 * for fraud protection. Event-specific data is passed
 * to the SessionDataCollector which handles session data storage internally.
 *
 * Quantities are reported as WooCommerce supplies them, in whatever type it supplies them.
 * The derived cart item count is the plugin's own number rather than a relayed one, and is
 * reported only when it is finite.
 */
class CartEventTracker {

	/**
	 * Session data collector instance.
	 *
	 * @var SessionDataCollector
	 */
	private SessionDataCollector $session_data_collector;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionDataCollector $session_data_collector The session data collector instance.
	 */
	final public function init( SessionDataCollector $session_data_collector ): void {
		$this->session_data_collector = $session_data_collector;
	}

	/**
	 * Register all cart event tracking hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'internal_woocommerce_cart_item_added_from_user_request', array( $this, 'track_cart_item_added' ), 10, 2 );

		add_action( 'internal_woocommerce_cart_item_updated_from_user_request', array( $this, 'track_cart_item_updated' ), 10, 4 );

		add_action( 'internal_woocommerce_cart_item_removed_from_user_request', array( $this, 'track_cart_item_removed' ), 10, 2 );

		add_action( 'woocommerce_cart_item_restored', array( $this, 'track_cart_item_restored' ), 10, 2 );

		add_action( 'template_redirect', array( $this, 'track_cart_page_loaded' ), 10, 0 );
	}

	/**
	 * Track cart page loaded event.
	 *
	 * Collects session data when the cart page is initially loaded.
	 * This captures the initial session state before any user interactions.
	 *
	 * @internal
	 * @return void
	 */
	public function track_cart_page_loaded(): void {
		try {
			if ( function_exists( 'is_cart' ) && is_cart() ) {
				$this->session_data_collector->collect( 'cart_page_loaded', array() );
			}
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'template_redirect', $e );
		}
	}

	/**
	 * Track cart item added event.
	 *
	 * Collects session data when an item is added to the cart.
	 *
	 * @internal
	 *
	 * No native parameter types: a mistyped hook argument would throw at
	 * parameter binding, before the fail-open guard can catch it.
	 *
	 * @param int   $product_id Product ID.
	 * @param mixed $quantity   Quantity added, relayed verbatim.
	 * @return void
	 */
	public function track_cart_item_added( $product_id, $quantity ): void {
		try {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				return;
			}

			$variation_id = 0;

			if ( 'variation' === $product->get_type() ) {
				$variation_id = $product_id;
				$product_id   = $product->get_parent_id();
			}

			$event_data = $this->build_cart_event_data(
				'item_added',
				$product_id,
				$quantity,
				$variation_id
			);

			$this->session_data_collector->collect( 'cart_item_added', $event_data );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'internal_woocommerce_cart_item_added_from_user_request', $e );
		}
	}

	/**
	 * Track cart item quantity updated event.
	 *
	 * Collects session data when cart item quantity is updated.
	 *
	 * @internal
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param mixed  $quantity      New quantity, relayed verbatim.
	 * @param mixed  $old_quantity  Old quantity, relayed verbatim.
	 * @param object $cart          Cart object.
	 * @return void
	 */
	public function track_cart_item_updated( $cart_item_key, $quantity, $old_quantity, $cart ): void {
		try {
			$cart_item = $cart->cart_contents[ $cart_item_key ] ?? null;

			// Compare numeric values when possible; otherwise preserve type changes.
			$quantity_number     = QuantityValue::as_finite_float( $quantity );
			$old_quantity_number = QuantityValue::as_finite_float( $old_quantity );

			$unchanged = ( null !== $quantity_number && null !== $old_quantity_number )
				? $quantity_number === $old_quantity_number
				: $quantity === $old_quantity;

			if ( $unchanged || ! $cart_item ) {
				return;
			}

			$product_id   = $cart_item['product_id'] ?? 0;
			$variation_id = $cart_item['variation_id'] ?? 0;

			$event_data = $this->build_cart_event_data(
				'item_updated',
				$product_id,
				$quantity,
				$variation_id
			);

			$event_data['old_quantity'] = $old_quantity;

			$this->session_data_collector->collect( 'cart_item_updated', $event_data );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'internal_woocommerce_cart_item_updated_from_user_request', $e );
		}
	}

	/**
	 * Track cart item removed event.
	 *
	 * Collects session data when an item is removed from the cart.
	 *
	 * @internal
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param object $cart          Cart object.
	 * @return void
	 */
	public function track_cart_item_removed( $cart_item_key, $cart ): void {
		try {
			$cart_item = $cart->removed_cart_contents[ $cart_item_key ] ?? null;

			if ( ! $cart_item ) {
				return;
			}

			$product_id   = $cart_item['product_id'] ?? 0;
			$variation_id = $cart_item['variation_id'] ?? 0;
			$quantity     = $cart_item['quantity'] ?? 0;

			$event_data = $this->build_cart_event_data(
				'item_removed',
				$product_id,
				$quantity,
				$variation_id
			);

			$this->session_data_collector->collect( 'cart_item_removed', $event_data );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'internal_woocommerce_cart_item_removed_from_user_request', $e );
		}
	}

	/**
	 * Track cart item restored event.
	 *
	 * Collects session data when a removed item is restored to the cart.
	 *
	 * @internal
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param object $cart          Cart object.
	 * @return void
	 */
	public function track_cart_item_restored( $cart_item_key, $cart ): void {
		try {
			$cart_item = $cart->cart_contents[ $cart_item_key ] ?? null;

			if ( ! $cart_item ) {
				return;
			}

			$product_id   = $cart_item['product_id'] ?? 0;
			$variation_id = $cart_item['variation_id'] ?? 0;
			$quantity     = $cart_item['quantity'] ?? 0;

			$event_data = $this->build_cart_event_data(
				'item_restored',
				$product_id,
				$quantity,
				$variation_id
			);

			$this->session_data_collector->collect( 'cart_item_restored', $event_data );
		} catch ( \Throwable $e ) {
			$this->log_tracker_failure( 'woocommerce_cart_item_restored', $e );
		}
	}

	/**
	 * Build cart event-specific data.
	 *
	 * Prepares the cart event data including action type, product details,
	 * and current cart state. This data will be merged with comprehensive
	 * session data during event dispatching.
	 *
	 * The two quantity fields follow different policies on purpose. The quantity is reported as
	 * supplied, whatever its type; the derived count is reported only when finite — see
	 * finite_count() for why.
	 *
	 * @param string $action       Action type (item_added, item_updated, item_removed, item_restored).
	 * @param int    $product_id   Product ID.
	 * @param mixed  $quantity     Quantity, relayed verbatim.
	 * @param int    $variation_id Variation ID.
	 * @return array Cart event data.
	 */
	private function build_cart_event_data( string $action, int $product_id, mixed $quantity, int $variation_id ): array {
		$cart_item_count = 0;

		// Get current cart item count if cart is available.
		if ( WC()->cart instanceof \WC_Cart ) {
			$cart_item_count = WC()->cart->get_cart_contents_count();
		}

		return array(
			'action'          => $action,
			'product_id'      => $product_id,
			'quantity'        => $quantity,
			'variation_id'    => $variation_id,
			'cart_item_count' => self::finite_count( $cart_item_count ),
		);
	}

	/**
	 * Keep a derived cart count only when it is a finite number.
	 *
	 * Unlike the quantity beside it, the count is not supplied by anyone — the plugin derives it,
	 * so there is nothing to relay verbatim: it is either a number the payload can state or it is
	 * unknown. Substituting 0 would be worse than omitting, since 0 is this method's own "cart
	 * unavailable" value and would assert an empty cart at exactly the moment the real count is
	 * unknown.
	 *
	 * This is not made redundant by the encoding boundary. WooCommerce sums the count through the
	 * `woocommerce_cart_contents_count` filter, and a filter returning the *string* `'INF'` would
	 * sail through {@see EncodablePayload}, which keeps every string on purpose. Enforcing the
	 * field's own numeric shape is this method's job; the boundary only guarantees the request
	 * survives.
	 *
	 * A count WooCommerce states as a numeric string is still a count, so it is read the same way
	 * {@see OrderData::from_cart()} reads a money total: by numeric value rather than by PHP type.
	 * Rejecting `'3'` would lose a usable number without preventing any fabrication.
	 *
	 * @param mixed $value Raw count.
	 * @return int|float|null The count when it has a finite numeric reading, null otherwise.
	 */
	private static function finite_count( mixed $value ): int|float|null {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( ! is_numeric( $value ) ) {
			return null;
		}

		$number = (float) $value;

		if ( ! is_finite( $number ) ) {
			return null;
		}

		// A whole count is reported as a whole number whichever way it arrived, so '3' and 3 do
		// not reach the payload as different types. Only when the cast is lossless, though: the
		// comparison happens in float, where PHP_INT_MAX rounds up to 2^63 — one past the real
		// maximum — so an inclusive bound would admit 2^63 and cast it to a large negative,
		// reporting a count that is not merely wrong but the wrong sign. (float) PHP_INT_MIN is
		// exactly representable, so the lower bound is inclusive and the upper is not.
		$is_lossless_int = floor( $number ) === $number
			&& $number >= (float) PHP_INT_MIN
			&& $number < (float) PHP_INT_MAX;

		return $is_lossless_int ? (int) $number : $number;
	}

	/**
	 * Log a tracker callback failure (fail-open: the failure never reaches the shopper request).
	 *
	 * @param string     $hook The WordPress hook the failing callback is registered on.
	 * @param \Throwable $e    The caught throwable.
	 * @return void
	 */
	private function log_tracker_failure( string $hook, \Throwable $e ): void {
		FraudProtectionController::log(
			'error',
			'Cart event tracker callback failed',
			array(
				'event_source'      => 'cart_event_tracker',
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
