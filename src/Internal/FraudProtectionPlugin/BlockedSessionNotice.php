<?php
/**
 * BlockedSessionNotice class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions\SessionClearanceManager;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the WordPress hooks that surface the blocked-session notice on
 * store pages and the add-payment-method page.
 *
 * The message text itself is produced by the public {@see BlockedSessionMessage},
 * which this class wires into WooCommerce notices.
 *
 * Note: Store API (block checkout) and payment gateway filtering are handled
 * directly in WC Core classes (Checkout.php and WC_Payment_Gateways).
 */
class BlockedSessionNotice /* implements RegisterHooksInterface */ {

	/**
	 * Session clearance manager instance.
	 *
	 * @var SessionClearanceManager
	 */
	private SessionClearanceManager $session_manager;

	/**
	 * Blocked-session message generator.
	 *
	 * @var BlockedSessionMessage
	 */
	private BlockedSessionMessage $message;

	/**
	 * Initialize with dependencies.
	 *
	 * @internal
	 *
	 * @param SessionClearanceManager $session_manager The session clearance manager instance.
	 * @param BlockedSessionMessage   $message         The blocked-session message generator.
	 */
	final public function init( SessionClearanceManager $session_manager, BlockedSessionMessage $message ): void {
		$this->session_manager = $session_manager;
		$this->message         = $message;
	}

	/**
	 * Register hooks for displaying blocked notice.
	 *
	 * This method should only be called when fraud protection is enabled.
	 *
	 * @return void
	 */
	public function register(): void {
		// Shop, cart, and checkout pages (both blocks and shortcode) - add notice via wc_add_notice on wp hook.
		add_action( 'wp', array( $this, 'maybe_add_blocked_purchase_notice' ), 10, 0 );

		add_action( 'before_woocommerce_add_payment_method', array( $this, 'maybe_display_generic_blocked_notice' ), 1, 0 );
	}

	/**
	 * Add blocked purchase notice on shop, cart, and checkout pages (both blocks and shortcode),
	 * if the session is blocked. Skips duplicate notices.
	 *
	 * Uses wc_add_notice() to add an error notice that will be rendered by:
	 * - StoreNoticesContainer component for blocks
	 * - wc_print_notices() for shortcodes
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function maybe_add_blocked_purchase_notice(): void {
		if ( ! $this->session_manager->is_session_blocked() ) {
			return;
		}

		if ( ! is_checkout() && ! is_cart() && ! is_shop() && ! is_product_taxonomy() ) {
			return;
		}

		$message = $this->message->get_html( MessageContext::Purchase );

		if ( wc_has_notice( $message, 'error' ) ) {
			return;
		}

		wc_add_notice( $message, 'error' );
	}

	/**
	 * Display blocked notice for non-cart/checkout pages, if the session is blocked.
	 *
	 * Shows a generic message explaining that the request cannot be
	 * processed online and provides contact information for support.
	 *
	 * @internal
	 *
	 * @return void
	 */
	public function maybe_display_generic_blocked_notice(): void {
		if ( ! $this->session_manager->is_session_blocked() ) {
			return;
		}

		$message = $this->message->get_html();
		if ( ! wc_has_notice( $message, 'error' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}
}
