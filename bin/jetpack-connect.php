<?php
/**
 * Register this wp-env site with WordPress.com, once, through a public tunnel.
 *
 * Run it from the host, with the tunnel open:
 *
 *   npm run env -- run cli wp --user=1 eval-file \
 *     wp-content/plugins/<worktree>/bin/jetpack-connect.php \
 *     https://<tunnel-hostname> confirm-unused-hostname
 *
 * To reuse a hostname after its previous environment is disconnected or no longer
 * needed, pass force-hostname-takeover=<blog-id> instead.
 *
 * See the README section "Live service testing".
 *
 * @package WooCommerce\FraudProtection
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- A WP-CLI eval-file script, not a loaded plugin file.

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	exit( 1 );
}

$wcfp_tunnel_url       = isset( $args[0] ) ? trim( $args[0] ) : '';
$wcfp_confirmation     = isset( $args[1] ) ? trim( $args[1] ) : '';
$wcfp_confirmed_unused = 'confirm-unused-hostname' === $wcfp_confirmation;
$wcfp_takeover_match   = array();
$wcfp_force_blog_id    = preg_match( '/^force-hostname-takeover=([1-9][0-9]*)$/', $wcfp_confirmation, $wcfp_takeover_match )
	? (int) $wcfp_takeover_match[1]
	: 0;

if ( '' === $wcfp_tunnel_url ) {
	WP_CLI::error( 'Pass the public tunnel URL as the first argument, e.g. https://<tunnel-hostname>' );
}

$wcfp_tunnel_url = untrailingslashit( $wcfp_tunnel_url );
$wcfp_url_parts  = wp_parse_url( $wcfp_tunnel_url );
$wcfp_scheme     = is_array( $wcfp_url_parts ) && isset( $wcfp_url_parts['scheme'] ) ? strtolower( $wcfp_url_parts['scheme'] ) : '';
$wcfp_host       = is_array( $wcfp_url_parts ) && isset( $wcfp_url_parts['host'] ) ? strtolower( rtrim( $wcfp_url_parts['host'], '.' ) ) : '';

if (
	! is_array( $wcfp_url_parts ) ||
	'https' !== $wcfp_scheme ||
	empty( $wcfp_host ) ||
	false === strpos( $wcfp_host, '.' ) ||
	isset( $wcfp_url_parts['user'] ) ||
	isset( $wcfp_url_parts['pass'] ) ||
	isset( $wcfp_url_parts['port'] ) ||
	isset( $wcfp_url_parts['path'] ) ||
	isset( $wcfp_url_parts['query'] ) ||
	isset( $wcfp_url_parts['fragment'] )
) {
	WP_CLI::error( 'The tunnel URL must be an HTTPS origin with no path, query, fragment, credentials, or port: ' . $wcfp_tunnel_url );
}

// Use one canonical origin for the hostname check, tunnel probe, and registration.
$wcfp_tunnel_url = $wcfp_scheme . '://' . $wcfp_host;

if ( '' !== $wcfp_confirmation && ! $wcfp_confirmed_unused && 0 === $wcfp_force_blog_id ) {
	WP_CLI::error( 'The second argument must be confirm-unused-hostname or force-hostname-takeover=<blog-id>.' );
}

// Without a current user WordPress.com rejects the handshake with `state_missing`.
if ( 0 === get_current_user_id() ) {
	WP_CLI::error( 'Run this with --user=1. Without a current user the handshake fails with "state_missing".' );
}

if ( ! class_exists( Automattic\WooCommerce\Internal\Jetpack\JetpackConnection::class ) ) {
	WP_CLI::error( 'WooCommerce connection support is not available. Confirm that WooCommerce is active.' );
}

$wcfp_existing_blog_id = Jetpack_Options::get_option( 'id' );
$wcfp_existing_blog_id = ( is_numeric( $wcfp_existing_blog_id ) && $wcfp_existing_blog_id > 0 ) ? (int) $wcfp_existing_blog_id : 0;
$wcfp_manager          = Automattic\WooCommerce\Internal\Jetpack\JetpackConnection::get_manager();

/*
 * Ask WordPress.com whether it already knows a site at this hostname. This is a
 * read-only, unauthenticated request. A takeover is allowed only when it returns
 * the blog ID supplied in the force argument.
 *
 * $wcfp_host_blog_id is the blog WordPress.com reports at this hostname, or null
 * when it reports none.
 */
$wcfp_host_blog_id = null;

$wcfp_probe = wp_remote_get(
	add_query_arg(
		array(
			'force'  => 'wpcom',
			'fields' => 'ID,URL',
		),
		'https://public-api.wordpress.com/rest/v1.1/sites/' . rawurlencode( $wcfp_host )
	),
	array( 'timeout' => 10 )
);

