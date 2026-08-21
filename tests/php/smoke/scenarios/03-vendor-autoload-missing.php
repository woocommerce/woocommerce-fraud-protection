<?php
/**
 * Smoke scenario: vendor/autoload.php missing or unreadable.
 *
 * If the Composer autoloader is missing on a broken deploy, the bootstrap
 * closure must bail cleanly instead of fatalling on a require_once miss.
 *
 * Strategy: copy the main plugin file (and the manually-required
 * PluginInitializer, which the main file pulls in before the autoloader
 * exists) to a temp dir without a vendor/ subdirectory, include from there so
 * __DIR__ resolves to the temp dir, then fire the woocommerce_loaded action.
 * This models the realistic broken deploy: `composer install` never ran, so
 * vendor/ is absent but src/ is present.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

// Model the realistic broken deploy: WooCommerce is loaded at a supported
// version (so the minimum-version guard passes), but `composer install` never
// ran, so vendor/ is absent. Without this, the version guard would bail first.
define( 'WC_VERSION', '9.8.0' );

$tmp = sys_get_temp_dir() . '/wfp-smoke-no-vendor-' . uniqid();
mkdir( $tmp );
mkdir( $tmp . '/src/Internal/FraudProtectionPlugin', 0777, true );
copy( dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php', $tmp . '/woocommerce-fraud-protection.php' );
copy( dirname( __DIR__, 4 ) . '/src/Internal/FraudProtectionPlugin/PluginInitializer.php', $tmp . '/src/Internal/FraudProtectionPlugin/PluginInitializer.php' );

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
	! class_exists( 'Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController', false ),
	'Bootstrap must bail without loading any component class when autoload is missing.'
);

$logs = file_get_contents( $error_log_path );
wfp_smoke_assert(
	is_string( $logs ) && false !== strpos( $logs, 'autoloader is not readable' ),
	'Bootstrap must log an error_log message when autoload is missing. Got: ' . var_export( $logs, true )
);

echo "OK\n";
