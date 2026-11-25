# Example integrations

Two barebones plugins that show what a native Fraud Protection integration looks like from the other side. They complement the [integration guide](../docs/integration-guide.md). They are reference code, not shipped with the plugin, and they are exercised by the PHPUnit tests under `tests/php/examples/` and the Jest tests `tests/js/example-gateway-*.test.js`.

## fraud-protection-example-gateway

A card gateway (`fp_example`) that integrates natively, on both the classic and the block cart and checkout. Its cart page shows two buttons, one for each kind of request that a gateway can send outside the checkout form: under the classic cart's checkout button, and in the express payment area of the Cart and Checkout blocks. It shows:

- **Declaring the integration**: the gateway lists `fraud_protection_payment_data`, `fraud_protection_checkout`, and `fraud_protection_express_checkout` in its `$supports` array (`includes/class-wc-gateway-fp-example.php`). Each string tells the plugin that the gateway implements that integration, and `wp wc fraud-protection native-integrations list` lists them. The loader in `includes/class-fp-example-gateway-plugin.php` registers the gateway and the integration on `plugins_loaded`.
- **Payment data**: a resolver registered with `GatewayPaymentData::register_resolver()` that builds a `PaymentMethodData` for the gateway's own attempts (`includes/class-fp-example-fraud-protection.php`). For a saved card, it keeps the data that the card's token resolved and adds only what the token doesn't hold.
- **A checkout request**: the "Pay with provider" button sends an AJAX request that creates an order at the payment provider, as PayPal Payments' create-order request does, and then opens the checkout that completes it. The button script (`assets/provider-checkout.js`) adds a session ID that it takes with `window.wcFraudProtection.takeSessionId()` when the shopper clicks. The gateway verifies the request with `GatewayRequestVerifier::verify_request()` and blocks it on a Block decision. It keeps the decision for reuse with the provider order's ID, which the checkout form posts back, and `register_reference_reader()` tells the plugin where to find it, so the checkout with the same cart items reuses the decision instead of being verified again. The decision is matched by the provider order's ID, not the session ID, because the browser starts a new session after the request.
- **The Cart and Checkout blocks**: the gateway registers a block payment method (`includes/class-fp-example-blocks-support.php` and `assets/blocks.js`) with the same card fields. It needs no Fraud Protection code: the plugin adds the session ID to the Checkout block's Store API request and verifies it by itself, and the card fields reach the payment data resolver as the request's payment data. When the shopper comes from the "Pay with provider" button, the block payment method posts the provider order's ID with the payment data, so the Checkout block submission reuses the decision as the classic checkout does.
- **An express checkout**: the "Express pay" button places the order with a Store API checkout request, without the checkout form, and the plugin verifies it as it verifies any Store API checkout. The button script (`assets/express.js`) builds the request's extension data through a synchronous `wp.hooks` filter, as WooPayments and Stripe do for their express buttons, and the integration adds the session ID there with `window.wcFraudProtection.addSessionIdToExtensions()` after calling `prepareSessionId()` once. The button stands in for a wallet's payment sheet, so it sends a fixed address in San Francisco.

## fraud-protection-example-subscriptions

A stand-in for the WooCommerce Subscriptions change-payment-method flow: an extension that handles its own pay form on `wp` and fires `woocommerce_subscription_change_payment_method_via_pay_shortcode` like Subscriptions does. It shows:

- **Protecting a classic form**: `BlackboxScriptHandler::request_pay_for_order_script()` loads the script that adds the session ID to the `#order_review` form on submit.
- **Verifying the submission**: `ClassicFormRequestData` reads the session ID and builds the request data from the POST body; `SessionVerifier::verify_session()` decides, and a Block stops the change with the standard notice.
- **Declaring native support**: registering `change_payment_method` under the extension's plugin slug from the `woocommerce_fraud_protection_register_native_integrations` action tells the plugin that the extension protects the flow itself. The stand-in declares under `woocommerce-subscriptions`, the slug of the plugin it plays.

The form renders through the `[fp_example_change_payment]` shortcode for the current user's latest order.

A gateway on this form needs no code specific to it. The verification uses the gateway the shopper selects and the fields it posts, so the gateway's [payment data](../docs/integration-guide.md#payment-data) resolver runs as it does at checkout. A gateway that saves the payment method through its own request before the form is submitted follows [Add payment method requests](../docs/integration-guide.md#add-payment-method-requests).

## Trying them in the local store

Add the examples to a gitignored `.wp-env.override.json` next to the repository's `.wp-env.json`. Its `plugins` list replaces the one in `.wp-env.json`, so repeat those entries:

```json
{
	"plugins": [
		"https://downloads.wordpress.org/plugin/woocommerce.zip",
		"https://github.com/woocommerce/woocommerce-gateway-dummy/releases/latest/download/woocommerce-gateway-dummy.zip",
		"https://downloads.wordpress.org/plugin/query-monitor.zip",
		".",
		"./examples/fraud-protection-example-gateway",
		"./examples/fraud-protection-example-subscriptions"
	]
}
```

Then run `npm run env:start`, enable the example gateway under WooCommerce > Settings > Payments, and check that `wp wc fraud-protection native-integrations list` lists both examples. The seeded store uses the block cart and checkout, where the example gateway and its two buttons appear as they are. To try the classic flows, set pages with the `[woocommerce_cart]` and `[woocommerce_checkout]` shortcodes as the cart and checkout pages. Without a Jetpack connection the plugin loads no browser scripts and can't reach Blackbox, so requests carry no session ID and verification fails open; only merchant rules can block them.
