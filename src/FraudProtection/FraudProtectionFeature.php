<?php
/**
 * FraudProtectionFeature class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Payment gateway feature names that declare a native Fraud Protection integration.
 *
 * A gateway declares a feature by adding its string to the `$supports` array of its
 * `WC_Payment_Gateway` subclass, the same way it declares `refunds` or `tokenization`.
 * Add the literal strings rather than these constants: the declaration must also work
 * when this plugin is not installed. Each declared feature switches off the matching
 * built-in compatibility layer for that gateway, so declare a feature only once the
 * gateway implements it.
 *
 * Stripe, WooPayments, PayPal Payments and Square register one gateway instance per
 * payment method. The plugin asks the instance for the current attempt first, then the
 * family's main gateway (`stripe`, `woocommerce_payments`, `ppcp-gateway`,
 * `square_credit_card`), so a declaration on the main gateway covers the whole family.
 * Compatibility code that runs before a gateway is selected, such as script loading and
 * PayPal's create-order requests, asks only the main gateway.
 *
 * @since 0.3.1
 */
final class FraudProtectionFeature {

	/**
	 * The gateway resolves its own payment data.
	 *
	 * The gateway hooks `woocommerce_fraud_protection_resolved_payment_data` and returns
	 * the payment type, instrument, transaction mode and merchant identifier for its own
	 * attempts. The built-in payment-data resolver for the gateway stands aside.
	 *
	 * @since 0.3.1
	 */
	public const PAYMENT_DATA = 'fraud_protection_payment_data';

	/**
	 * The gateway verifies its own purchase requests.
	 *
	 * For a gateway that places or authorizes a purchase through its own requests before
	 * or instead of the WooCommerce checkout or pay-for-order submission, such as PayPal's
	 * create-order and vault-order calls. The gateway loads the browser scripts on its
	 * product, cart, checkout and pay-for-order payment surfaces, sends the session ID with
	 * those requests, verifies them with `SessionVerifier`, blocks on a Block decision, and
	 * supplies the decision to the final checkout through
	 * `woocommerce_fraud_protection_skip_session_verify`. The built-in PayPal Payments
	 * request, script and decision-reuse layers stand aside for those purchase surfaces.
	 *
	 * PayPal Payments creates setup tokens both at checkout, for example for a free trial,
	 * and when a payment method is saved, and the request does not reliably tell which.
	 * Its setup-token layer therefore stands aside only when the gateway declares both this
	 * feature and {@see self::ADD_PAYMENT_METHOD}, and PayPal Payments must declare the two
	 * together: with only one, setup-token requests on that feature's surfaces are still
	 * verified by the plugin, without the browser session.
	 *
	 * @since 0.3.1
	 */
	public const CHECKOUT = 'fraud_protection_checkout';

	/**
	 * The gateway verifies its own requests that save a payment method outside a purchase.
	 *
	 * For a gateway that checks or saves a payment method through its own requests before
	 * or instead of the form submission on My Account's "Add payment method" page or
	 * WooCommerce Subscriptions' change-payment-method form, such as Stripe's setup-intent
	 * and PayPal's setup-token calls. The gateway loads the browser scripts on those forms,
	 * sends the session ID with those requests, verifies them with `SessionVerifier`, and
	 * blocks on a Block decision with the `MessageContext::Generic` message. When the
	 * "Add payment method" form submission follows, the gateway supplies the decision to it
	 * through `woocommerce_fraud_protection_skip_session_verify`. The built-in Stripe
	 * setup-intent layer and the PayPal Payments script layer stand aside on those forms.
	 *
	 * @since 0.3.1
	 */
	public const ADD_PAYMENT_METHOD = 'fraud_protection_add_payment_method';

	/**
	 * The gateway sends the session ID with its express checkout orders.
	 *
	 * The gateway's express payment buttons (Apple Pay, Google Pay and similar) add the
	 * session ID to the Store API checkout request under the `woocommerce/fraud-protection`
	 * extension namespace. The built-in express checkout script for the gateway stands aside.
	 *
	 * @since 0.3.1
	 */
	public const EXPRESS_CHECKOUT = 'fraud_protection_express_checkout';

	/**
	 * Every feature a gateway can declare.
	 *
	 * @since 0.3.1
	 */
	public const ALL = array( self::PAYMENT_DATA, self::CHECKOUT, self::ADD_PAYMENT_METHOD, self::EXPRESS_CHECKOUT );
}
