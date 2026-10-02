<?php
/**
 * Smoke scenario: the MU-plugin copy yields to an active regular plugin copy.
 *
 * Both copies declare the same classes from different paths, so the MU-plugin copy
 * must not declare any class or register any hook. The regular copy must then load
 * as usual when WordPress includes it.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

require_once __DIR__ . '/../stubs/wp.php';

$managed_root = wfp_smoke_create_managed_fixture();
$regular_file = wfp_smoke_create_regular_copy();

$GLOBALS['wfp_smoke_active_plugins'] = array( $regular_file );

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_VERSION' ), 'The MU-plugin copy must not start when a regular copy is active.' );
wfp_smoke_assert( ! class_exists( PluginInitializer::class, false ), 'The MU-plugin copy must not declare plugin classes when a regular copy is active.' );
wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL' ), 'The loader must not mark a regular copy as a managed installation.' );
wfp_smoke_assert( array() === $GLOBALS['wfp_smoke_hooks'], 'The MU-plugin copy must not register hooks when a regular copy is active.' );

$GLOBALS['wfp_smoke_did_actions']['muplugins_loaded'] = 1;
require_once $regular_file;

wfp_smoke_assert( defined( 'WC_FRAUD_PROTECTION_VERSION' ), 'The regular copy must start.' );
wfp_smoke_assert( WC_FRAUD_PROTECTION_PLUGIN_FILE === $regular_file, 'The running copy must be the regular copy. Got: ' . WC_FRAUD_PROTECTION_PLUGIN_FILE );
wfp_smoke_assert( ! PluginInitializer::is_mu_plugin(), 'The regular copy must not report itself as an MU-plugin.' );
wfp_smoke_assert( isset( $GLOBALS['wfp_smoke_hooks']['woocommerce_loaded'] ), 'The regular copy must queue the bootstrap.' );

wfp_smoke_remove_dir( dirname( $regular_file, 2 ) );
wfp_smoke_remove_dir( $managed_root );

echo "OK\n";
