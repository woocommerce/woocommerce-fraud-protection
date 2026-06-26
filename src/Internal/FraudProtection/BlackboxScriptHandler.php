<?php
/**
 * BlackboxScriptHandler class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Handles loading Blackbox JS telemetry script on payment method pages.
 *
 * Enqueues the external Blackbox JS SDK and a small initialization script
 * on checkout, pay-for-order, and add-payment-method pages. The init script
 * calls Blackbox.configure() with the site's API key and Jetpack blog ID.
 *
 * @internal This class is part of the internal API and is subject to change without notice.
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
	 * Name of the form field carrying the Blackbox session ID.
	 *
	 * Used by classic form protectors (via ClassicFormDataExtractionTrait)
	 * and passed to JS via wcFraudProtection.config.
	 */
	public const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

	/**
	 * Session clearance manager.
	 *
	 * @var SessionClearanceManager
	 */
	private SessionClearanceManager $session_clearance_manager;

	/**
	 * Initialize dependencies.
	 *
	 * @internal
	 *
	 * @param SessionClearanceManager $session_clearance_manager Session clearance manager.
	 */
	final public function init( SessionClearanceManager $session_clearance_manager ): void {
		$this->session_clearance_manager = $session_clearance_manager;
	}

	/**
	 * Register hooks for Blackbox script loading.
	 *
	 * Called from FraudProtectionController::on_init() which already checks
	 * if the feature is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_scripts' ) );
	}

	/**
	 * Conditionally enqueue Blackbox scripts on payment method pages.
	 *
	 * Loads scripts on checkout (including custom pages with the checkout block),
	 * pay-for-order, and add-payment-method pages.
	 * Extensions can use the `woocommerce_fraud_protection_enqueue_blackbox_scripts`
	 * filter to load scripts on additional pages (e.g., product pages for express payments).
	 *
	 * @return void
	 */
	public function maybe_enqueue_scripts(): void {
		global $wp, $post;

		// WC_Blocks_Utils ships with WooCommerce, but guard defensively in case the
		// blocks package is partially loaded or missing on an unusual setup.
		$has_checkout_block = class_exists( \WC_Blocks_Utils::class )
			&& \WC_Blocks_Utils::has_block_in_page( $post, 'woocommerce/checkout' );

		// is_add_payment_method_page returns true for the payment methods list, check for the actual
		// add payment method page where payment details are collected.
		$is_add_payment_method_page = is_add_payment_method_page() && isset( $wp->query_vars['add-payment-method'] );

		$is_payment_page = is_checkout() ||
			$has_checkout_block ||
			is_checkout_pay_page() ||
			$is_add_payment_method_page;

		// $is_payment_page matches the order-received page. Exclude it here.
		$should_enqueue = $is_payment_page && ! is_order_received_page();

		/**
		 * Filter whether to enqueue Blackbox fraud protection scripts on the current page.
		 *
		 * By default, scripts are loaded on checkout, pay-for-order, and add-payment-method pages.
		 * Extensions can return true to load scripts on additional pages where payment methods
		 * are rendered (e.g., product pages for express checkout buttons).
		 *
		 * @since 0.1.0
		 *
		 * @param bool $should_enqueue Whether to enqueue Blackbox scripts on the current page.
		 */
		$should_enqueue = (bool) apply_filters( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', $should_enqueue );

		if ( ! $should_enqueue ) {
			return;
		}

		$blog_id = $this->get_blog_id();

		if ( ! $blog_id ) {
			FraudProtectionController::log(
				'error',
				'Blackbox scripts not loaded: Jetpack blog ID not available. Is the site connected to Jetpack?',
				array( 'event_source' => 'blackbox_script_enqueue' ),
				true
			);
			return;
		}

		$this->enqueue_scripts( $blog_id );
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

		// Enqueue the Woo Fraud Protection init script.
		wp_enqueue_script(
			'wc-fraud-protection-blackbox-init',
			WC_FRAUD_PROTECTION_PLUGIN_URL . 'assets/js/blackbox-init.js',
			array( 'wc-fraud-protection-blackbox' ),
			WC_FRAUD_PROTECTION_VERSION,
			array( 'in_footer' => true )
		);

		$wc_identity_id = $this->session_clearance_manager->get_identity_id();

		wp_localize_script(
			'wc-fraud-protection-blackbox-init',
			'wcFraudProtection',
			array(
				'config' => array(
					'apiKey'         => self::API_KEY_PREFIX . ':' . $blog_id,
					'identityKey'    => $wc_identity_id,
					'timeout'        => self::SESSION_ID_TIMEOUT_MS,
					'sessionIdField' => self::SESSION_ID_FIELD,
				),
			)
		);
	}

	/**
	 * Get the Jetpack blog ID.
	 *
	 * @return int|false Blog ID or false if not available.
	 */
	private function get_blog_id() {
		if ( ! class_exists( \Jetpack_Options::class ) ) {
			return false;
		}

		$blog_id = \Jetpack_Options::get_option( 'id' );

		if ( ! is_numeric( $blog_id ) || (int) $blog_id <= 0 ) {
			return false;
		}

		return (int) $blog_id;
	}
}
