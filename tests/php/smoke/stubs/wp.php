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

// These stubs keep WordPress function signatures and provide direct error-log capture.
// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter, Universal.NamingConventions.NoReservedKeywordParameterNames, WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.IniSet.log_errors_Disallowed, WordPress.PHP.IniSet.Risky

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
	/**
	 * Provide the add_action() test stub.
	 *
	 * @param mixed $hook Test value.
	 * @param mixed $callback Test value.
	 * @param mixed $priority Test value.
	 * @param mixed $accepted_args Test value.
	 */
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wfp_smoke_hooks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'add_filter' ) ) {
	/**
	 * Provide the add_filter() test stub.
	 *
	 * @param mixed $hook Test value.
	 * @param mixed $callback Test value.
	 * @param mixed $priority Test value.
	 * @param mixed $accepted_args Test value.
	 */
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['wfp_smoke_hooks'][ $hook ][] = $callback;
		return true;
	}
}

if ( ! function_exists( 'do_action' ) ) {
	/**
	 * Provide the do_action() test stub.
	 *
	 * @param mixed $hook Test value.
	 * @param mixed ...$args Test values.
	 */
	function do_action( $hook, ...$args ) {
		foreach ( $GLOBALS['wfp_smoke_hooks'][ $hook ] ?? array() as $callback ) {
			$callback( ...$args );
		}
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * Provide the apply_filters() test stub.
	 *
	 * @param mixed $hook Test value.
	 * @param mixed $value Test value.
	 * @param mixed ...$args Test values.
	 */
	function apply_filters( $hook, $value, ...$args ) {
		foreach ( $GLOBALS['wfp_smoke_hooks'][ $hook ] ?? array() as $callback ) {
			$value = $callback( $value, ...$args );
		}
		return $value;
	}
}

if ( ! function_exists( 'remove_filter' ) ) {
	/**
	 * Provide the remove_filter() test stub.
	 *
	 * @param mixed $hook Test value.
	 * @param mixed $callback Test value.
	 * @param mixed $priority Test value.
	 */
	function remove_filter( $hook, $callback, $priority = 10 ) {
		return true;
	}
}

if ( ! function_exists( 'has_action' ) ) {
	/**
	 * Provide the has_action() test stub.
	 *
	 * @param mixed $hook Test value.
	 * @param mixed $callback Test value.
	 */
	function has_action( $hook, $callback = null ) {
		return ! empty( $GLOBALS['wfp_smoke_hooks'][ $hook ] );
	}
}

if ( ! function_exists( 'plugin_dir_url' ) ) {
	/**
	 * Provide the plugin_dir_url() test stub.
	 *
	 * @param mixed $file Test value.
	 */
	function plugin_dir_url( $file ) {
		return 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}

if ( ! function_exists( 'plugins_url' ) ) {
	/**
	 * Provide the plugins_url() test stub.
	 *
	 * @param mixed $path Test value.
	 * @param mixed $plugin Test value.
	 * @param mixed $scheme Test value.
	 */
	function plugins_url( $path = '', $plugin = '', $scheme = null ) {
		$url  = 'https://example.test/store/wp-content/plugins/wordpress/plugins/woocommerce-fraud-protection/0.2.0/';
		$url .= ltrim( $path, '/' );

		// phpcs:ignore WooCommerce.Commenting.CommentHooks.MissingHookComment -- Test invokes the hook.
		return apply_filters( 'plugins_url', $url, $path, $plugin );
	}
}

if ( ! function_exists( 'plugin_dir_path' ) ) {
	/**
	 * Provide the plugin_dir_path() test stub.
	 *
	 * @param mixed $file Test value.
	 */
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Provide the get_option() test stub.
	 *
	 * @param mixed $key Test value.
	 * @param mixed $default Test value.
	 */
	function get_option( $key, $default = false ) {
		return $GLOBALS['wfp_smoke_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	/**
	 * Provide the update_option() test stub.
	 *
	 * @param mixed $key Test value.
	 * @param mixed $value Test value.
	 */
	function update_option( $key, $value ) {
		$GLOBALS['wfp_smoke_options'][ $key ] = $value;
		return true;
	}
}

// In-memory transient store. Expiration is ignored: scenarios run in a single
// short-lived process, so time-based expiry never comes into play.
if ( ! function_exists( 'get_transient' ) ) {
	/**
	 * Provide the get_transient() test stub.
	 *
	 * @param mixed $key Test value.
	 */
	function get_transient( $key ) {
		return $GLOBALS['wfp_smoke_transients'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'set_transient' ) ) {
	/**
	 * Provide the set_transient() test stub.
	 *
	 * @param mixed $key Test value.
	 * @param mixed $value Test value.
	 * @param mixed $expiration Test value.
	 */
	function set_transient( $key, $value, $expiration = 0 ) {
		$GLOBALS['wfp_smoke_transients'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'delete_transient' ) ) {
	/**
	 * Provide the delete_transient() test stub.
	 *
	 * @param mixed $key Test value.
	 */
	function delete_transient( $key ) {
		unset( $GLOBALS['wfp_smoke_transients'][ $key ] );
		return true;
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * Provide the esc_url() test stub.
	 *
	 * @param mixed $url Test value.
	 */
	function esc_url( $url ) {
		return $url;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Provide the esc_html() test stub.
	 *
	 * @param mixed $text Test value.
	 */
	function esc_html( $text ) {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * Provide the __() test stub.
	 *
	 * @param mixed $text Test value.
	 * @param mixed $domain Test value.
	 */
	function __( $text, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( '_x' ) ) {
	/**
	 * Provide the _x() test stub.
	 *
	 * @param mixed $text Test value.
	 * @param mixed $context Test value.
	 * @param mixed $domain Test value.
	 */
	function _x( $text, $context, $domain = '' ) {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_email' ) ) {
	/**
	 * Provide the sanitize_email() test stub.
	 *
	 * @param mixed $email Test value.
	 */
	function sanitize_email( $email ) {
		return is_string( $email ) ? trim( $email ) : '';
	}
}

if ( ! function_exists( 'is_checkout' ) ) {
	/**
	 * Provide the is_checkout() test stub.
	 */
	function is_checkout() {
		return false;
	}
}

if ( ! function_exists( 'is_checkout_pay_page' ) ) {
	/**
	 * Provide the is_checkout_pay_page() test stub.
	 */
	function is_checkout_pay_page() {
		return false;
	}
}

if ( ! function_exists( 'is_add_payment_method_page' ) ) {
	/**
	 * Provide the is_add_payment_method_page() test stub.
	 */
	function is_add_payment_method_page() {
		return false;
	}
}

if ( ! function_exists( 'is_order_received_page' ) ) {
	/**
	 * Provide the is_order_received_page() test stub.
	 */
	function is_order_received_page() {
		return false;
	}
}

if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	/**
	 * Provide the is_wc_endpoint_url() test stub.
	 *
	 * @param mixed $endpoint Test value.
	 */
	function is_wc_endpoint_url( $endpoint = false ) {
		return false;
	}
}

if ( ! function_exists( 'is_admin' ) ) {
	/**
	 * Provide the is_admin() test stub.
	 */
	function is_admin() {
		return false;
	}
}

if ( ! function_exists( 'is_preview' ) ) {
	/**
	 * Provide the is_preview() test stub.
	 */
	function is_preview() {
		return false;
	}
}

if ( ! function_exists( 'is_customize_preview' ) ) {
	/**
	 * Provide the is_customize_preview() test stub.
	 */
	function is_customize_preview() {
		return false;
	}
}

if ( ! function_exists( 'get_queried_object_id' ) ) {
	/**
	 * Provide the get_queried_object_id() test stub.
	 */
	function get_queried_object_id() {
		return 0;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	/**
	 * Provide the current_user_can() test stub.
	 *
	 * @param mixed $capability Test value.
	 * @param mixed ...$args Test values.
	 */
	function current_user_can( $capability, ...$args ) {
		return false;
	}
}

if ( ! function_exists( 'wp_script_is' ) ) {
	/**
	 * Provide the wp_script_is() test stub.
	 *
	 * @param mixed $handle Test value.
	 * @param mixed $list Test value.
	 */
	function wp_script_is( $handle, $list = 'enqueued' ) {
		return false;
	}
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
	/**
	 * Provide the wp_enqueue_script() test stub.
	 *
	 * @param mixed ...$args Test values.
	 */
	function wp_enqueue_script( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'wp_localize_script' ) ) {
	/**
	 * Provide the wp_localize_script() test stub.
	 *
	 * @param mixed ...$args Test values.
	 */
	function wp_localize_script( ...$args ) {
		return true;
	}
}

if ( ! function_exists( 'wc_add_notice' ) ) {
	/**
	 * Provide the wc_add_notice() test stub.
	 *
	 * @param mixed $message Test value.
	 * @param mixed $type Test value.
	 */
	function wc_add_notice( $message, $type = 'success' ) {
		return true;
	}
}

if ( ! function_exists( 'wc_has_notice' ) ) {
	/**
	 * Provide the wc_has_notice() test stub.
	 *
	 * @param mixed $message Test value.
	 * @param mixed $type Test value.
	 */
	function wc_has_notice( $message, $type = 'success' ) {
		return false;
	}
}

if ( ! function_exists( 'is_cart' ) ) {
	/**
	 * Provide the is_cart() test stub.
	 */
	function is_cart() {
		return false;
	}
}

if ( ! function_exists( 'is_shop' ) ) {
	/**
	 * Provide the is_shop() test stub.
	 */
	function is_shop() {
		return false;
	}
}

if ( ! function_exists( 'is_product_taxonomy' ) ) {
	/**
	 * Provide the is_product_taxonomy() test stub.
	 */
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