if ( is_wp_error( $wcfp_probe ) ) {
	WP_CLI::error( 'Could not check WordPress.com for an existing site at ' . $wcfp_host . ': ' . $wcfp_probe->get_error_message() );
} else {
	$wcfp_probe_code = wp_remote_retrieve_response_code( $wcfp_probe );
	$wcfp_probe_body = json_decode( wp_remote_retrieve_body( $wcfp_probe ), true );
	$wcfp_probe_id   = is_array( $wcfp_probe_body ) && is_numeric( $wcfp_probe_body['ID'] ?? null )
		? (int) $wcfp_probe_body['ID']
		: 0;
	$wcfp_probe_url  = is_array( $wcfp_probe_body ) && is_string( $wcfp_probe_body['URL'] ?? null )
		? $wcfp_probe_body['URL']
		: '';
	$wcfp_probe_host = '' !== $wcfp_probe_url ? wp_parse_url( $wcfp_probe_url, PHP_URL_HOST ) : '';
	$wcfp_probe_host = is_string( $wcfp_probe_host ) ? strtolower( rtrim( $wcfp_probe_host, '.' ) ) : '';

	if ( 200 === $wcfp_probe_code && 0 >= $wcfp_probe_id ) {
		WP_CLI::error( 'WordPress.com returned HTTP 200 for ' . $wcfp_host . ' without a valid blog id.' );
	} elseif ( 200 === $wcfp_probe_code && '' === $wcfp_probe_host ) {
		WP_CLI::error( 'WordPress.com returned HTTP 200 for ' . $wcfp_host . ' without a valid URL hostname.' );
	} elseif ( 200 === $wcfp_probe_code && $wcfp_host !== $wcfp_probe_host ) {
		WP_CLI::error( 'WordPress.com returned HTTP 200 for ' . $wcfp_host . ', but the response URL belongs to ' . $wcfp_probe_host . '.' );
	} elseif ( 200 === $wcfp_probe_code ) {
		$wcfp_host_blog_id = $wcfp_probe_id;
		WP_CLI::log( 'WordPress.com reports blog ' . $wcfp_host_blog_id . ' at ' . $wcfp_host . '.' );
	} elseif ( 404 === $wcfp_probe_code ) {
		WP_CLI::log( 'WordPress.com reports no existing site at ' . $wcfp_host . '.' );
	} else {
		WP_CLI::error( 'Could not confirm whether WordPress.com has a site at ' . $wcfp_host . ' (HTTP ' . $wcfp_probe_code . ').' );
	}
}

/*
 * A local blog ID can identify the expected WordPress.com blog. It cannot show
 * whether another environment has taken over the connection since.
 */
$wcfp_matches_existing_blog = ( 0 !== $wcfp_existing_blog_id && $wcfp_existing_blog_id === $wcfp_host_blog_id );

/*
 * Refuse to register over a different blog ID already held by this site. A failed
 * registration can remove the token needed to disconnect the current blog.
 */
if ( 0 !== $wcfp_existing_blog_id && ! $wcfp_matches_existing_blog ) {
	WP_CLI::error(
		'This site already holds WordPress.com blog ' . $wcfp_existing_blog_id . ', but WordPress.com does not report that blog at ' . $wcfp_host . ".\n" .
		"Disconnect first: bin/jetpack-disconnect.php\n" .
		"If this site has no usable token, clear its local connection before you start over:\n" .
		"  npm run env -- run cli wp option patch delete jetpack_options id\n" .
		'This command does not disconnect blog ' . $wcfp_existing_blog_id . ' from WordPress.com.'
	);
}

if ( null !== $wcfp_host_blog_id && 0 === $wcfp_force_blog_id ) {
	if ( $wcfp_matches_existing_blog ) {
		WP_CLI::error(
			'This environment has a local reference to WordPress.com blog ' . $wcfp_host_blog_id . ' at ' . $wcfp_host . ".\n" .
			"The public check cannot tell whether another environment now uses its connection.\n" .
			"If the current connection works, no action is needed.\n" .
			'If its token was replaced, re-run with force-hostname-takeover=' . $wcfp_host_blog_id . '.'
		);
	}

	WP_CLI::error(
		'WordPress.com already has a site at ' . $wcfp_host . ' (blog ' . $wcfp_host_blog_id . '). ' .
		"The public check cannot tell whether another environment still uses its connection.\n" .
		'If that connection can be replaced, re-run with force-hostname-takeover=' . $wcfp_host_blog_id . '.'
	);
}

