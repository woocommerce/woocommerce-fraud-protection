<?php
/**
 * Smoke scenario: direct loader request.
 *
 * The MU-plugin loader must stop before using WordPress-only symbols when it
 * is loaded directly without WordPress.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

// This smoke test writes the captured response before WordPress loads.
// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite

require_once dirname( __DIR__, 4 ) . '/woocommerce-fraud-protection-loader.php';

fwrite( STDERR, "The loader did not stop direct execution.\n" );
exit( 1 );
