<?php
/**
 * FraudProtectionIntegration class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\FraudProtection;

defined( 'ABSPATH' ) || exit;

/**
 * Integrations that an extension other than a payment gateway can declare.
 *
 * An extension that integrates natively with one of these flows declares it under its own
 * plugin slug, with {@see NativeIntegrationRegistry::register()} from the
 * `woocommerce_fraud_protection_register_native_integrations` action:
 *
 *     $registry->register( 'my-extension', 'change_payment_method' );
 *
 * When this plugin ships a built-in compatibility layer for that extension's flow, the
 * layer stands aside. Each compatibility layer documents the slug and integration it
 * checks. Declare an integration only once the extension implements it, and only under
 * the extension's own slug.
 *
 * Payment gateways declare their integration through `WC_Payment_Gateway::supports()`
 * instead; see {@see FraudProtectionFeature}.
 *
 * @since 0.3.2
 */
final class FraudProtectionIntegration {

	/**
	 * The extension verifies its own change-payment-method form.
	 *
	 * The extension loads the browser scripts on the form, for example with
	 * `BlackboxScriptHandler::request_pay_for_order_script()`, verifies each submission with
	 * `SessionVerifier` before the payment method changes, and stops the change on a Block
	 * decision.
	 *
	 * @since 0.3.2
	 */
	public const CHANGE_PAYMENT_METHOD = 'change_payment_method';

	/**
	 * Every integration an extension can declare.
	 *
	 * @since 0.3.2
	 */
	public const ALL = array( self::CHANGE_PAYMENT_METHOD );
}
