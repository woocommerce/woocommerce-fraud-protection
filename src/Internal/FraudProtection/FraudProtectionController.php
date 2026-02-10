<?php
/**
 * FraudProtectionController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

use Automattic\WooCommerce\Internal\Jetpack\JetpackConnection;

defined( 'ABSPATH' ) || exit;

/**
 * Main controller for fraud protection features.
 *
 * This class orchestrates all fraud protection components and ensures
 * zero-impact when the feature flag is disabled.
 *
 * @since 10.5.0
 * @internal This class is part of the internal API and is subject to change without notice.
 */
class FraudProtectionController /* implements RegisterHooksInterface */ {


	/**
	 * Blocked session notice instance.
	 *
	 * @var BlockedSessionNotice
	 */
	private BlockedSessionNotice $blocked_session_notice;

	/**
	 * Blackbox script handler instance.
	 *
	 * @var BlackboxScriptHandler
	 */
	private BlackboxScriptHandler $blackbox_script_handler;

	/**
	 * Blocks checkout protector instance.
	 *
	 * @var BlocksCheckoutProtector
	 */
	private BlocksCheckoutProtector $blocks_checkout_protector;

	/**
	 * Cart event tracker instance.
	 *
	 * @var CartEventTracker
	 */
	private CartEventTracker $cart_event_tracker;

	/**
	 * Checkout event tracker instance.
	 *
	 * @var CheckoutEventTracker
	 */
	private CheckoutEventTracker $checkout_event_tracker;

	/**
	 * Payment method event tracker instance.
	 *
	 * @var PaymentMethodEventTracker
	 */
	private PaymentMethodEventTracker $payment_method_event_tracker;

	/**
	 * Session blocking handler instance.
	 *
	 * @var SessionBlockingHandler
	 */
	private SessionBlockingHandler $session_blocking_handler;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'on_init' ) );
	}

	/**
	 * Initialize the instance, runs when the instance is created by the dependency injection container.
	 *
	 * @internal
	 *
	 * @param BlockedSessionNotice      $blocked_session_notice       The instance of BlockedSessionNotice to use.
	 * @param BlackboxScriptHandler     $blackbox_script_handler      The instance of BlackboxScriptHandler to use.
	 * @param CartEventTracker          $cart_event_tracker           The instance of CartEventTracker to use.
	 * @param CheckoutEventTracker      $checkout_event_tracker       The instance of CheckoutEventTracker to use.
	 * @param PaymentMethodEventTracker $payment_method_event_tracker The instance of PaymentMethodEventTracker to use.
	 * @param SessionBlockingHandler    $session_blocking_handler     The instance of SessionBlockingHandler to use.
	 * @param BlocksCheckoutProtector   $blocks_checkout_protector The instance of BlocksCheckoutProtector to use.
	 */
	final public function init(
		BlockedSessionNotice $blocked_session_notice,
		BlackboxScriptHandler $blackbox_script_handler,
		CartEventTracker $cart_event_tracker,
		CheckoutEventTracker $checkout_event_tracker,
		PaymentMethodEventTracker $payment_method_event_tracker,
		SessionBlockingHandler $session_blocking_handler,
		BlocksCheckoutProtector $blocks_checkout_protector
	): void {
		$this->blocked_session_notice       = $blocked_session_notice;
		$this->blackbox_script_handler      = $blackbox_script_handler;
		$this->cart_event_tracker           = $cart_event_tracker;
		$this->checkout_event_tracker       = $checkout_event_tracker;
		$this->payment_method_event_tracker = $payment_method_event_tracker;
		$this->session_blocking_handler     = $session_blocking_handler;
		$this->blocks_checkout_protector    = $blocks_checkout_protector;
	}

	/**
	 * Hook into WordPress on init.
	 *
	 * @internal
	 */
	public function on_init(): void {
		// Bail if the feature is not enabled.
		if ( ! $this->feature_is_enabled() ) {
			return;
		}

		$this->blocked_session_notice->register();
		$this->blackbox_script_handler->register();
		$this->blocks_checkout_protector->register();
		$this->session_blocking_handler->register();
		$this->register_event_tracking_hooks();
	}

	/**
	 * Register all event tracking hooks.
	 *
	 * @internal
	 */
	private function register_event_tracking_hooks(): void {
		// Cart event tracking.
		add_action( 'woocommerce_add_to_cart', array( $this->cart_event_tracker, 'track_cart_item_added' ), 10, 4 );
		add_action( 'woocommerce_cart_item_removed', array( $this->cart_event_tracker, 'track_cart_item_removed' ), 10, 2 );
		add_action( 'woocommerce_cart_item_restored', array( $this->cart_event_tracker, 'track_cart_item_restored' ), 10, 2 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this->cart_event_tracker, 'track_cart_item_updated' ), 10, 4 );

		// Checkout event tracking.
		add_action( 'woocommerce_checkout_order_processed', array( $this->checkout_event_tracker, 'track_order_placed_from_shortcode' ), 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this->checkout_event_tracker, 'track_order_placed_from_store_api' ), 10, 1 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this->checkout_event_tracker, 'track_shortcode_checkout_field_update' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this->checkout_event_tracker, 'track_blocks_checkout_update' ), 10, 2 );

		// Payment method event tracking.
		add_action( 'woocommerce_new_payment_token', array( $this->payment_method_event_tracker, 'track_payment_method_added' ), 10, 2 );
		add_action( 'before_woocommerce_add_payment_method', array( $this->payment_method_event_tracker, 'track_add_payment_method_page_loaded' ), 10, 0 );

		// Page load tracking.
		add_action( 'template_redirect', array( $this, 'track_page_load_events' ), 10, 0 );
	}

	/**
	 * Track page load events for cart and checkout pages.
	 *
	 * @internal
	 */
	public function track_page_load_events(): void {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			$this->cart_event_tracker->track_cart_page_loaded();
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			$this->checkout_event_tracker->track_checkout_page_loaded();
		}
	}

	/**
	 * Check if fraud protection feature is enabled.
	 *
	 * This method can be used by other fraud protection classes to check
	 * the feature flag status. Returns false (fail-open) if init hasn't run yet.
	 *
	 * @return bool True if enabled, false if not enabled or init hasn't run yet.
	 */
	public function feature_is_enabled(): bool {
		// Fail-open: don't block if init hasn't run yet to avoid FeaturesController translation notices.
		if ( ! did_action( 'init' ) ) {
			return false;
		}
		// Always enabled as MU-plugin.
		return true;
	}

	/**
	 * Log helper method for consistent logging across all fraud protection components.
	 *
	 * This static method ensures all fraud protection logs are written with
	 * the same 'woo-fraud-protection' source for easy filtering in WooCommerce logs.
	 *
	 * @param string $level   Log level (emergency, alert, critical, error, warning, notice, info, debug).
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 *
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		wc_get_logger()->log(
			$level,
			$message,
			array_merge( $context, array( 'source' => 'woo-fraud-protection' ) )
		);
	}
}
