<?php
/**
 * Smoke scenario: CheckoutSchema class absent.
 *
 * On a partial Store API load (older WC, or blocks build skew), the
 * woocommerce_store_api_register_endpoint_data() function may exist while
 * Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema does not.
 * register_store_api_extension() must bail before reading
 * CheckoutSchema::IDENTIFIER instead of fatalling.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

require_once dirname( __DIR__, 4 ) . '/vendor/autoload.php';

// Stub the registration function but NOT the schema class. The plugin must
// detect the missing class and bail before referencing CheckoutSchema::IDENTIFIER.
$register_calls = 0;
if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
	function woocommerce_store_api_register_endpoint_data( ...$args ) {
		global $register_calls;
		++$register_calls;
		return true;
	}
}

wfp_smoke_assert(
	! class_exists( 'Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema' ),
	'Pre-condition: CheckoutSchema must NOT exist.'
);

// register_store_api_extension is private - call register() to exercise it.
// register() runs register_store_api_extension() unconditionally, which is the
// path under test.
$session_verifier       = new \Automattic\WooCommerce\FraudProtection\SessionVerifier();
$blocked_session_notice = new \Automattic\WooCommerce\Internal\FraudProtection\BlockedSessionNotice();

$protector = new \Automattic\WooCommerce\Internal\FraudProtection\BlocksCheckoutProtector();
$protector->init( $session_verifier, $blocked_session_notice );
$protector->register();

wfp_smoke_assert(
	0 === $register_calls,
	'woocommerce_store_api_register_endpoint_data must NOT be called when CheckoutSchema is missing.'
);

echo "OK\n";
