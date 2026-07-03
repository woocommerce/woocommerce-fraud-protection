<?php
/**
 * MessageContext enum file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin;

defined( 'ABSPATH' ) || exit;

/**
 * Context in which a blocked-session message is shown.
 *
 * Used by `BlockedSessionNotice::get_message_html()` and
 * `get_message_plaintext()` to select the wording of the blocked-session message.
 */
enum MessageContext: string {

	/** Checkout/cart blocking; wording invites the shopper to complete their purchase. */
	case Purchase = 'purchase';

	/** Non-purchase flows (e.g. adding or changing a payment method); generic wording. */
	case Generic = 'generic';
}
