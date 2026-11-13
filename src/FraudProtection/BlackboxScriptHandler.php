<?php
/**
 * BlackboxScriptHandler class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdError;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared browser scripts for payment-surface consumers.
 *
 * Resolve it from the WooCommerce container with
 * `wc_get_container()->get( BlackboxScriptHandler::class )` rather than constructing it:
 * the container supplies its dependencies through `init()`.
 */
class BlackboxScriptHandler {

	/**
	 * Blackbox JS SDK URL.
	 */
	private const BLACKBOX_JS_URL = 'https://blackbox-api.wp.com/v1/dist/v.js';

	/**
	 * API key prefix identifying WooCommerce as a Blackbox client.
	 */
	private const API_KEY_PREFIX = 'woo';

	/**
	 * Session ID acquisition timeout in milliseconds.
	 *
	 * Browser-side fail-open deadline for Blackbox.flush(). Sized to bound
	 * checkout latency when the SDK or its backend is slow; on timeout we
	 * proceed with the session ID the SDK still has, or an empty one, and let
	 * server-side verify decide.
	 */
	private const SESSION_ID_TIMEOUT_MS = 3000;

	/**
	 * Transient that limits the missing-blog-ID log to once per site per hour.
	 */
	private const MISSING_BLOG_ID_LOG_TRANSIENT = 'wc_fraud_protection_missing_blog_id_log';

	/**
	 * Session identity manager.
	 *
	 * @var SessionIdentityManager
	 */
	private SessionIdentityManager $session_identity_manager;

	/**
	 * Initialize dependencies.
	 *
	 * @internal
	 *
	 * @param SessionIdentityManager $session_identity_manager Session identity manager.
	 */
	final public function init( SessionIdentityManager $session_identity_manager ): void {
		$this->session_identity_manager = $session_identity_manager;
	}

	/**
	 * Request the shared scripts for the current payment surface.
	 *
	 * A false result requires the caller to skip its dependent script. A missing
	 * blog ID is checked on every call, while its log is limited to once per site
	 * per hour.
	 *
	 * @return bool True when the shared scripts are enqueued, now or earlier in the request.
	 *
	 * @since 0.1.9
	 */
	public function request_scripts(): bool {
		if ( $this->is_excluded_render_context() ) {
			return false;
		}

		if ( $this->blackbox_init_is_configured() ) {
			return true;
		}

		$blog_id = $this->get_blog_id();

		if ( ! $blog_id ) {
			$this->maybe_log_missing_blog_id();
			return false;
		}

		$this->enqueue_scripts( $blog_id );

		return true;
	}

	/**
	 * Make one of your scripts depend on the shared scripts.
	 *
	 * Call it right after you register your script, wherever you register it. It registers
	 * the shared scripts without enqueueing them and adds `wc-fraud-protection-blackbox-init`
	 * to the dependencies of `$handle`. WordPress then prints the shared scripts only on the
	 * pages where your script is enqueued, before it, so `window.wcFraudProtection` is set up
	 * before your script runs. Pass only a script that you enqueue on your payment surfaces,
	 * since the shared scripts load on every page where it is enqueued. On pages that must
	 * not load them, such as the order confirmation page or a post preview, the shared
	 * scripts are left out when the scripts are printed, and your script loads without them.
	 *
	 * @param string $handle Handle of a script registered with WordPress.
	 * @return bool True when `$handle` depends on the shared scripts; false when they are unavailable, or `$handle` is not registered or was already printed.
	 *
	 * @since 0.3.2
	 */
	public function register_scripts_for( string $handle ): bool {
		$scripts = wp_scripts();
		$script  = $scripts->query( $handle, 'registered' );
		if ( ! $script instanceof \_WP_Dependency || in_array( $handle, $scripts->done, true ) ) {
			FraudProtectionController::log(
				'warning',
				'Shared scripts were requested for a script that is not registered or was already printed',
				array( 'script_handle' => $handle )
			);
			return false;
		}

		// Registration can run before the main query, so only the admin check is reliable
		// here; the other excluded pages are handled when the scripts are printed.
		if ( is_admin() ) {
			return false;
		}

		// No missing-blog-ID log here: this can run on every page, and request_scripts()
		// already logs it on the payment surfaces.
		$blog_id = $this->get_blog_id();
		if ( ! $blog_id ) {
			return false;
		}

		$this->register_shared_scripts( $blog_id );

		if ( ! in_array( 'wc-fraud-protection-blackbox-init', $script->deps, true ) ) {
			$script->deps[] = 'wc-fraud-protection-blackbox-init';
		}

		if ( false === has_filter( 'print_scripts_array', array( $this, 'leave_out_shared_scripts_on_excluded_pages' ) ) ) {
			add_filter( 'print_scripts_array', array( $this, 'leave_out_shared_scripts_on_excluded_pages' ) );
		}

		return true;
	}

	/**
	 * Leave the shared scripts out of the printed scripts on pages that must not load them.
	 *
	 * Added by register_scripts_for(). A script that depends on the shared scripts still
	 * prints, without `window.wcFraudProtection`, and sends its requests without a session ID.
	 *
	 * @internal
	 *
	 * @param mixed $to_do Handles of the scripts about to be printed.
	 * @return mixed The handles, without the shared scripts on excluded pages.
	 */
	public function leave_out_shared_scripts_on_excluded_pages( mixed $to_do ): mixed {
		if ( ! is_array( $to_do ) ) {
			return $to_do;
		}

		$shared_scripts = array( 'wc-fraud-protection-blackbox', 'wc-fraud-protection-blackbox-init' );
		if ( ! array_intersect( $shared_scripts, $to_do ) || ! $this->is_excluded_render_context() ) {
			return $to_do;
		}

		return array_values( array_diff( $to_do, $shared_scripts ) );
	}

