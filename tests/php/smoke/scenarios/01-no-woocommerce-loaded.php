<?php
/**
 * Smoke scenario: WooCommerce never loaded.
 *
 * Including the main plugin file when `woocommerce_loaded` never fires must
 * not touch any namespaced class. Verifies the closure is registered but
 * not executed, and that no fatal occurs.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php';

wfp_smoke_assert( ! defined( 'WC_FRAUD_PROTECTION_PLUGIN_URL' ), 'Plugin must not define a bootstrap-time asset URL.' );

wfp_smoke_assert(
	isset( $GLOBALS['wfp_smoke_hooks']['woocommerce_loaded'] ),
	'Plugin should register a woocommerce_loaded closure.'
);

wfp_smoke_assert(
	! class_exists( 'Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController', false ),
	'FraudProtectionController must NOT be loaded before woocommerce_loaded fires.'
);

echo "OK\n";
