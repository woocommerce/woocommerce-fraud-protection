<?php
/**
 * Smoke scenario: managed loader registration precedes asset resolution.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

$managed_root = sys_get_temp_dir() . '/wfp-smoke-managed-' . uniqid();
$managed_dir  = $managed_root . '/woocommerce-fraud-protection';
$target       = $managed_dir . '/woocommerce-fraud-protection.php';
mkdir( $managed_dir, 0777, true );
symlink( dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php', $target );
define( 'WPMU_PLUGIN_DIR', $managed_root );

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

$invalid_asset_url = array( 'unexpected' );
wfp_smoke_assert(
	$invalid_asset_url === apply_filters( 'plugins_url', $invalid_asset_url ),
	'Managed filter must preserve a non-string URL value.'
);

$unrelated_asset_url = 'https://example.test/store/wp-content/plugins/other-plugin/1.0.0/assets/js/other.js';
wfp_smoke_assert(
	$unrelated_asset_url === apply_filters( 'plugins_url', $unrelated_asset_url, 'assets/js/other.js', dirname( WC_FRAUD_PROTECTION_PLUGIN_FILE ) . '/other.php' ),
	'Managed filter must not change unrelated plugin URLs.'
);

$asset_url = plugins_url( 'assets/js/blackbox-init.js', WC_FRAUD_PROTECTION_PLUGIN_FILE );

wfp_smoke_assert(
	'https://example.test/wp-content/mu-plugins/woocommerce-fraud-protection/assets/js/blackbox-init.js' === $asset_url,
	'Managed loader must correct the package URL and preserve the asset suffix. Got: ' . $asset_url
);
wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_PLUGIN_URL' ), 'Managed loader must not define the obsolete URL constant.' );

unlink( $target );
rmdir( $managed_dir );
rmdir( $managed_root );

echo "OK\n";
