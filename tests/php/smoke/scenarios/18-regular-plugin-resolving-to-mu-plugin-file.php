<?php
/**
 * Smoke scenario: an active regular plugin entry resolves to the MU-plugin copy's own file.
 *
 * Managed hosts can link both entries to the same shared plugin directory. WordPress will not
 * include the same file again, so the MU-plugin copy must start instead of yielding to itself.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

require_once __DIR__ . '/../stubs/wp.php';

$managed_root = wfp_smoke_create_managed_fixture();

$GLOBALS['wfp_smoke_active_plugins'] = array( $managed_root . '/woocommerce-fraud-protection/woocommerce-fraud-protection.php' );

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

wfp_smoke_assert( defined( 'WC_FRAUD_PROTECTION_VERSION' ), 'The MU-plugin copy must start when the regular entry is the same file.' );
wfp_smoke_assert( PluginInitializer::is_mu_plugin(), 'The MU-plugin copy must report itself as an MU-plugin.' );
wfp_smoke_assert( defined( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL' ) && WC_FRAUD_PROTECTION_MANAGED_INSTALL, 'The root loader must identify the managed installation.' );

wfp_smoke_remove_dir( $managed_root );

echo "OK\n";