	/**
	 * Request the shared scripts and the script that protects the `#order_review` pay form.
	 *
	 * For an extension with its own handler for WooCommerce's pay-for-order form, such as
	 * a change-payment-method flow. The `wc-fraud-protection-pay-for-order` script holds
	 * the form's submit until it has added the session ID as a hidden field; read it on the
	 * server with {@see ClassicFormRequestData}. Call it from the hook that renders the form.
	 *
	 * @return bool True when the scripts are enqueued; false when the shared scripts are unavailable.
	 *
	 * @since 0.3.2
	 */
	public function request_pay_for_order_script(): bool {
		if ( ! $this->request_scripts() ) {
			return false;
		}

		wp_enqueue_script(
			'wc-fraud-protection-pay-for-order',
			plugins_url( 'assets/js/pay-for-order.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox-init', 'jquery' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);

		return true;
	}

	/**
	 * Enqueue the Blackbox SDK and initialization scripts.
	 *
	 * @param int $blog_id The Jetpack blog ID.
	 * @return void
	 */
	private function enqueue_scripts( int $blog_id ): void {
		$this->register_shared_scripts( $blog_id );

		wp_enqueue_script( 'wc-fraud-protection-blackbox' );
		wp_enqueue_script( 'wc-fraud-protection-blackbox-init' );
	}

	/**
	 * Register and configure the Blackbox SDK and initialization scripts, once per request.
	 *
	 * @param int $blog_id The Jetpack blog ID.
	 * @return void
	 */
	private function register_shared_scripts( int $blog_id ): void {
		if ( wp_script_is( 'wc-fraud-protection-blackbox-init', 'registered' ) ) {
			return;
		}

		wp_register_script(
			'wc-fraud-protection-blackbox',
			self::BLACKBOX_JS_URL,
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External SDK, version managed by Blackbox CDN.
			array( 'in_footer' => true )
		);

		wp_register_script(
			'wc-fraud-protection-blackbox-init',
			plugins_url( 'assets/js/blackbox-init.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);

		// The init script reads the identity from its cookie, so no per-visitor value is rendered into the page.
		wp_localize_script(
			'wc-fraud-protection-blackbox-init',
			'wcFraudProtection',
			array(
				'config' => array(
					'apiKey'              => self::API_KEY_PREFIX . ':' . $blog_id,
					'identityCookie'      => $this->session_identity_manager->get_identity_cookie_settings(),
					'timeout'             => self::SESSION_ID_TIMEOUT_MS,
					'sessionIdField'      => SessionVerifier::SESSION_ID_FIELD,
					'sessionIdErrorField' => SessionIdError::FIELD,
				),
			)
		);
	}

	/**
	 * Get the Jetpack blog ID.
	 *
	 * @return int|false Blog ID or false if not available.
	 */
	private function get_blog_id(): int|false {
		if ( ! class_exists( \Jetpack_Options::class ) ) {
			return false;
		}

		$blog_id = \Jetpack_Options::get_option( 'id' );

		if ( ! is_numeric( $blog_id ) || (int) $blog_id <= 0 ) {
			return false;
		}

		return (int) $blog_id;
	}

	/**
	 * Check whether this request must not load payment telemetry.
	 *
	 * @return bool
	 */
	private function is_excluded_render_context(): bool {
		return is_admin()
			|| $this->is_rest_request()
			|| $this->is_editor_preview()
			|| is_customize_preview()
			|| $this->is_order_confirmation_page();
	}

	/**
	 * Log a missing Jetpack blog ID at most once per site per hour.
	 *
	 * The transient limits only the log. Each caller still checks the Jetpack
	 * option, so a connection completed after a miss can recover immediately.
	 *
	 * @return void
	 */
	private function maybe_log_missing_blog_id(): void {
		if ( false !== get_transient( self::MISSING_BLOG_ID_LOG_TRANSIENT ) ) {
			return;
		}

		set_transient( self::MISSING_BLOG_ID_LOG_TRANSIENT, 1, HOUR_IN_SECONDS );

		FraudProtectionController::log(
			'error',
			'Blackbox scripts not loaded: Jetpack blog ID not available. Is the site connected to Jetpack?',
			array( 'event_source' => 'blackbox_script_enqueue' ),
			true
		);
	}

	/**
	 * Check whether a REST endpoint is being handled.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		if ( function_exists( 'wp_is_rest_endpoint' ) ) {
			return wp_is_rest_endpoint();
		}

		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	/**
	 * Check whether an authorized editor is previewing a post.
	 *
	 * @return bool
	 */
	private function is_editor_preview(): bool {
		if ( ! is_preview() ) {
			return false;
		}

		$previewed_post_id = get_queried_object_id();

		return $previewed_post_id > 0 && current_user_can( 'edit_post', $previewed_post_id );
	}

	/**
	 * Check whether this is an order confirmation page without a pay form.
	 *
	 * @return bool
	 */
	private function is_order_confirmation_page(): bool {
		return is_order_received_page() && ! is_checkout_pay_page();
	}

	/**
	 * Check whether the shared init script is configured.
	 *
	 * @return bool
	 */
	private function blackbox_init_is_configured(): bool {
		return wp_script_is( 'wc-fraud-protection-blackbox-init', 'registered' )
			&& wp_script_is( 'wc-fraud-protection-blackbox-init', 'enqueued' );
	}
}
