<?php
/**
 * Smoke scenario: vendor/autoload.php missing or unreadable.
 *
 * If the Composer autoloader is missing on a broken deploy, the bootstrap
 * closure must bail cleanly instead of fatalling on a require_once miss.
 *
 * Strategy: copy the main plugin file to a temp dir without a vendor/
 * subdirectory, include from there so __DIR__ resolves to the temp dir,
 * then fire the woocommerce_loaded action.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

$tmp = sys_get_temp_dir() . '/wfp-smoke-no-vendor-' . uniqid();
mkdir( $tmp );
copy( dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php', $tmp . '/woocommerce-fraud-protection.php' );

$error_log_path = wfp_smoke_capture_errors();

// Plugin file uses __DIR__, so this resolves to the vendor-less tmp dir.
require_once $tmp . '/woocommerce-fraud-protection.php';

// Sanity check: bootstrap is still queued.
wfp_smoke_assert(
	isset( $GLOBALS['wfp_smoke_hooks']['woocommerce_loaded'] ),
	'Plugin should still register woocommerce_loaded even with a broken autoload path.'
);

// Fire the bootstrap. Without our guard, this would fatal on the require_once.
do_action( 'woocommerce_loaded' );

wfp_smoke_assert(
	! class_exists( 'Automattic\WooCommerce\FraudProtection\FraudProtectionController', false ),
	'Bootstrap must bail without loading any namespaced class when autoload is missing.'
);

$logs = file_get_contents( $error_log_path );
wfp_smoke_assert(
	is_string( $logs ) && false !== strpos( $logs, 'autoloader is not readable' ),
	'Bootstrap must log an error_log message when autoload is missing. Got: ' . var_export( $logs, true )
);

echo "OK\n";
