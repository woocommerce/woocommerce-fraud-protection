# Compatibility layers

This reference lists each class under `src/Internal/FraudProtectionPlugin/Compat/`: the extension it supports, the hooks it uses, the gateways it matches, and when it stands aside for a native integration. [Chapter 4 of the tutorial](../tutorial/04-gateway-specific-flows.md) explains why each one exists.

WooCommerce hook names link to the line where each hook runs in WooCommerce's code reference. The code reference is rebuilt for every WooCommerce release, so those lines move: if a link lands on unrelated code, look the hook up in the [WooCommerce hooks list](https://woocommerce.github.io/code-reference/hooks/hooks.html).

## Contents

- [Rules for every layer](#rules-for-every-layer)
- [Native support checks](#native-support-checks)
- [Verification sources](#verification-sources)
- [Layer reference](#layer-reference)
- [Adding or changing a layer](#adding-or-changing-a-layer)

## Rules for every layer

- **Stand aside for native integrations.** Ask `GatewayNativeSupport` or `ExtensionNativeSupport` inside each hook callback, never at registration time, and do nothing when the matching feature is declared.
- **Leave other gateways alone.** A filter callback returns its input unchanged when the gateway isn't one it handles.
- **Preserve on partial failure.** A payment-data resolver keeps every incoming field and adds only what it resolved.
- **Lean on the public API.** Layers are meant to move into the extensions they support, so prefer public classes over internal ones. Today's layers still use some internal pieces (`GatewayNativeSupport`, `FraudProtectionController::log()`, `ClassicFormDataExtractionTrait`). A new layer built on `GatewayPaymentData` or `GatewayRequestVerifier` should register through their internal `register_compatibility_resolver()` and `register_compatibility_reference_reader()` methods, so that a gateway's own registration replaces it whichever registers first; no current layer uses them yet.
- **Log under the plugin's source.** Use `FraudProtectionController::log()`, so the entry lands in the `woo-fraud-protection` log.
- **Treat third-party hook arguments as mixed.** Check types before using them.

## Native support checks

`GatewayNativeSupport::declared( $feature, ...$gateway_ids )`:

- returns `true` when any listed **registered** gateway's [`supports( $feature )`](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateway.html#method_supports) is `true`;
- skips unregistered IDs, so a layer can pass the current gateway first and the family's main gateway last (for example `stripe_sepa`, then `stripe`);
- returns `false`, and logs, when called before [`init`](https://developer.wordpress.org/reference/hooks/init/);
- returns `false` on any error, which keeps the layer on.

`ExtensionNativeSupport::declared( $extension_slug, $integration )` reads the `woocommerce_fraud_protection_native_integrations` filter and checks for the integration under the slug. Any error means "not declared".

`wp wc fraud-protection native-integrations list` prints both kinds of declarations.

## Verification sources

Each verification passes a source string (32 characters at most). Sources appear in logs, in the sessions table, and in skip-filter callbacks, which use them to decide whether a kept decision applies.

| Source | Where |
|--------|-------|
| `blocks_checkout` | `BlocksCheckoutProtector` |
| `shortcode_checkout` | `ShortcodeCheckoutProtector` |
| `pay_for_order` | `PayForOrderProtector` |
| `add_payment_method` | `AddPaymentMethodProtector` |
| `stripe_setup_intent_creation` | `StripeSetupIntentCompat` |
| `paypal_express_order_creation` | `PayPalCompat` (create-order) |
| `paypal_setup_token_creation` | `PayPalCompat` (setup token) |
| `paypal_vault_order_creation` | `PayPalCompat` (vault order) |
| `subscriptions_change_payment` | `SubscriptionsChangePaymentCompat` |
| Gateway-defined | `GatewayRequestVerifier::verify_request()` callers |

## Layer reference

### StripePaymentDataCompat

| | |
|--|--|
| Extension | WooCommerce Stripe Gateway |
| Gateways | `stripe` and `stripe_*` |
| Hooks | `woocommerce_fraud_protection_resolved_payment_data` |
| Stands aside | Gateway (or `stripe`) declares `fraud_protection_payment_data` |

Reads the posted Stripe payment method ID and fetches its details from the Stripe API through the gateway's own client. On the Add payment method form post, it resolves the payment method held by the posted setup intent, because that's the one Stripe saves. It reads the setup intent only in that request (while [`woocommerce_add_payment_method_form_is_valid`](https://woocommerce.github.io/code-reference/files/woocommerce-includes-class-wc-form-handler.html#source-view.648) is running): in the setup-intent AJAX request, a setup intent field can belong to an earlier intent.

### WooPaymentsPaymentDataCompat

| | |
|--|--|
| Extension | WooPayments |
| Gateways | `woocommerce_payments` and `woocommerce_payments_*` |
| Hooks | `woocommerce_fraud_protection_resolved_payment_data` |
| Stands aside | Gateway (or `woocommerce_payments`) declares `fraud_protection_payment_data` |

Resolves card and bank details through the WooPayments API. When WooPay is enabled, payment method IDs belong to the WooPay platform account and can't be read, so only the transaction mode and merchant identifier are resolved.

### SquarePaymentDataCompat

| | |
|--|--|
| Extension | WooCommerce Square |
| Gateways | `square_credit_card`, `square_cash_app_pay` |
| Hooks | `woocommerce_fraud_protection_resolved_payment_data` |
| Stands aside | Gateway (or `square_credit_card`) declares `fraud_protection_payment_data` |

Reads card details from Square's posted fields. No API call.

### PayPalPaymentDataCompat

| | |
|--|--|
| Extension | WooCommerce PayPal Payments |
| Gateways | `ppcp-*` |
| Hooks | `woocommerce_fraud_protection_resolved_payment_data` |
| Stands aside | Gateway (or `ppcp-gateway`) declares `fraud_protection_payment_data` |

Resolves the PayPal merchant identifier, the transaction mode, and saved wallet data.

### ExpressCheckoutScriptCompat

| | |
|--|--|
| Extension | WooPayments, Stripe |
| Hooks | [`wp_enqueue_scripts`](https://developer.wordpress.org/reference/hooks/wp_enqueue_scripts/) at `PHP_INT_MAX` |
| Stands aside | Per gateway, when it declares `fraud_protection_express_checkout` |

Loads `express-checkout.js` when `WCPAY_EXPRESS_CHECKOUT_ECE` or `wc_stripe_express_checkout` is enqueued. Those handles are enqueued only where express buttons show, including the Cart and Checkout blocks. The gateways' general block integration handles aren't used, because they're registered even when express buttons are off. The script receives the list of gateways to handle, so a gateway that declares the feature on the same page is left alone.

### PayPalScriptCompat

| | |
|--|--|
| Extension | WooCommerce PayPal Payments |
| Hooks | PayPal Payments' button render hooks (product, cart, mini-cart, checkout, Pay for order), [`woocommerce_before_mini_cart`](https://woocommerce.github.io/code-reference/files/woocommerce-templates-cart-mini-cart.html#source-view.22), [`woocommerce_widget_cart_is_hidden`](https://woocommerce.github.io/code-reference/files/woocommerce-includes-widgets-class-wc-widget-cart.html#source-view.55), the checkout and cart block enqueue hooks, [`woocommerce_checkout_before_order_review`](https://woocommerce.github.io/code-reference/files/woocommerce-templates-checkout-form-checkout.html#source-view.56), [`before_woocommerce_pay_form`](https://woocommerce.github.io/code-reference/files/woocommerce-includes-shortcodes-class-wc-shortcode-checkout.html#source-view.219), [`woocommerce_add_payment_method_form_bottom`](https://woocommerce.github.io/code-reference/files/woocommerce-templates-myaccount-form-add-payment-method.html#source-view.50), `woocommerce_subscriptions_change_payment_after_submit`, [`wp_enqueue_scripts`](https://developer.wordpress.org/reference/hooks/wp_enqueue_scripts/) (for SDK v6 surfaces) |
| Stands aside | Purchase surfaces when `ppcp-gateway` declares `fraud_protection_checkout`; Add payment method and change-payment-method forms when it declares `fraud_protection_add_payment_method`; SDK v6 surfaces that can save a PayPal account only when it declares both |

Loads `paypal-express.js` on every surface where PayPal renders buttons. On the Cart block it also loads `blocks-checkout.js`. PayPal Payments has changed its script handles across versions, so the layer checks the legacy handles too.

### PayPalCompat

| | |
|--|--|
| Extension | WooCommerce PayPal Payments |
| Hooks | `woocommerce_paypal_payments_create_order_request_started`, `woocommerce_paypal_payments_paypal_order_created`, `woocommerce_paypal_payments_woocommerce_order_created_from_cart`, `ppcp_request_args` |
| Stands aside | `ppcp-gateway` declares `fraud_protection_checkout`; for setup tokens, also `fraud_protection_add_payment_method` |

- Verifies PayPal's create-order request when it starts, and responds with an error on a block.
- Verifies the setup-token and vault-order requests to PayPal through `ppcp_request_args`, which runs before PayPal Payments calls PayPal's API.
- Ties the PayPal order that was created to the verification, so `PayPalDecisionReuse` can bind its record to it.
- Stores the session ID on the WooCommerce order that the express flow creates from the cart.

Setup tokens are created both at checkout and when saving a payment method, and the request doesn't say which. That's why both features must be declared before the layer stands aside for them.

### PayPalDecisionReuse

| | |
|--|--|
| Extension | WooCommerce PayPal Payments |
| Hooks | `woocommerce_fraud_protection_skip_session_verify` |
| Stands aside | Gateway (or `ppcp-gateway`) declares `fraud_protection_checkout` |

Keeps the latest PayPal request decision in the WooCommerce session (`_fraud_protection_paypal_verification`) and supplies it to the matching final submission once, then marks it used.

- **Create-order and vault-order decisions** are bound to the PayPal order that `PayPalCompat` associated with the verification. A blocked create-order request ends before PayPal creates an order, so its record never matches anything. Checkout and Pay for order submissions can reuse these records; vault-order records also serve the Subscriptions change-payment-method form.
- **Setup-token decisions**, block or allow, are bound to the session ID and the cart, and only checkout submissions can reuse them.

### StripeSetupIntentCompat

| | |
|--|--|
| Extension | WooCommerce Stripe Gateway |
| Hooks | `wc_stripe_is_valid_create_and_confirm_setup_intent_captcha`, `woocommerce_fraud_protection_skip_session_verify`, [`woocommerce_add_payment_method_form_bottom`](https://woocommerce.github.io/code-reference/files/woocommerce-templates-myaccount-form-add-payment-method.html#source-view.50) (20) |
| Stands aside | Gateway (or `stripe`) declares `fraud_protection_add_payment_method` |

- Verifies Stripe's `wc_stripe_create_and_confirm_setup_intent` AJAX request after Stripe's nonce and rate-limit checks, before the setup intent is created. Builds the gateway ID from `wc-stripe-payment-type` (`stripe` for cards, `stripe_{type}` otherwise).
- On a block, responds with Stripe's error shape ([`wp_send_json_error()`](https://developer.wordpress.org/reference/functions/wp_send_json_error/)).
- Keeps the decision in `_fraud_protection_stripe_setup_intent_verification` with the session ID, user and payment method, only when the request carried a session ID.
- On the form post (source `add_payment_method`), supplies the decision once, when the session ID and user match. An allow isn't supplied when the posted setup intent now holds another payment method. The record is retired on every Stripe add-payment-method verification, match or not.
- Loads `stripe-add-payment-method.js` after `add-payment-method.js`, when a Stripe gateway is available.

### SubscriptionsChangePaymentCompat

| | |
|--|--|
| Extension | WooCommerce Subscriptions |
| Hooks | `woocommerce_subscription_change_payment_method_via_pay_shortcode`, `woocommerce_subscriptions_change_payment_after_submit` |
| Stands aside | `woocommerce-subscriptions` declares `change_payment_method` |

Loads `pay-for-order.js` on the change-payment-method form, verifies the submission after Subscriptions' nonce check, and on a block adds the notice, redirects to the subscription and exits. Its class docblock shows how Subscriptions can take over the integration.

### WcposCompat

| | |
|--|--|
| Extension | WooCommerce POS (WCPOS) |
| Hooks | `woocommerce_fraud_protection_skip_session_verify` |
| Stands aside | Not applicable: WCPOS can supply the same decision through the public filter |

Supplies `Allow` for a `pay_for_order` verification when the request is a WCPOS request with a valid cashier token for a user with `access_woocommerce_pos`, and the gateway extends WCPOS's POS gateway base class. It uses only the public API, so it can move into WCPOS unchanged.

## Adding or changing a layer

1. **Prefer a native integration.** If the extension's maintainers can use the public API, help them do that instead.
2. **Map the flow first.** List every request in the attempt, where the card reaches the network, and every surface. [Chapter 4](../tutorial/04-gateway-specific-flows.md#questions-to-ask-about-any-gateway-flow) has the questions.
3. **Pick the declaration** that the layer implements, and add the native support check to every callback.
4. **Register it** in `FraudProtectionController::register_compat_layers()`.
5. **Test both sides**: the layer working, and the layer standing aside when its feature is declared while staying on for the others. `tests/php/Support/FakeNativeGateway.php` helps with the second.
6. **Reproduce with the real extension** in test mode before you open the pull request.
7. **Document the layer here** and, if it changes what an extension can declare, in the [integration guide](../integration-guide.md).
