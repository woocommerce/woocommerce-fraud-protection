<?php
/**
 * FraudProtectionController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Main controller for fraud protection features.
 *
 * This class orchestrates all fraud protection components and ensures
 * zero-impact when the feature flag is disabled.
 *
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
	 * Shortcode checkout protector instance.
	 *
	 * @var ShortcodeCheckoutProtector
	 */
	private ShortcodeCheckoutProtector $shortcode_checkout_protector;

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
	 * Add payment method protector instance.
	 *
	 * @var AddPaymentMethodProtector
	 */
	private AddPaymentMethodProtector $add_payment_method_protector;

	/**
	 * Pay-for-order protector instance.
	 *
	 * @var PayForOrderProtector
	 */
	private PayForOrderProtector $pay_for_order_protector;

	/**
	 * Session verifier instance.
	 *
	 * @var SessionVerifier
	 */
	private SessionVerifier $session_verifier;

	/**
	 * Order events tracker instance.
	 *
	 * @var OrderEventsTracker
	 */
	private OrderEventsTracker $order_events_tracker;

	/**
	 * Session blocking handler instance.
	 *
	 * @var SessionBlockingHandler
	 */
	private SessionBlockingHandler $session_blocking_handler;

	/**
	 * PayPal compatibility instance.
	 *
	 * @var Compat\PayPalCompat
	 */
	private Compat\PayPalCompat $paypal_compat;

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
	 * @param BlockedSessionNotice       $blocked_session_notice       The instance of BlockedSessionNotice to use.
	 * @param BlackboxScriptHandler      $blackbox_script_handler      The instance of BlackboxScriptHandler to use.
	 * @param CartEventTracker           $cart_event_tracker           The instance of CartEventTracker to use.
	 * @param CheckoutEventTracker       $checkout_event_tracker       The instance of CheckoutEventTracker to use.
	 * @param PaymentMethodEventTracker  $payment_method_event_tracker The instance of PaymentMethodEventTracker to use.
	 * @param SessionBlockingHandler     $session_blocking_handler     The instance of SessionBlockingHandler to use.
	 * @param SessionVerifier            $session_verifier             The instance of SessionVerifier to use.
	 * @param OrderEventsTracker         $order_events_tracker         The instance of OrderEventsTracker to use.
	 * @param BlocksCheckoutProtector    $blocks_checkout_protector    The instance of BlocksCheckoutProtector to use.
	 * @param ShortcodeCheckoutProtector $shortcode_checkout_protector The instance of ShortcodeCheckoutProtector to use.
	 * @param AddPaymentMethodProtector  $add_payment_method_protector The instance of AddPaymentMethodProtector to use.
	 * @param PayForOrderProtector       $pay_for_order_protector      The instance of PayForOrderProtector to use.
	 * @param Compat\PayPalCompat        $paypal_compat                The instance of PayPalCompat to use.
	 */
	final public function init(
		BlockedSessionNotice $blocked_session_notice,
		BlackboxScriptHandler $blackbox_script_handler,
		CartEventTracker $cart_event_tracker,
		CheckoutEventTracker $checkout_event_tracker,
		PaymentMethodEventTracker $payment_method_event_tracker,
		SessionBlockingHandler $session_blocking_handler,
		SessionVerifier $session_verifier,
		OrderEventsTracker $order_events_tracker,
		BlocksCheckoutProtector $blocks_checkout_protector,
		ShortcodeCheckoutProtector $shortcode_checkout_protector,
		AddPaymentMethodProtector $add_payment_method_protector,
		PayForOrderProtector $pay_for_order_protector,
		Compat\PayPalCompat $paypal_compat
	): void {
		$this->blocked_session_notice       = $blocked_session_notice;
		$this->blackbox_script_handler      = $blackbox_script_handler;
		$this->cart_event_tracker           = $cart_event_tracker;
		$this->checkout_event_tracker       = $checkout_event_tracker;
		$this->payment_method_event_tracker = $payment_method_event_tracker;
		$this->session_blocking_handler     = $session_blocking_handler;
		$this->session_verifier             = $session_verifier;
		$this->order_events_tracker         = $order_events_tracker;
		$this->blocks_checkout_protector    = $blocks_checkout_protector;
		$this->shortcode_checkout_protector = $shortcode_checkout_protector;
		$this->add_payment_method_protector = $add_payment_method_protector;
		$this->pay_for_order_protector      = $pay_for_order_protector;
		$this->paypal_compat                = $paypal_compat;
	}

	/**
	 * Hook into WordPress on init.
	 *
	 * @internal
	 */
	public function on_init(): void {
		// Bail if the feature is not enabled.
		if ( ! self::feature_is_enabled() ) {
			return;
		}

		$this->blocked_session_notice->register();
		$this->blackbox_script_handler->register();
		$this->session_verifier->register();
		$this->order_events_tracker->register();
		$this->blocks_checkout_protector->register();
		$this->shortcode_checkout_protector->register();
		$this->add_payment_method_protector->register();
		$this->pay_for_order_protector->register();
		$this->session_blocking_handler->register();
		$this->cart_event_tracker->register();
		$this->checkout_event_tracker->register();
		$this->payment_method_event_tracker->register();
		$this->paypal_compat->register();
	}

	/**
	 * Check if fraud protection feature is enabled.
	 *
	 * This method can be used by other fraud protection classes to check
	 * the feature flag status. Returns false (fail-open) if init hasn't run yet.
	 *
	 * @return bool True if enabled, false if not enabled or init hasn't run yet.
	 */
	public static function feature_is_enabled(): bool {
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
