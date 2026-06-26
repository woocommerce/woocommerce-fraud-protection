<?php
/**
 * FraudProtectionController class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

use Automattic\WooCommerce\FraudProtection\SessionVerifier;
use Automattic\WooCommerce\Internal\FraudProtection\Logging\LogContextSanitizer;
use Automattic\WooCommerce\Proxies\LegacyProxy;

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
	 * @param BlockedSessionNotice       $blocked_session_notice       The instance of BlockedSessionNotice to use.
	 * @param BlackboxScriptHandler      $blackbox_script_handler      The instance of BlackboxScriptHandler to use.
	 * @param CartEventTracker           $cart_event_tracker           The instance of CartEventTracker to use.
	 * @param CheckoutEventTracker       $checkout_event_tracker       The instance of CheckoutEventTracker to use.
	 * @param PaymentMethodEventTracker  $payment_method_event_tracker The instance of PaymentMethodEventTracker to use.
	 * @param SessionBlockingHandler     $session_blocking_handler     The instance of SessionBlockingHandler to use.
	 * @param SessionVerifier            $session_verifier             The instance of SessionVerifier to use.
	 * @param BlocksCheckoutProtector    $blocks_checkout_protector    The instance of BlocksCheckoutProtector to use.
	 * @param ShortcodeCheckoutProtector $shortcode_checkout_protector The instance of ShortcodeCheckoutProtector to use.
	 * @param AddPaymentMethodProtector  $add_payment_method_protector The instance of AddPaymentMethodProtector to use.
	 * @param PayForOrderProtector       $pay_for_order_protector      The instance of PayForOrderProtector to use.
	 */
	final public function init(
		BlockedSessionNotice $blocked_session_notice,
		BlackboxScriptHandler $blackbox_script_handler,
		CartEventTracker $cart_event_tracker,
		CheckoutEventTracker $checkout_event_tracker,
		PaymentMethodEventTracker $payment_method_event_tracker,
		SessionBlockingHandler $session_blocking_handler,
		SessionVerifier $session_verifier,
		BlocksCheckoutProtector $blocks_checkout_protector,
		ShortcodeCheckoutProtector $shortcode_checkout_protector,
		AddPaymentMethodProtector $add_payment_method_protector,
		PayForOrderProtector $pay_for_order_protector
	): void {
		$this->blocked_session_notice       = $blocked_session_notice;
		$this->blackbox_script_handler      = $blackbox_script_handler;
		$this->cart_event_tracker           = $cart_event_tracker;
		$this->checkout_event_tracker       = $checkout_event_tracker;
		$this->payment_method_event_tracker = $payment_method_event_tracker;
		$this->session_blocking_handler     = $session_blocking_handler;
		$this->session_verifier             = $session_verifier;
		$this->blocks_checkout_protector    = $blocks_checkout_protector;
		$this->shortcode_checkout_protector = $shortcode_checkout_protector;
		$this->add_payment_method_protector = $add_payment_method_protector;
		$this->pay_for_order_protector      = $pay_for_order_protector;
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
		$this->blocks_checkout_protector->register();
		$this->shortcode_checkout_protector->register();
		$this->add_payment_method_protector->register();
		$this->pay_for_order_protector->register();
		$this->session_blocking_handler->register();
		$this->cart_event_tracker->register();
		$this->checkout_event_tracker->register();
		$this->payment_method_event_tracker->register();
	}

	/**
	 * Check if fraud protection feature is enabled.
	 *
	 * @return bool
	 */
	public static function feature_is_enabled(): bool {
		// Always enabled as MU-plugin.
		return true;
	}

	/**
	 * Prefix used on entries forwarded to the PHP error log so they can be
	 * recognised in downstream log surfaces.
	 */
	private const PLATFORM_LOG_TAG = 'woo-fraud-protection';

	/**
	 * App-level severity to encoded line-number value used in the trailing
	 * `on line <N>` marker of forwarded entries.
	 *
	 * The host platform's PHP-errors parser extracts the integer after
	 * `on line` into a structured `line` field. Real PHP errors emit
	 * positive line numbers; parse-failure cases default to -1. The
	 * range [-50, -10] is reserved here to encode our app-level severity
	 * while staying collision-safe against both. Lucene query
	 * `line:[-50 TO -10]` isolates our intentional emissions.
	 *
	 * Levels below `warning` are not forwarded today; if a caller passes
	 * an unmapped level, {@see forward_to_platform_log()} falls back to
	 * the `warning` code.
	 */
	private const LEVEL_LINE_CODES = array(
		'warning'   => -10,
		'error'     => -20,
		'critical'  => -30,
		'alert'     => -40,
		'emergency' => -50,
	);

	/**
	 * Log helper method for consistent logging across all fraud protection components.
	 *
	 * Always writes to the local WooCommerce log with source
	 * `woo-fraud-protection` so entries are easy to filter under
	 * WooCommerce -> Status -> Logs.
	 *
	 * When `$forward_to_platform_log` is true, also emits a sanitized,
	 * tagged line via {@see error_log()}. The sanitizer drops any context
	 * key not on {@see LogContextSanitizer::ALLOWED_KEYS}. The local log
	 * entry keeps the full context regardless.
	 *
	 * Reserve `$forward_to_platform_log = true` for entries that signal
	 * something an operator would want to see in aggregated platform logs
	 * (transport failures, response parsing failures, plugin exception
	 * paths, third-party filter failures).
	 *
	 * @param string               $level                   Log level (emergency, alert, critical, error, warning, notice, info, debug).
	 * @param string               $message                 Log message.
	 * @param array<string, mixed> $context                 Optional context data.
	 * @param bool                 $forward_to_platform_log Whether to also forward a sanitized copy to the PHP error log. Defaults to false.
	 *
	 * @return void
	 */
	public static function log( string $level, string $message, array $context = array(), bool $forward_to_platform_log = false ): void {
		$message = self::prefix_message_with_identity( $message );

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log(
				$level,
				$message,
				array_merge( $context, array( 'source' => 'woo-fraud-protection' ) )
			);
		}

		if ( $forward_to_platform_log ) {
			self::forward_to_platform_log( $level, $message, $context );
		}
	}

	/**
	 * Emit a sanitized, tagged copy of a log entry to the PHP error log.
	 *
	 * Routes selected entries to the host platform's aggregated error log
	 * capture so they surface in centralised logging without requiring
	 * opt-in via the WooCommerce `remote_logging` feature. Independent of
	 * the local WooCommerce log.
	 *
	 * Line shape (consumed by the host's PHP-errors parser):
	 *
	 *     PHP Warning: [woo-fraud-protection <level>] <message>[ <json>] in <file> on line <N>
	 *
	 * - `PHP Warning:` is the parser-recognised prefix that maps the
	 *   entry to `severity:"Warning"`. The app-level severity is encoded
	 *   in the trailing `on line <N>` field per {@see LEVEL_LINE_CODES},
	 *   so a single `severity` value covers all forwarded levels and we
	 *   discriminate via `line`.
	 * - The `in <file> on line <N>` marker MUST be the final segment;
	 *   the parser regex is end-anchored. JSON content that happens to
	 *   contain `in /x on line 99` is safe because it sits before our
	 *   marker.
	 * - `<file>` is a fixed plugin-main-file path - not the real call
	 *   site. We only need it to be a stable path that lets the parser
	 *   extract `kind` (e.g. mu-plugins) and `name` consistently so the
	 *   entries can be filtered by plugin in the downstream index.
	 *
	 * @param string               $level   Log severity.
	 * @param string               $message Log message (already prefixed with identity ID if applicable).
	 * @param array<string, mixed> $context Original (unsanitized) context; the sanitizer enforces the allowlist.
	 *
	 * @return void
	 */
	private static function forward_to_platform_log( string $level, string $message, array $context ): void {
		$sanitized = LogContextSanitizer::sanitize( $context );
		$line_code = self::LEVEL_LINE_CODES[ $level ] ?? self::LEVEL_LINE_CODES['warning'];

		$body = sprintf( '[%s %s] %s', self::PLATFORM_LOG_TAG, $level, $message );
		if ( '' !== $sanitized ) {
			$body .= ' ' . $sanitized;
		}

		$line = sprintf(
			'PHP Warning: %s in %s on line %d',
			$body,
			self::get_plugin_marker_file(),
			$line_code
		);

		wc_get_container()->get( LegacyProxy::class )->call_function( 'error_log', $line );
	}

	/**
	 * Build the plugin-main-file path used in the parser-recognised
	 * `in <file>` marker of forwarded log entries.
	 *
	 * The path doesn't have to match the call site that produced the
	 * entry - the parser only uses it to extract `kind` and `name`
	 * fields in the downstream index. A fixed plugin-main-file path
	 * keeps those fields stable across emissions so the plugin's
	 * entries can be filtered as a single cohort.
	 *
	 * @return string Absolute path to the plugin's main file.
	 */
	private static function get_plugin_marker_file(): string {
		return dirname( __DIR__, 3 ) . '/woocommerce-fraud-protection.php';
	}

	/**
	 * Prefix a log message with the current session's identity ID when one
	 * is available.
	 *
	 * @param string $message Original message.
	 *
	 * @return string Message with `Identity: <id> | ` prefix when applicable.
	 */
	private static function prefix_message_with_identity( string $message ): string {
		$identity_id = self::get_session_identity_id();
		if ( '' === $identity_id ) {
			return $message;
		}

		return sprintf( 'Identity: %s | %s', $identity_id, $message );
	}

	/**
	 * Get the identity ID from the current WC session, if available.
	 *
	 * This is a read-only lookup — it will not initialize a session or generate an identity ID.
	 *
	 * @return string Identity ID, or empty string if not available.
	 */
	private static function get_session_identity_id(): string {
		$wc = function_exists( 'WC' ) ? WC() : null;
		if ( ! $wc instanceof \WooCommerce || ! $wc->session instanceof \WC_Session ) {
			return '';
		}

		$identity_id = $wc->session->get( SessionClearanceManager::CUSTOMER_IDENTITY_ID_KEY );

		return is_string( $identity_id ) ? $identity_id : '';
	}
}
