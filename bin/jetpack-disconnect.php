<?php
/**
 * Disconnect this wp-env site from WordPress.com, and confirm WordPress.com agreed.
 *
 * Run it from the host:
 *
 *   npm run env -- run cli wp --user=1 eval-file \
 *     wp-content/plugins/<worktree>/bin/jetpack-disconnect.php
 *
 * No tunnel is needed. The standard disconnect path skips its remote request for a
 * local site, so this script checks WordPress.com's response before it clears local
 * state. A failure leaves the tokens in place for a retry.
 *
 * See the README section "Live service testing".
 *
 * @package WooCommerce\FraudProtection
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WP-CLI eval-file script, not a loaded plugin file.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

if ( 0 === get_current_user_id() ) {
	WP_CLI::error( 'Run this with --user=1.' );
}

if ( ! class_exists( Automattic\WooCommerce\Internal\Jetpack\JetpackConnection::class ) ) {
	WP_CLI::error( 'WooCommerce connection support is not available. Confirm that WooCommerce is active.' );
}

$wcfp_blog_id = Jetpack_Options::get_option( 'id' );

if ( ! is_numeric( $wcfp_blog_id ) || $wcfp_blog_id <= 0 ) {
	WP_CLI::error( 'This site has no WordPress.com blog id, so there is nothing to disconnect.' );
}

$wcfp_manager = Automattic\WooCommerce\Internal\Jetpack\JetpackConnection::get_manager();

// is_connected() is true only with both a blog id and a blog token; the token is
// what signs the deregister below.
if ( ! $wcfp_manager->is_connected() ) {
	WP_CLI::error(
		'This site holds blog id ' . $wcfp_blog_id . " but has no connection token, so it cannot sign a disconnect.\n" .
		'Blog ' . $wcfp_blog_id . " is probably still connected at WordPress.com.\n" .
		"Reconnect through the same tunnel URL with the exact force-hostname-takeover value printed by jetpack-connect.php,\n" .
		"then run this command again. Reconnecting can replace a later connection that uses the same hostname.\n" .
		"If you cannot reconnect, clear the local connection. This leaves the WordPress.com blog connected:\n" .
		'  npm run env -- run cli wp option patch delete jetpack_options id'
	);
}

WP_CLI::log( 'Asking WordPress.com to disconnect blog ' . $wcfp_blog_id . '...' );

/*
 * Send the deregister directly instead of via disconnect_site(), so the result is
 * checkable. Client::remote_request() has no offline-mode guard, so this works from
 * localhost with no tunnel open.
 *
 * WooCommerce does not initialize identity-crisis handling, so these arguments are
 * not expected in the documented setup. Outside offline mode, the standalone
 * Jetpack plugin can add local wp-env URLs. Strip them if present.
 */
$wcfp_strip_idc_args = static function ( $wcfp_url ) {
	return remove_query_arg( array( 'home', 'siteurl', 'idc', 'migrate_for_idc', 'multisite' ), $wcfp_url );
};

add_filter( 'jetpack_remote_request_url', $wcfp_strip_idc_args, PHP_INT_MAX );

$wcfp_xml          = new Jetpack_IXR_Client();
$wcfp_deregistered = $wcfp_xml->query( 'jetpack.deregister', get_current_user_id() );

remove_filter( 'jetpack_remote_request_url', $wcfp_strip_idc_args, PHP_INT_MAX );

if ( ! $wcfp_deregistered ) {
	$wcfp_error = $wcfp_xml->get_jetpack_error();

	WP_CLI::error(
		'WordPress.com did NOT disconnect the site: ' . $wcfp_error->get_error_code() . ': ' . $wcfp_error->get_error_message() . "\n" .
		'Blog ' . $wcfp_blog_id . " is still connected. Local tokens have been left in place, so you can retry.\n" .
		"If it keeps failing on the token, this site's token has probably been rotated by a registration elsewhere.\n" .
		'Reconnect at the same tunnel URL to get a working one, then run this again.'
	);
}

WP_CLI::log( 'WordPress.com confirmed the disconnect. Removing local state...' );

// Local teardown only - the WordPress.com side is already done above.
$wcfp_manager->disconnect_site( false, true );

// Remove the active ID so Fraud Protection returns to local-only behavior.
Jetpack_Options::delete_option( 'id' );

$wcfp_remaining = Jetpack_Options::get_option( 'id' );

if ( ! empty( $wcfp_remaining ) ) {
	WP_CLI::error( 'WordPress.com disconnected blog ' . $wcfp_blog_id . ', but the local blog id is still set to ' . $wcfp_remaining . '.' );
}

WP_CLI::success( 'Disconnected from WordPress.com blog ' . $wcfp_blog_id . ' and returned the environment to local-only mode.' );
