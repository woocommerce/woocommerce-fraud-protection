<?php
/**
 * KustomScriptCompat class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Compat;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Protectors\ShortcodeCheckoutProtector;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the classic checkout script on Kustom's embedded checkout.
 *
 * Kustom's template replaces form-checkout.php and fires neither of the actions
 * that ShortcodeCheckoutProtector uses to enqueue its script. Kustom still places
 * the order through the classic checkout request, which that script completes
 * with the session ID.
 */
class KustomScriptCompat {

	/**
	 * Classic checkout protector, which owns the script.
	 *
	 * @var ShortcodeCheckoutProtector
	 */
	private ShortcodeCheckoutProtector $shortcode_checkout_protector;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param ShortcodeCheckoutProtector $shortcode_checkout_protector The classic checkout protector.
	 */
	final public function init( ShortcodeCheckoutProtector $shortcode_checkout_protector ): void {
		$this->shortcode_checkout_protector = $shortcode_checkout_protector;
	}

	/**
	 * Register the Kustom checkout template hook.
	 *
	 * Kustom adds the checkout form fields on this action, so a theme that
	 * overrides the template has to keep it.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'kco_wc_before_snippet', array( $this->shortcode_checkout_protector, 'enqueue_shortcode_checkout_script' ), 10, 0 );
	}
}
