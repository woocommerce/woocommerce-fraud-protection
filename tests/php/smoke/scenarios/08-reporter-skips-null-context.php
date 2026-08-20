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

// Load the plugin's namespaced classes via the Composer autoloader.
require_once dirname( __DIR__, 4 ) . '/vendor/autoload.php';

$logging_spy = new Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionControllerForTests();
Automattic\WooCommerce\FraudProtection\Tests\Support\FraudProtectionControllerForTests::set_facade_target(
	$logging_spy
);

// Deliberately not init()'d: a null context must be skipped before report() ever
// reaches the tracker, so no collaborator is required.
$reporter = new Automattic\WooCommerce\FraudProtection\FraudProtectionReporter();
$reporter->report( new WC_Order(), Automattic\WooCommerce\FraudProtection\Schemas\ReportSource::Api, 'smoke-report-id', null, null, 'smoke-test' );

$skip_logged = false;
foreach ( $logging_spy->entries as $entry ) {
	if ( false !== strpos( $entry['message'], 'no reportable context' ) ) {
		$skip_logged = true;
		break;
	}
}

wfp_smoke_assert(
	$skip_logged,
	'report() with a null context must skip and log a warning without fatalling. Logged: ' . var_export( $logging_spy->entries, true )
);

echo "OK\n";
