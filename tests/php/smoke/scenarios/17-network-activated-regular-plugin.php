<?php
/**
 * Smoke scenario: a network-activated regular copy takes precedence over the MU-plugin copy.
 *
 * WordPress loads network-activated plugins after MU-plugins but before `muplugins_loaded`,
 * so the regular copy must not mistake itself for an MU-plugin copy.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

require_once __DIR__ . '/../stubs/wp.php';

$managed_root = wfp_smoke_create_managed_fixture();
$regular_file = wfp_smoke_create_regular_copy();

$GLOBALS['wfp_smoke_multisite']              = true;
$GLOBALS['wfp_smoke_active_network_plugins'] = array( $regular_file );

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_VERSION' ), 'The MU-plugin copy must not start when a regular copy is network-activated.' );

require_once $regular_file;

wfp_smoke_assert( WC_FRAUD_PROTECTION_PLUGIN_FILE === $regular_file, 'The network-activated regular copy must start. Got: ' . ( defined( 'WC_FRAUD_PROTECTION_PLUGIN_FILE' ) ? WC_FRAUD_PROTECTION_PLUGIN_FILE : 'nothing' ) );
wfp_smoke_assert( ! PluginInitializer::is_mu_plugin(), 'The network-activated regular copy must not report itself as an MU-plugin.' );

wfp_smoke_remove_dir( dirname( $regular_file, 2 ) );
wfp_smoke_remove_dir( $managed_root );

echo "OK\n";
