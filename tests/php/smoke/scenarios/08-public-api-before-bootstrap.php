<?php
/**
 * Smoke scenario: wc_fraud_protection_report() called before WooCommerce's
 * dependency injection container is available.
 *
 * Gateway plugins may invoke the public API early in the request lifecycle,
 * before WooCommerce has finished loading. The function resolves its
 * collaborators through wc_get_container(), which WooCommerce only defines once
 * loaded, so it must bail silently instead of fatalling on the missing function.
 *
 * Strategy: include the plugin file but never fire woocommerce_loaded. Stub a
 * minimal WC() function and a dummy WC_Order class so the call type-checks, but
 * deliberately leave wc_get_container() undefined to mimic the pre-bootstrap state.
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

// Call the public API before bootstrap. With wc_get_container() undefined the
// function must bail silently; reaching the assertion below proves it did not fatal.
// A null context is passed because the namespaced ReportContextData class is not
// loadable pre-bootstrap; the guard bails before the context is ever inspected.
wc_fraud_protection_report( new WC_Order(), 'test-source', null, 'smoke-test' );

// The container is unavailable, so the function short-circuits at its guard
// before touching any namespaced class: no defensive autoload happens.
wfp_smoke_assert(
	! class_exists( 'Automattic\WooCommerce\FraudProtection\FraudProtectionController', false ),
	'Function should bail before loading any namespaced class when the container is unavailable.'
);

echo "OK\n";
