<?php
/**
 * BlackboxScriptHandler class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared browser scripts for payment-surface consumers.
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
	 * Browser-side fail-open race for Blackbox.getSessionId(). Sized to bound
	 * checkout latency when the SDK or its backend is slow; on timeout we
	 * proceed with an empty session ID and let server-side verify decide.
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
	 * Enqueue the Blackbox SDK and initialization scripts.
	 *
	 * @param int $blog_id The Jetpack blog ID.
	 * @return void
	 */
	private function enqueue_scripts( int $blog_id ): void {
		wp_enqueue_script(
			'wc-fraud-protection-blackbox',
			self::BLACKBOX_JS_URL,
			array(),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- External SDK, version managed by Blackbox CDN.
			array( 'in_footer' => true )
		);

		wp_enqueue_script(
			'wc-fraud-protection-blackbox-init',
			plugins_url( 'assets/js/blackbox-init.js', WC_FRAUD_PROTECTION_PLUGIN_FILE ),
			array( 'wc-fraud-protection-blackbox' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);

		$wc_identity_id = $this->session_identity_manager->get_identity_id();

		wp_localize_script(
			'wc-fraud-protection-blackbox-init',
			'wcFraudProtection',
			array(
				'config' => array(
					'apiKey'         => self::API_KEY_PREFIX . ':' . $blog_id,
					'identityKey'    => $wc_identity_id,
					'timeout'        => self::SESSION_ID_TIMEOUT_MS,
					'sessionIdField' => SessionVerifier::SESSION_ID_FIELD,
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
