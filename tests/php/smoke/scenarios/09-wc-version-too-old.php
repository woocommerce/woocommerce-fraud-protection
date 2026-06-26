<?php
/**
 * Smoke scenario: WooCommerce older than the minimum supported version.
 *
 * As an MU-plugin this bypasses WordPress's "WC requires at least" enforcement,
 * so PluginInitializer enforces the minimum WooCommerce version at runtime. When
 * WC_VERSION is below the minimum, the bootstrap must skip initialization: log a
 * notice via error_log and return without loading any component class — and it
 * must do so before requiring the Composer autoloader.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

// WooCommerce is loaded, but at a version below the plugin's minimum.
define( 'WC_VERSION', '9.4.0' );

$error_log_path = wfp_smoke_capture_errors();

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php';

// Sanity check: bootstrap is still queued regardless of the WooCommerce version.
wfp_smoke_assert(
	isset( $GLOBALS['wfp_smoke_hooks']['woocommerce_loaded'] ),
	'Plugin should register woocommerce_loaded regardless of the WooCommerce version.'
);

// Fire the bootstrap. The minimum-version guard must short-circuit it.
do_action( 'woocommerce_loaded' );

wfp_smoke_assert(
	! class_exists( 'Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController', false ),
	'Bootstrap must bail without loading any component class when WooCommerce is below the minimum version.'
);

$logs = file_get_contents( $error_log_path );
wfp_smoke_assert(
	is_string( $logs ) && false !== strpos( $logs, 'requires WooCommerce' ),
	'Bootstrap must log an error_log message when WooCommerce is below the minimum version. Got: ' . var_export( $logs, true )
);

echo "OK\n";
