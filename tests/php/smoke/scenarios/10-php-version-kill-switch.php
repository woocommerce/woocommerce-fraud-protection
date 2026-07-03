<?php
/**
 * Smoke scenario: unsupported-PHP kill switch.
 *
 * The main plugin file must stay parseable on the PHP versions the plugin no
 * longer supports (< 8.1) and, on those runtimes, bail immediately via its
 * version kill switch: register no hook, load no bootstrap/component class, and
 * — unlike the runtime version guards in PluginInitializer — write nothing to
 * the error log. On supported PHP (>= 8.1, the declared minimum) the same file
 * must NOT be killed and must queue the bootstrap as usual.
 *
 * The scenario is version-aware so it runs on both sides of the boundary: the
 * regular smoke suite (PHP 8.1) exercises the proceed path, while the dedicated
 * `kill-switch` CI job runs it on PHP 7.4 and 8.0 to exercise the bail path.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

$error_log_path = wfp_smoke_capture_errors();

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php';

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	// Unsupported PHP: the kill switch must fire and bail silently.
	wfp_smoke_assert(
		! isset( $GLOBALS['wfp_smoke_hooks']['woocommerce_loaded'] ),
		'On PHP < 8.1 the kill switch must bail before registering any hook.'
	);

	wfp_smoke_assert(
		! class_exists( 'Automattic\WooCommerce\Internal\FraudProtectionPlugin\PluginInitializer', false ),
		'On PHP < 8.1 the kill switch must bail before loading any plugin class.'
	);

	$logs = file_get_contents( $error_log_path );
	wfp_smoke_assert(
		is_string( $logs ) && '' === trim( $logs ),
		'On PHP < 8.1 the kill switch must bail without logging anything. Got: ' . var_export( $logs, true )
	);
} else {
	// Supported PHP (>= 8.1, the declared minimum): the plugin must NOT be killed.
	wfp_smoke_assert(
		isset( $GLOBALS['wfp_smoke_hooks']['woocommerce_loaded'] ),
		'On PHP >= 8.1 the kill switch must not fire and the bootstrap must be queued.'
	);
}

echo "OK\n";
