<?php
/**
 * Smoke scenario: a nested managed main file loads before the root loader.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php';

$managed_root = sys_get_temp_dir() . '/wfp-smoke-managed-nested-' . uniqid();
$managed_dir  = $managed_root . '/woocommerce-fraud-protection';
$target       = $managed_dir . '/woocommerce-fraud-protection.php';
mkdir( $managed_dir, 0777, true );
symlink( dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php', $target );
define( 'WPMU_PLUGIN_DIR', $managed_root );

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

$asset_url = plugins_url( 'assets/js/blocks-checkout.js', WC_FRAUD_PROTECTION_PLUGIN_FILE );

wfp_smoke_assert(
	'https://example.test/wp-content/mu-plugins/woocommerce-fraud-protection/assets/js/blocks-checkout.js' === $asset_url,
	'The root loader must correct an asset URL after the nested main file loads. Got: ' . $asset_url
);

unlink( $target );
rmdir( $managed_dir );
rmdir( $managed_root );

echo "OK\n";
