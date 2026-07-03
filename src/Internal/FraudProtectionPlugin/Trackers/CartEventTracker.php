<?php
/**
 * CartEventTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks cart events for fraud protection analysis.
 *
 * This class provides methods to track cart events (add, update, remove, restore)
 * for fraud protection. Event-specific data is passed
 * to the SessionDataCollector which handles session data storage internally.
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
	 * @param int       $product_id Product ID.
	 * @param int|float $quantity   Quantity added.
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
	 * @param string    $cart_item_key Cart item key.
	 * @param int|float $quantity      New quantity.
	 * @param int|float $old_quantity  Old quantity.
	 * @param object    $cart          Cart object.
	 * @return void
	 */
	public function track_cart_item_updated( $cart_item_key, $quantity, $old_quantity, $cart ): void {
		try {
			$cart_item = $cart->cart_contents[ $cart_item_key ] ?? null;

			if ( (float) $quantity === (float) $old_quantity || ! $cart_item ) {
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
	 * @param string    $action       Action type (item_added, item_updated, item_removed, item_restored).
	 * @param int       $product_id   Product ID.
	 * @param int|float $quantity     Quantity.
	 * @param int       $variation_id Variation ID.
	 * @return array Cart event data.
	 */
	private function build_cart_event_data( string $action, int $product_id, int|float $quantity, int $variation_id ): array {
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
			'cart_item_count' => $cart_item_count,
		);
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
