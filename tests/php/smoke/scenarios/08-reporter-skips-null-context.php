<?php
/**
 * Smoke scenario: FraudProtectionReporter skips a report with no context.
 *
 * FraudProtectionReporter::report() backs the public reporting API. Given a null
 * context (the "unmappable event" case that ReportContextData::from_array()
 * returns), it must skip silently — log a warning and return without touching
 * its tracker collaborator or fatalling — even without a fully booted
 * WooCommerce.
 *
 * Strategy: load the plugin's classes via the Composer autoloader and register a
 * FraudProtectionController so its static log() facade is wired (this is what
 * boot does in production), then call report() with a null context on a reporter
 * that was deliberately never given a tracker via init(). The skip must happen
 * before the (uninitialized) tracker is accessed, so reaching the assertion proves
 * there was no fatal; the captured warning confirms the skip branch actually ran.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

if ( ! class_exists( 'WC_Order' ) ) {
	class WC_Order {} // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
}

// Capture warnings logged via wc_get_logger() so the skip path can be asserted.
$GLOBALS['wfp_smoke_logged'] = array();
if ( ! function_exists( 'wc_get_logger' ) ) {
	function wc_get_logger() {
		return new class() {
			public function log( $level, $message, $context = array() ) {
				$GLOBALS['wfp_smoke_logged'][] = $message;
			}
		};
	}
}

// Load the plugin's namespaced classes via the Composer autoloader.
require_once dirname( __DIR__, 4 ) . '/vendor/autoload.php';

// init() wires a controller into the static FraudProtectionController::log() facade
// when the DI container creates it; in production that happens at boot. There is no
// container here, so point the facade at a real controller directly (the same way
// the unit tests do).
Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionControllerForTests::set_facade_target(
	new Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController()
);

// Deliberately not init()'d: a null context must be skipped before report() ever
// reaches the tracker, so no collaborator is required.
$reporter = new Automattic\WooCommerce\FraudProtection\FraudProtectionReporter();
$reporter->report( new WC_Order(), 'test-source', null, 'smoke-test' );

$skip_logged = false;
foreach ( $GLOBALS['wfp_smoke_logged'] as $logged_message ) {
	if ( false !== strpos( (string) $logged_message, 'no reportable context' ) ) {
		$skip_logged = true;
		break;
	}
}

wfp_smoke_assert(
	$skip_logged,
	'report() with a null context must skip and log a warning without fatalling. Logged: ' . var_export( $GLOBALS['wfp_smoke_logged'], true )
);

echo "OK\n";
