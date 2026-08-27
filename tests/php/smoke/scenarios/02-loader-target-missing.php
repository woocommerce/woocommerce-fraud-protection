<?php
/**
 * Smoke scenario: loader target file missing.
 *
 * When the symlink target on WPCloud is broken, including the MU loader
 * must not WSOD the site. Verifies the loader logs a clear error_log
 * message and bails cleanly when the target plugin file is unreadable.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

// Point WPMU_PLUGIN_DIR at a directory that does not contain the plugin.
// The loader builds its target path as WPMU_PLUGIN_DIR/woocommerce-fraud-protection/woocommerce-fraud-protection.php.
$broken_root = sys_get_temp_dir() . '/wfp-smoke-broken-' . uniqid();
mkdir( $broken_root );
define( 'WPMU_PLUGIN_DIR', $broken_root );

$error_log_path = wfp_smoke_capture_errors();

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

wfp_smoke_assert(
	! defined( 'WC_FRAUD_PROTECTION_VERSION' ),
	'Loader must NOT include the plugin file when target is missing.'
);

wfp_smoke_assert(
	! defined( 'WC_FRAUD_PROTECTION_PLUGIN_URL' ),
	'Loader must NOT define the asset URL when target is missing.'
);

$logs = file_get_contents( $error_log_path );
wfp_smoke_assert(
	is_string( $logs ) && false !== strpos( $logs, 'target plugin file is not readable' ),
	'Loader must log an error_log message when the target is unreadable. Got: ' . var_export( $logs, true )
);

echo "OK\n";
