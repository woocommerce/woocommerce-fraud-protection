<?php
/**
 * CartEventTracker class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks cart events for fraud protection analysis.
 *
 * This class provides methods to track cart events (add, update, remove, restore)
 * for fraud protection. Event-specific data is passed
 * to the SessionDataCollector which handles session data storage internally.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
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
	 * @internal
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_loaded', array( $this, 'track_cart_item_added_via_product_page' ), 30 ); // Needs to run after WC_Form_Handler::add_to_cart_action().
		add_action( 'woocommerce_store_api_cart_item_add_from_request', array( $this, 'track_cart_item_added_via_store_api' ), 10, 3 );
		add_action( 'woocommerce_ajax_added_to_cart', array( $this, 'track_cart_item_added' ), 10, 1 );

		add_action( 'woocommerce_cart_item_removed', array( $this, 'track_cart_item_removed' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'track_cart_item_restored' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'track_cart_item_updated' ), 10, 4 );
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
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			$this->session_data_collector->collect( 'cart_page_loaded', array() );
		}
	}

	/**
	 * Track cart item added via Store API.
	 *
	 * @internal
	 *
	 * @param string   $item_id  Cart item key.
	 * @param int      $quantity Quantity added.
	 * @param \WC_Cart $cart     Cart object.
	 * @return void
	 */
	public function track_cart_item_added_via_store_api( string $item_id, int $quantity, \WC_Cart $cart ): void {
		$cart_item = $cart->get_cart_item( $item_id );
		if ( ! $cart_item ) {
			return;
		}

		$product_id = $cart_item['variation_id'] ? $cart_item['variation_id'] : $cart_item['product_id'];

		$this->track_cart_item_added( $product_id );
	}

	/**
	 * Track cart item added via product page form.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function track_cart_item_added_via_product_page(): void {
		if ( ! isset( $_REQUEST['add-to-cart'] ) || ! is_numeric( wp_unslash( $_REQUEST['add-to-cart'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return;
		}

		/** This filter is documented in WooCommerce core. */
		$product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( wp_unslash( $_REQUEST['add-to-cart'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$this->track_cart_item_added( $product_id );
	}

	/**
	 * Track cart item added event.
	 *
	 * Collects session data when an item is added to the cart.
	 *
	 * @internal
	 *
	 * @param int $product_id Product ID.
	 * @return void
	 */
	public function track_cart_item_added( int $product_id ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$quantity     = empty( $_POST['quantity'] ) ? 1 : (int) wc_stock_amount( wp_unslash( $_POST['quantity'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
	}

	/**
	 * Track cart item quantity updated event.
	 *
	 * Collects session data when cart item quantity is updated.
	 *
	 * @internal
	 *
	 * @param string $cart_item_key Cart item key.
	 * @param int    $quantity      New quantity.
	 * @param int    $old_quantity  Old quantity.
	 * @param object $cart          Cart object.
	 * @return void
	 */
	public function track_cart_item_updated( $cart_item_key, $quantity, $old_quantity, $cart ): void {
		$cart_item = $cart->cart_contents[ $cart_item_key ] ?? null;

		if ( (int) $quantity === (int) $old_quantity || ! $cart_item ) {
			return;
		}

		$product_id   = $cart_item['product_id'] ?? 0;
		$variation_id = $cart_item['variation_id'] ?? 0;

		$event_data = $this->build_cart_event_data(
			'item_updated',
			$product_id,
			(int) $quantity,
			$variation_id
		);

		$event_data['old_quantity'] = (int) $old_quantity;

		$this->session_data_collector->collect( 'cart_item_updated', $event_data );
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
	}

	/**
	 * Build cart event-specific data.
	 *
	 * Prepares the cart event data including action type, product details,
	 * and current cart state. This data will be merged with comprehensive
	 * session data during event dispatching.
	 *
	 * @param string $action       Action type (item_added, item_updated, item_removed, item_restored).
	 * @param int    $product_id   Product ID.
	 * @param int    $quantity     Quantity.
	 * @param int    $variation_id Variation ID.
	 * @return array Cart event data.
	 */
	private function build_cart_event_data( string $action, int $product_id, int $quantity, int $variation_id ): array {
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
}
