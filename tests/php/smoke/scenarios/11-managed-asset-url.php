<?php
/**
 * Smoke scenario: managed installations use their configured asset URL.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

// These helpers must be defined before the shared stub loads so this scenario
// can model the WordPress URL helpers used by the loader and initializer.
/**
 * Convert a URL to the current request scheme.
 *
 * @param string      $url    URL to convert.
 * @param string|null $scheme Scheme to use, or null for the request scheme.
 * @return string Converted URL.
 */
function set_url_scheme( $url, $scheme = null ) {
	if ( null === $scheme ) {
		$scheme = isset( $_SERVER['HTTPS'] ) && 'on' === $_SERVER['HTTPS'] ? 'https' : 'http';
	}

	return preg_replace( '#^https?://#', $scheme . '://', $url );
}

/**
 * Add one trailing slash to a path.
 *
 * @param string $value Path to normalize.
 * @return string Normalized path.
 */
function trailingslashit( $value ) {
	return rtrim( $value, '/' ) . '/';
}

/**
 * Build a normal plugin URL and apply the plugins_url filter.
 *
 * @param string $file Plugin file path.
 * @return string Plugin directory URL.
 */
function plugin_dir_url( $file ) {
	$url = 'https://example.test/wp-content/plugins/' . basename( dirname( $file ) ) . '/';

	return trailingslashit( apply_filters( 'plugins_url', $url, '', $file ) );
}

require_once __DIR__ . '/../stubs/wp.php';

$_SERVER['HTTPS'] = 'on';
define( 'WPMU_PLUGIN_URL', 'http://public.example.test/custom-mu///' );

add_filter(
	'plugins_url',
	static function ( $url ) {
		unset( $url );
		return 'https://hostile.example.test/';
	}
);

$managed_root = sys_get_temp_dir() . '/wfp-smoke-managed-' . uniqid();
$managed_dir  = $managed_root . '/woocommerce-fraud-protection';
$target       = $managed_dir . '/woocommerce-fraud-protection.php';
mkdir( $managed_dir, 0777, true );
symlink( dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection.php', $target );
define( 'WPMU_PLUGIN_DIR', $managed_root );

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

wfp_smoke_assert(
	defined( 'WC_FRAUD_PROTECTION_PLUGIN_URL' ) && 'https://public.example.test/custom-mu/woocommerce-fraud-protection/' === WC_FRAUD_PROTECTION_PLUGIN_URL,
	'Managed installations must use the configured URL with the current request scheme. Got: ' . ( defined( 'WC_FRAUD_PROTECTION_PLUGIN_URL' ) ? WC_FRAUD_PROTECTION_PLUGIN_URL : 'undefined' )
);

unlink( $target );
rmdir( $managed_dir );
rmdir( $managed_root );

echo "OK\n";
