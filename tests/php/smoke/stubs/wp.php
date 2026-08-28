<?php
/**
 * Minimal WP/WC stubs for smoke tests.
 *
 * These stubs let us include the plugin entry points and individual classes
 * without booting a full WP test environment. They cover the bare minimum
 * surface area each smoke scenario needs: hook recording, option storage,
 * plugin URL resolution, escape helpers, and translation passthroughs.
 *
 * Each scenario is responsible for defining or stubbing class symbols
 * (CheckoutSchema, WC_Emails, WooCommerce, etc.) as needed.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/wfp-smoke-abspath/' );
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

// Hook registry for assertions in tests.
$GLOBALS['wfp_smoke_hooks']      = array();
$GLOBALS['wfp_smoke_options']    = array();
$GLOBALS['wfp_smoke_transients'] = array();

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wfp_smoke_hooks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wfp_smoke_hooks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['wfp_smoke_hooks'][ $hook ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['wfp_smoke_hooks'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	function remove_filter( $hook, $callback, $priority = 10 ) {
		return true;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	function has_action( $hook, $callback = null ) {
		return ! empty( $GLOBALS['wfp_smoke_hooks'][ $hook ] );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	function plugins_url( $path = '', $plugin = '', $scheme = null ) {
		unset( $scheme );
		$url = 'https://example.test/store/wp-content/plugins/wordpress/plugins/woocommerce-fraud-protection/0.2.0/';
		$url .= ltrim( $path, '/' );

		return apply_filters( 'plugins_url', $url, $path, $plugin );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['wfp_smoke_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['wfp_smoke_options'][ $key ] = $value;
		return true;
	}
}

// In-memory transient store. Expiration is ignored: scenarios run in a single
// short-lived process, so time-based expiry never comes into play.
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['wfp_smoke_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['wfp_smoke_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		unset( $GLOBALS['wfp_smoke_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	function _x( $text, $context, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return is_string( $email ) ? trim( $email ) : '';
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	function is_checkout() {
		return false;
	}
}

if ( ! function_exists( 'is_checkout_pay_page' ) ) {
	function is_checkout_pay_page() {
		return false;
	}
}

if ( ! function_exists( 'is_add_payment_method_page' ) ) {
	function is_add_payment_method_page() {
		return false;
	}
}

if ( ! function_exists( 'is_order_received_page' ) ) {
	function is_order_received_page() {
		return false;
	}
}

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	function is_wc_endpoint_url( $endpoint = false ) {
		return false;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'is_preview' ) ) {
	function is_preview() {
		return false;
	}
}

if ( ! function_exists( 'is_customize_preview' ) ) {
	function is_customize_preview() {
		return false;
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return 0;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability, ...$args ) {
		return false;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return false;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	function wp_localize_script( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	function wc_add_notice( $message, $type = 'success' ) {
		return true;
	}
}

if ( ! function_exists( 'wc_has_notice' ) ) {
	function wc_has_notice( $message, $type = 'success' ) {
		return false;
	}
}

if ( ! function_exists( 'is_cart' ) ) {
	function is_cart() {
		return false;
	}
}

if ( ! function_exists( 'is_shop' ) ) {
	function is_shop() {
		return false;
	}
}

if ( ! function_exists( 'is_product_taxonomy' ) ) {
	function is_product_taxonomy() {
		return false;
	}
}

/**
 * Assertion helper used by smoke scenarios.
 *
 * Exits the process with status 1 and prints the failure message to stderr
 * when $cond is false. Keeps each scenario short and explicit.
 *
 * @param bool   $cond    Condition to assert.
 * @param string $message Failure message.
 * @return void
 */
function wfp_smoke_assert( bool $cond, string $message ): void {
	if ( ! $cond ) {
		fwrite( STDERR, "ASSERTION FAILED: $message\n" );
		exit( 1 );
	}
}

/**
 * Capture error_log output to a temp file and return the path.
 *
 * Each scenario can call this once to redirect error_log() into a known file,
 * then assert the contents match what the guarded fail-open path is expected to log.
 *
 * @return string Path to the temp error log file.
 */
function wfp_smoke_capture_errors(): string {
	$path = tempnam( sys_get_temp_dir(), 'wfp-smoke-errors-' );
	ini_set( 'log_errors', '1' );
	ini_set( 'error_log', $path );
	return $path;
}
