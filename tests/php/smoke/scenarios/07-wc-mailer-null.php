<?php
/**
 * Smoke scenario: WC()->mailer() returning null / non-WC_Emails.
 *
 * BlockedSessionNotice support-email lookup must not fatal when the WC
 * mailer is unavailable. The get_support_email() helper falls back through
 * the WC mailer's "from" address to admin_email.
 *
 * @package WooCommerce\FraudProtection\Tests\Smoke
 */

declare( strict_types = 1 );

require_once __DIR__ . '/../stubs/wp.php';

require_once dirname( __DIR__, 4 ) . '/vendor/autoload.php';

update_option( 'admin_email', 'admin@example.test' );

// Stub the WooCommerce singleton class so $wc instanceof \WooCommerce passes.
if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public function mailer() {
			return null;
		}
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC() {
		return new \WooCommerce();
	}
}

$session_manager = new \Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionClearanceManager();

$notice = new \Automattic\WooCommerce\Internal\FraudProtectionPlugin\BlockedSessionNotice();
$notice->init( $session_manager );

// Pre-fix this fatals on WC()->mailer()->get_from_address().
$html      = $notice->get_message_html( \Automattic\WooCommerce\Internal\FraudProtectionPlugin\MessageContext::Purchase );
$plaintext = $notice->get_message_plaintext();

wfp_smoke_assert(
	is_string( $html ) && false !== strpos( $html, 'admin@example.test' ),
	'HTML message must fall back to admin_email when mailer is null. Got: ' . var_export( $html, true )
);

wfp_smoke_assert(
	is_string( $plaintext ) && false !== strpos( $plaintext, 'admin@example.test' ),
	'Plaintext message must fall back to admin_email when mailer is null. Got: ' . var_export( $plaintext, true )
);

echo "OK\n";
