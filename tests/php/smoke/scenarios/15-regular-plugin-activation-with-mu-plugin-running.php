<?php
/**
 * Smoke scenario: WordPress includes a regular copy to activate it while the MU-plugin copy runs.
 *
 * The regular copy is not active yet in that request, so the MU-plugin copy starts. The
 * activation include of the regular copy must then stop instead of redeclaring plugin classes.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

require_once __DIR__ . '/../stubs/wp.php';

$managed_root = wfp_smoke_create_managed_fixture();
$regular_file = wfp_smoke_create_regular_copy();

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

$mu_plugin_file = realpath( dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php' );
wfp_smoke_assert( WC_FRAUD_PROTECTION_PLUGIN_FILE === $mu_plugin_file, 'The MU-plugin copy must start when no regular copy is active.' );
wfp_smoke_assert( PluginInitializer::is_mu_plugin(), 'The MU-plugin copy must report itself as an MU-plugin.' );

$GLOBALS['wfp_smoke_did_actions']['muplugins_loaded'] = 1;
require_once $regular_file;

wfp_smoke_assert( WC_FRAUD_PROTECTION_PLUGIN_FILE === $mu_plugin_file, 'The activation include must not replace the running copy.' );
wfp_smoke_assert( PluginInitializer::is_mu_plugin(), 'The activation include must not change the running copy type.' );
wfp_smoke_assert( 1 === count( $GLOBALS['wfp_smoke_hooks']['woocommerce_loaded'] ), 'The activation include must not queue a second bootstrap.' );

wfp_smoke_remove_dir( dirname( $regular_file, 2 ) );
wfp_smoke_remove_dir( $managed_root );

echo "OK\n";
