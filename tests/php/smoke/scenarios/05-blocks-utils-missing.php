<?php
/**
 * Smoke scenario: WC_Blocks_Utils class absent.
 *
 * On older WC versions or partial blocks loads, WC_Blocks_Utils may not exist.
 * BlackboxScriptHandler::maybe_enqueue_scripts() must treat the missing class
 * as "no checkout block found" instead of fatalling.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

require_once dirname( __DIR__, 4 ) . '/vendor/autoload.php';

// Intentionally do NOT define WC_Blocks_Utils. Confirm:
wfp_smoke_assert(
	! class_exists( 'WC_Blocks_Utils' ),
	'Pre-condition: WC_Blocks_Utils must NOT be defined.'
);

global $post, $wp;
$post = null;
$wp   = (object) array( 'query_vars' => array() );

$session_manager = new \Automattic\WooCommerce\FraudProtection\SessionClearanceManager();
$handler         = new \Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler();
$handler->init( $session_manager );

// Pre-fix this fatals on \WC_Blocks_Utils::has_block_in_page().
$handler->maybe_enqueue_scripts();

echo "OK\n";
