<?php
/**
 * Smoke scenario: WC() returning null / stub without `version`.
 *
 * SessionDataCollector::get_collected_data() reads WC()->version. Pre-fix
 * this fataled with a null deref or undefined-property error when WC() was
 * unavailable or returned an unusual stub. Post-fix it falls back to an
 * empty string for wc_version.
 *
 * Covers two failure modes:
 *   1. WC() returns null entirely (function defined but returns null).
 *   2. WC() returns a stub object that has no `version` property.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

require_once dirname( __DIR__, 4 ) . '/vendor/autoload.php';

// Stub WC() to return null. We swap the return value via a global between calls.
$GLOBALS['wfp_smoke_wc_value'] = null;

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return $GLOBALS['wfp_smoke_wc_value'];
	}
}

$session_manager = new \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionClearanceManager();
$collector       = new \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionDataCollector();
$collector->init( $session_manager );

// 1. WC() returns null.
$GLOBALS['wfp_smoke_wc_value'] = null;
$data                          = $collector->get_collected_data();

wfp_smoke_assert(
	is_array( $data ) && '' === $data['wc_version'],
	'When WC() is null, wc_version must be empty string. Got: ' . var_export( $data['wc_version'] ?? null, true )
);

// 2. WC() returns a stub without `version`.
$GLOBALS['wfp_smoke_wc_value'] = new stdClass(); // No `version` property.
$data                          = $collector->get_collected_data();

wfp_smoke_assert(
	is_array( $data ) && '' === $data['wc_version'],
	'When WC() returns a stub without version, wc_version must be empty string. Got: ' . var_export( $data['wc_version'] ?? null, true )
);

// 3. clear_collected_events() must not fatal when WC() is null.
// Pre-fix this fatals on `WC()->session` chain deref.
$GLOBALS['wfp_smoke_wc_value'] = null;
$collector->clear_collected_events();

echo "OK\n";
