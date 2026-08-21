<?php
/**
 * Smoke scenario: Blackbox script requests handle excluded and configured contexts.
 *
 * request_scripts() is the single consumer-facing entry point for loading the
 * Blackbox scripts. In an excluded admin context it declines before touching
 * WordPress script APIs, Jetpack, or the logger. An already-configured init
 * script short-circuits to true without enqueueing anything again.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

// Scenario-controlled stubs; stubs/wp.php only defines functions that do not exist yet.
$GLOBALS['wfp_smoke_is_admin']  = true;
$GLOBALS['wfp_smoke_script_is'] = false;

/**
 * Scenario-controlled is_admin() stub.
 *
 * @return bool
 */
function is_admin() {
	return $GLOBALS['wfp_smoke_is_admin'];
}

/**
 * Scenario-controlled wp_script_is() stub.
 *
 * @param string $handle Script handle.
 * @param string $list   State to query.
 * @return bool
 */
function wp_script_is( $handle, $list = 'enqueued' ) {
	return $GLOBALS['wfp_smoke_script_is'];
}

require_once __DIR__ . '/../stubs/wp.php';

require_once dirname( __DIR__, 4 ) . '/vendor/autoload.php';

$session_manager = new \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionIdentityManager();
$handler         = new \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler();
$handler->init( $session_manager );

// An excluded admin request declines before reaching Jetpack or the logger.
wfp_smoke_assert(
	false === $handler->request_scripts(),
	'request_scripts() must decline in an excluded admin context.'
);

// With the init script already configured, the request short-circuits to true
// without consulting Jetpack or enqueueing anything again.
$GLOBALS['wfp_smoke_is_admin']  = false;
$GLOBALS['wfp_smoke_script_is'] = true;

wfp_smoke_assert(
	true === $handler->request_scripts(),
	'request_scripts() must report already-configured scripts as available.'
);

echo "OK\n";
