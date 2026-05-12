<?php
/**
 * Smoke scenario: wc_fraud_protection_report() called before woocommerce_loaded.
 *
 * Gateway plugins may invoke the public API early in the request lifecycle.
 * The function must defensively load the autoloader (or bail silently) instead
 * of fatalling on the unloaded FraudProtectionController class reference.
 *
 * Strategy: include the plugin file but never fire woocommerce_loaded. Stub
 * a minimal WC() function and a dummy WC_Order class so the call type-checks.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return null;
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php';

wfp_smoke_assert(
	! class_exists( 'Automattic\WooCommerce\FraudProtection\FraudProtectionController', false ),
	'Pre-condition: FraudProtectionController must NOT be loaded yet.'
);

// Call the public API before bootstrap. Pre-fix this would fatal on the
// `FraudProtectionController::feature_is_enabled()` reference.
wc_fraud_protection_report( new WC_Order(), 'test-source', 'good', 'smoke-test' );

// After the call, the autoloader has been defensively loaded by the function,
// so the class is now available.
wfp_smoke_assert(
	class_exists( 'Automattic\WooCommerce\FraudProtection\FraudProtectionController' ),
	'Defensive autoload should have made FraudProtectionController available.'
);

echo "OK\n";