if ( 0 !== $wcfp_force_blog_id && null === $wcfp_host_blog_id ) {
	WP_CLI::error( 'WordPress.com reports no existing site at this hostname. Use confirm-unused-hostname instead.' );
}

if ( 0 !== $wcfp_force_blog_id && $wcfp_force_blog_id !== $wcfp_host_blog_id ) {
	WP_CLI::error(
		'The requested takeover blog id (' . $wcfp_force_blog_id . ') does not match the blog WordPress.com reports at this hostname (' . $wcfp_host_blog_id . ').'
	);
}

if ( 0 !== $wcfp_force_blog_id ) {
	WP_CLI::warning(
		'Forcing WordPress.com blog ' . $wcfp_force_blog_id . ' at ' . $wcfp_host . ' to connect to this environment. ' .
		'This can replace the connection used by a previous environment at this hostname. ' .
		'That environment then fails open, so a live test can appear to pass without a live verdict.'
	);
} elseif ( ! $wcfp_confirmed_unused ) {
	WP_CLI::error(
		"Refusing to register without confirmation.\n" .
		'About to claim ' . $wcfp_tunnel_url . " as this site's WordPress.com identity.\n" .
		"If another site has connected through that hostname, this can break its connection.\n" .
		'Re-run with confirm-unused-hostname as the second argument once you are sure.'
	);
}

$wcfp_tunnel_probe = wp_remote_get(
	$wcfp_tunnel_url . '/xmlrpc.php',
	array(
		'redirection' => 0,
		'timeout'     => 10,
	)
);

if ( is_wp_error( $wcfp_tunnel_probe ) ) {
	WP_CLI::error( 'Could not reach the tunnel XML-RPC endpoint: ' . $wcfp_tunnel_probe->get_error_message() );
}

$wcfp_tunnel_probe_code = wp_remote_retrieve_response_code( $wcfp_tunnel_probe );

if ( 405 !== $wcfp_tunnel_probe_code ) {
	WP_CLI::error( 'The tunnel XML-RPC endpoint returned HTTP ' . $wcfp_tunnel_probe_code . '; expected 405. Check the tunnel before registering.' );
}

/*
 * wp-env hardcodes WP_SITEURL and WP_HOME in wp-config.php, and Jetpack reads
 * those constants ahead of the options (Connection\Urls::get_raw_url()), so
 * `wp option update siteurl` has no effect and the .wp-env.json `config` block
 * cannot express the public tunnel URL. These filters are applied last, when
 * Manager::register() builds the URLs it sends, and they live for this one CLI
 * call only. The site URL itself is never written to the database.
 */
$wcfp_report_tunnel_url = static function () use ( $wcfp_tunnel_url ) {
	return $wcfp_tunnel_url;
};

add_filter( 'jetpack_sync_site_url', $wcfp_report_tunnel_url, 20 );
add_filter( 'jetpack_sync_home_url', $wcfp_report_tunnel_url, 20 );
add_filter( 'option_siteurl', $wcfp_report_tunnel_url, 20 );
add_filter( 'option_home', $wcfp_report_tunnel_url, 20 );

WP_CLI::log( 'Registering ' . $wcfp_tunnel_url . ' with WordPress.com...' );

$wcfp_registration = $wcfp_manager->try_registration();

if ( is_wp_error( $wcfp_registration ) ) {
	WP_CLI::error( $wcfp_registration->get_error_code() . ': ' . $wcfp_registration->get_error_message() );
}

$wcfp_blog_id = Jetpack_Options::get_option( 'id' );

if ( ! is_numeric( $wcfp_blog_id ) || $wcfp_blog_id <= 0 ) {
	WP_CLI::error( 'Registration reported success but no blog id was stored.' );
}

if ( 0 !== $wcfp_force_blog_id && $wcfp_force_blog_id !== (int) $wcfp_blog_id ) {
	WP_CLI::error(
		'WordPress.com reported blog ' . $wcfp_force_blog_id . ' at this hostname before registration, but connected blog ' . $wcfp_blog_id . ".\n" .
		'Blog ' . $wcfp_blog_id . ' is already connected and its token remains active locally. The script could not confirm that blog ' . $wcfp_force_blog_id . " was replaced.\n" .
		'Close the tunnel now. Do not continue testing until you decide whether to keep blog ' . $wcfp_blog_id . '. To discard it, run bin/jetpack-disconnect.php.'
	);
}

WP_CLI::success( 'Registered as WordPress.com blog ' . $wcfp_blog_id . '. The tunnel is no longer needed - close it now.' );
