# Example integrations

Two barebones plugins that show what a native Fraud Protection integration looks like from the other side. They complement the [integration guide](../docs/integration-guide.md). They are reference code, not shipped with the plugin, and they are exercised by the PHPUnit tests under `tests/php/examples/` and the Jest test `tests/js/example-gateway-express.test.js`.

## fraud-protection-example-gateway

A card gateway (`fp_example`, classic cart and checkout only) that integrates natively. It shows:

- **Declaring the integration**: the gateway lists `fraud_protection_payment_data`, `fraud_protection_checkout`, and `fraud_protection_express_checkout` in its `$supports` array (`includes/class-wc-gateway-fp-example.php`). Each string switches off the matching built-in compatibility layer for the gateway, and `wp wc fraud-protection native-integrations list` lists them. The loader in `includes/class-fp-example-gateway-plugin.php` registers the gateway and the integration on `plugins_loaded`.
- **Payment data**: a `woocommerce_fraud_protection_resolved_payment_data` callback that builds a `PaymentMethodData` for its own attempts (`includes/class-fp-example-fraud-protection.php`).
- **A gateway-owned checkout request**: an "Express pay" button on the cart page sends an AJAX request that the gateway verifies with `SessionVerifier` and blocks on a Block decision. The request creates a payment reference, as PayPal Payments creates an order, and the checkout form posts it back. The decision is kept for the checkout that posts that reference, for the same cart, and supplied through `woocommerce_fraud_protection_skip_session_verify`, so the attempt is not scored twice. The decision is matched by the reference, not the session ID, because the browser starts a new session after the express request. This is the pattern PayPal Payments needs for its create-order request.
- **A synchronous session ID**: the button script (`assets/express.js`) builds its request data through a synchronous `wp.hooks` filter, as WooPayments and Stripe do for their express buttons, and the integration adds the session ID there with `window.wcFraudProtection.takePreparedSessionId()` after calling `prepareSessionId()` once.

## fraud-protection-example-subscriptions

A stand-in for the WooCommerce Subscriptions change-payment-method flow: an extension that handles its own pay form on `wp` and fires `woocommerce_subscription_change_payment_method_via_pay_shortcode` like Subscriptions does. It shows:

- **Protecting a classic form**: `BlackboxScriptHandler::request_pay_for_order_script()` loads the script that adds the session ID to the `#order_review` form on submit.
- **Verifying the submission**: `ClassicFormRequestData` reads the session ID and builds the request data from the POST body; `SessionVerifier::verify_session()` decides, and a Block stops the change with the standard notice.
- **Declaring native support**: declaring `change_payment_method` under the extension's plugin slug through the `woocommerce_fraud_protection_native_integrations` filter tells the plugin that the flow verifies itself, so the built-in compatibility layer does not verify the same request again. The stand-in declares under `woocommerce-subscriptions`, the slug of the plugin it plays.

The form renders through the `[fp_example_change_payment]` shortcode for the current user's latest order.

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

Then run `npm run env:start`, enable the example gateway under WooCommerce > Settings > Payments, and check that `wp wc fraud-protection native-integrations list` lists both examples. The seeded store uses the block cart and checkout: set pages with the `[woocommerce_cart]` and `[woocommerce_checkout]` shortcodes as the cart and checkout pages to see the example gateway and its "Express pay" button. Without a Jetpack connection the plugin loads no browser scripts, so requests carry no session ID and verification fails open.
