<?php
/**
 * FraudProtectionController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalDecisionReuse;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalPaymentDataCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\PayPalScriptCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\SquarePaymentDataCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\StripePaymentDataCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\SubscriptionsChangePaymentCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat\WooPaymentsPaymentDataCompat;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Database\SchemaManager;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Logging\FraudProtectionLogger;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\AddPaymentMethodProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\BlocksCheckoutProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\PayForOrderProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionEventPruner;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\CartEventTracker;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\CheckoutEventTracker;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Trackers\PaymentMethodEventTracker;

defined( 'ABSPATH' ) || exit;

/**
 * Main controller for fraud protection features.
 *
 * This class orchestrates all fraud protection components and ensures
 * zero-impact when the feature flag is disabled.
 *
 * Resolving an instance from the DI container (which calls {@see init()}) wires
 * the static {@see log()} facade; the class must be resolved before that facade
 * is used. {@see register()} then hooks the instance into WordPress.
 */
class FraudProtectionController /* implements RegisterHooksInterface */ {

	/**
	 * Logger used by the static {@see log()} facade.
	 *
	 * @var FraudProtectionLogger
	 */
	protected static FraudProtectionLogger $logger;

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
	 * Database schema manager instance.
	 *
	 * @var SchemaManager
	 */
	private SchemaManager $schema_manager;

	/**
	 * Session event pruner instance.
	 *
	 * @var SessionEventPruner
	 */
	private SessionEventPruner $session_event_pruner;

	/**
	 * Register hooks. To be run at `woocommerce_loaded`.
	 */
	public function register(): void {
		if ( ! self::feature_is_enabled() ) {
			return;
		}

		$this->register_compat_layers();

		add_action( 'init', array( $this, 'handle_init' ) );
	}

	/**
	 * Register the payment gateway compatibility layers.
	 *
	 * @return void
	 */
	private function register_compat_layers(): void {
		$container = wc_get_container();

		$container->get( StripePaymentDataCompat::class )->register();
		$container->get( SquarePaymentDataCompat::class )->register();
		$container->get( PayPalPaymentDataCompat::class )->register();
		$container->get( WooPaymentsPaymentDataCompat::class )->register();
		$container->get( PayPalCompat::class )->register();
		$container->get( PayPalDecisionReuse::class )->register();
		$container->get( PayPalScriptCompat::class )->register();
		$container->get( SubscriptionsChangePaymentCompat::class )->register();
	}

	/**
	 * Initialize the instance, runs when the instance is created by the dependency injection container.
	 *
	 * @internal
	 *
	 * @param CartEventTracker           $cart_event_tracker           The instance of CartEventTracker to use.
	 * @param CheckoutEventTracker       $checkout_event_tracker       The instance of CheckoutEventTracker to use.
	 * @param PaymentMethodEventTracker  $payment_method_event_tracker The instance of PaymentMethodEventTracker to use.
	 * @param SessionVerifier            $session_verifier             The instance of SessionVerifier to use.
	 * @param BlocksCheckoutProtector    $blocks_checkout_protector    The instance of BlocksCheckoutProtector to use.
	 * @param ShortcodeCheckoutProtector $shortcode_checkout_protector The instance of ShortcodeCheckoutProtector to use.
	 * @param AddPaymentMethodProtector  $add_payment_method_protector The instance of AddPaymentMethodProtector to use.
	 * @param PayForOrderProtector       $pay_for_order_protector      The instance of PayForOrderProtector to use.
	 * @param SchemaManager              $schema_manager               The instance of SchemaManager to use.
	 * @param SessionEventPruner         $session_event_pruner         The instance of SessionEventPruner to use.
	 * @param FraudProtectionLogger      $logger                       The logger instance.
	 */
	final public function init(
		CartEventTracker $cart_event_tracker,
		CheckoutEventTracker $checkout_event_tracker,
		PaymentMethodEventTracker $payment_method_event_tracker,
		SessionVerifier $session_verifier,
		BlocksCheckoutProtector $blocks_checkout_protector,
		ShortcodeCheckoutProtector $shortcode_checkout_protector,
		AddPaymentMethodProtector $add_payment_method_protector,
		PayForOrderProtector $pay_for_order_protector,
		SchemaManager $schema_manager,
		SessionEventPruner $session_event_pruner,
		FraudProtectionLogger $logger
	): void {
		self::$logger = $logger;

		$this->cart_event_tracker           = $cart_event_tracker;
		$this->checkout_event_tracker       = $checkout_event_tracker;
		$this->payment_method_event_tracker = $payment_method_event_tracker;
		$this->session_verifier             = $session_verifier;
		$this->blocks_checkout_protector    = $blocks_checkout_protector;
		$this->shortcode_checkout_protector = $shortcode_checkout_protector;
		$this->add_payment_method_protector = $add_payment_method_protector;
		$this->pay_for_order_protector      = $pay_for_order_protector;
		$this->schema_manager               = $schema_manager;
		$this->session_event_pruner         = $session_event_pruner;
	}

	/**
	 * Register the first-party components on the WordPress `init` hook.
	 *
	 * @internal
	 */
	public function handle_init(): void {
		$this->schema_manager->register();
		$this->session_event_pruner->register();
		$this->session_verifier->register();
		$this->blocks_checkout_protector->register();
		$this->shortcode_checkout_protector->register();
		$this->add_payment_method_protector->register();
		$this->pay_for_order_protector->register();
		$this->cart_event_tracker->register();
		$this->checkout_event_tracker->register();
		$this->payment_method_event_tracker->register();
	}

	/**
	 * Check if fraud protection feature is enabled.
	 *
	 * Static facade kept for backwards compatibility; delegates to the
	 * registered instance's {@see is_feature_enabled()}.
	 *
	 * @return bool
	 */
	public static function feature_is_enabled(): bool {
		// Always enabled as MU-plugin.
		return true;
	}

	/**
	 * Log helper method for consistent logging across all fraud protection components.
	 *
	 * Static facade kept for backwards compatibility; delegates to the logger
	 * service installed by {@see init()}.
	 *
	 * @param string               $level                   Log level (emergency, alert, critical, error, warning, notice, info, debug).
	 * @param string               $message                 Log message.
	 * @param array<string, mixed> $context                 Optional context data.
	 * @param bool                 $forward_to_platform_log Whether to also forward a sanitized copy to the PHP error log. Defaults to false.
	 *
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array(), bool $forward_to_platform_log = false ): void {
		self::$logger->log( $level, $message, $context, $forward_to_platform_log );
	}
}
