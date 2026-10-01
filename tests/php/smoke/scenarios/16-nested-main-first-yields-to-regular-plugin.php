<?php
/**
 * Smoke scenario: a nested managed main file loads before the root loader while a regular copy is active.
 *
 * The main file must yield on its own, and the root loader must then keep managed-installation
 * behavior off.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer;

require_once __DIR__ . '/../stubs/wp.php';

$regular_file = wfp_smoke_create_regular_copy();

$GLOBALS['wfp_smoke_active_plugins'] = array( $regular_file );

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php';

wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_VERSION' ), 'The nested main file must not start when a regular copy is active.' );
wfp_smoke_assert( ! class_exists( PluginInitializer::class, false ), 'The nested main file must not declare plugin classes when a regular copy is active.' );

$managed_root = wfp_smoke_create_managed_fixture();
require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_VERSION' ), 'The root loader must not start the MU-plugin copy when a regular copy is active.' );
wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_MANAGED_INSTALL' ), 'The root loader must not mark a regular copy as a managed installation.' );
wfp_smoke_assert( array() === $GLOBALS['wfp_smoke_hooks'], 'The root loader must not register hooks when a regular copy is active.' );

wfp_smoke_remove_dir( dirname( $regular_file, 2 ) );
wfp_smoke_remove_dir( $managed_root );

echo "OK\n";
