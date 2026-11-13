# 4. Gateway-specific flows

The protectors from [chapter 3](03-fraud-protection.md) cover WooCommerce's own forms. Many real payments don't go only through those forms. This chapter explains where they leave them, how the plugin's compatibility layers protect them, and how gateways and other extensions can take over that work through native integrations.

WordPress hooks link to the WordPress developer reference. WooCommerce hooks link to WooCommerce's documentation where it describes them; the others have no stable page, so look them up by name in the [WooCommerce codebase](https://github.com/woocommerce/woocommerce/tree/trunk/plugins/woocommerce).

## Contents

- [Where the generic protectors fall short](#where-the-generic-protectors-fall-short)
- [The compatibility layers](#the-compatibility-layers)
- [Payment data resolvers](#payment-data-resolvers)
- [Express checkouts](#express-checkouts)
- [PayPal Payments](#paypal-payments)
- [Kustom's embedded checkout](#kustoms-embedded-checkout)
- [Stripe and WooPayments on Add payment method](#stripe-and-woopayments-on-add-payment-method)
- [WooCommerce Subscriptions: change payment method](#woocommerce-subscriptions-change-payment-method)
- [WooCommerce POS](#woocommerce-pos)
- [Native integrations](#native-integrations)
- [Questions to ask about any gateway flow](#questions-to-ask-about-any-gateway-flow)

## Where the generic protectors fall short

A protector verifies the request that WooCommerce hands to the gateway. That works when the gateway does all its work inside [`process_payment()`](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateway.html#method_process_payment) or [`add_payment_method()`](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateway.html#method_add_payment_method). It fails in five situations:

| Situation | Example | What goes wrong without help |
|-----------|---------|------------------------------|
| The card reaches the network **before** the WooCommerce form is posted | On My Account > Add payment method, Stripe and WooPayments create and confirm a setup intent in their own AJAX request, before the form post | The protector runs after the issuer has already seen the card. |
| The gateway starts the payment through **its own endpoint** | PayPal creates a PayPal order through its own AJAX request | That request never reaches a protector. |
| The request **skips the form** the plugin's script hooks into | Apple Pay or Google Pay buttons on a product page post straight to the Store API, or to Square's own endpoint | The request reaches the protector, but without a session ID. |
| The order is placed **without the shopper's browser** | Kustom's servers place the order through the Store API after the shopper pays in its iframe | The request reaches the protector, but no script can add a session ID when it's sent. |
| An extension **replaces WooCommerce's form handler** | WooCommerce Subscriptions handles the "change payment method" form on the Pay for order page itself | The protector's hook never fires. |

There's also a sixth, quieter gap: the protectors know the gateway ID but not much about the payment instrument. Each gateway posts different fields, so the plugin needs gateway-specific code to turn them into a card brand, last four digits, and so on.

## The compatibility layers

Each gap has a class under `src/Internal/FraudProtectionPlugin/Compat/`. They register from `FraudProtectionController::register_compat_layers()` at `woocommerce_loaded`.

| Layer | Extension | What it does |
|-------|-----------|--------------|
| `StripePaymentDataCompat` | Stripe | Resolves card details through the Stripe API. |
| `WooPaymentsPaymentDataCompat` | WooPayments | Resolves card details through the WooPayments API. |
| `SquarePaymentDataCompat` | Square | Reads card details from Square's posted fields. |
| `PayPalPaymentDataCompat` | PayPal Payments | Resolves the merchant identifier, transaction mode and saved wallet data. |
| `ExpressCheckoutScriptCompat` | WooPayments, Stripe, Square | Loads `express-checkout.js` where express buttons render. |
| `PayPalScriptCompat` | PayPal Payments | Loads `paypal-express.js` on every PayPal payment surface. |
| `PayPalCompat` | PayPal Payments | Verifies PayPal's create-order, setup-token and vault-order requests. |
| `PayPalDecisionReuse` | PayPal Payments | Lets the final checkout reuse the create-order decision. |
| `StripeSetupIntentCompat` | Stripe | Verifies Stripe's setup-intent AJAX request, and lets the form post reuse the decision. |
| `WooPaymentsSetupIntentCompat` | WooPayments | Does the same for WooPayments' setup-intent AJAX request. |
| `KustomCompat` | Kustom | Gets the session ID to the orders that Kustom's embedded checkout places, and keeps each ID to one attempt. |
| `SubscriptionsChangePaymentCompat` | WooCommerce Subscriptions | Verifies the change-payment-method form. |
| `WcposCompat` | WooCommerce POS | Skips verification for tenders a cashier records. |

These layers hook into *other plugins'* internals: their hook names, field names, script handles and AJAX actions. Those are not public contracts, so a gateway update can break a layer silently. That's the main reason for [native integrations](#native-integrations).

[Compatibility layers](../internals/compatibility-layers.md) in the internals reference lists each layer's hooks and the conditions under which it stands aside.

## Payment data resolvers

`PaymentDataResolver` builds a baseline `PaymentMethodData` (from a saved WooCommerce token when there is one) and passes it through the `woocommerce_fraud_protection_resolved_payment_data` filter. The payment-data layers hook that filter. Each one:

1. returns the incoming value unchanged when the gateway isn't theirs;
2. reads the gateway's posted fields, such as a Stripe `pm_...` payment method ID;
3. when needed, asks the gateway's API for the details;
4. on partial success, keeps every incoming field and adds only what it resolved.

Two things make this subtle:

- **Gateway IDs come in families.** Stripe registers `stripe`, `stripe_sepa`, `stripe_ideal` and more; WooPayments registers `woocommerce_payments` and `woocommerce_payments_*`; PayPal uses `ppcp-*`. A layer must match the whole family, and only that family.
- **The posted fields differ by surface.** The block checkout sends `payment_data` as a list of key-value pairs, the classic checkout posts flat fields, and Add payment method posts a setup intent instead of a payment method. The same layer must handle all of them.

## Express checkouts

WooPayments and Stripe show Apple Pay, Google Pay and similar buttons on product, cart and checkout pages. The button opens the wallet's payment sheet, and when the shopper approves, the gateway's script posts an order straight to the Store API checkout route. `BlocksCheckoutProtector` verifies that request like any block checkout, but nothing has added a session ID to it.

`ExpressCheckoutScriptCompat` fixes that:

1. On [`wp_enqueue_scripts`](https://developer.wordpress.org/reference/hooks/wp_enqueue_scripts/) (at `PHP_INT_MAX`), it checks whether a gateway enqueued its express button script, `WCPAY_EXPRESS_CHECKOUT_ECE` or `wc_stripe_express_checkout`.
2. If so, it loads the shared scripts and `express-checkout.js`.
3. `express-checkout.js` calls `prepareSessionId()` once, and adds a filter to each gateway's extension-data hook (`wcpay.express-checkout.cart-place-order-extension-data`, `wcstripe.express-checkout.cart-place-order-extension-data`).
4. When the gateway builds the Store API request, the filter adds the prepared session ID with `addSessionIdToExtensions()`.

The gateways build that data **synchronously**, inside the wallet's callback, so there's no time to wait for the SDK. That's why the ID is prepared ahead of time, and why `takePreparedSessionId()` never hands out the same ID twice.

Square's wallet buttons need two more pieces:

- On product, cart and classic checkout pages, they post the order to Square's own endpoint, `?wc-ajax=square_digital_wallet_process_checkout`, which runs the classic checkout process, so `ShortcodeCheckoutProtector` verifies it. Square has no filter for the request, so `express-checkout.js` adds the prepared session ID, or `not_ready`, to its body with a jQuery AJAX prefilter. `ExpressCheckoutScriptCompat` loads the script when Square enqueues `wc-square-digital-wallet`.
- On Pay for order, they submit the form with jQuery's `trigger( 'submit' )`, which fires no native `submit` event. `pay-for-order.js` adds a jQuery `submit` handler for this case, which adds a prepared session ID without holding the submit (see [Browser scripts](../internals/browser-scripts.md#submits-started-with-jquery)).

## Kustom's embedded checkout

Kustom replaces the checkout form with an iframe (see [chapter 2](02-payment-gateways.md#kustom)). Its orders still go through WooCommerce's checkout, where the protectors verify them, so `KustomCompat` only has to get the session ID to them:

- **Classic checkout.** Kustom's template doesn't fire the hooks that load `shortcode-checkout.js`, so the layer loads it on Kustom's `kco_wc_before_snippet`. Kustom posts the form without submitting it, and the script's AJAX prefilter adds the prepared session ID.
- **Checkout block.** No browser request carries the order, so `kustom-blocks-checkout.js` keeps the prepared session ID in the block's extension data while Kustom is selected, and Kustom sends that data with the order it places. The script follows the prepared ID without taking it, so the session keeps collecting until the shopper pays.

Because the browser isn't told when Kustom uses the ID, it can't reset the session afterwards. So the layer keeps each ID to one attempt on the server: it records the ID of each Kustom order that reaches verification, and a later Checkout block request, with any payment method, that carries the same ID is verified with `not_ready` instead. Until the prepared ID expires, later attempts on the same page are verified without a session.

A native integration only has to supply the session ID: once Kustom declares `fraud_protection_express_checkout`, the layer stands aside.

## PayPal Payments

PayPal's buttons create a PayPal order **before** the WooCommerce order exists:

1. The shopper clicks the PayPal button. PayPal Payments' script sends a create-order request to the store.
2. The store creates an order at PayPal and returns its ID.
3. The shopper approves the payment in PayPal's popup.
4. The store creates the WooCommerce order and captures the payment, either from the checkout form or directly from the express flow.

The plugin covers it with three layers and a script:

- `PayPalScriptCompat` loads `paypal-express.js` on every surface where PayPal renders buttons: product, cart, mini-cart, classic and block checkout, Pay for order, Add payment method, and the Subscriptions change-payment-method form.
- `paypal-express.js` wraps `fetch` and adds the session ID to PayPal Payments' supported requests.
- `PayPalCompat` verifies the create-order request, the vault-order request that pays with a saved PayPal account, and the setup-token request that saves an account or card. It blocks them on a `Block`, and associates the PayPal order with the verification.
- `PayPalDecisionReuse` supplies that decision through `woocommerce_fraud_protection_skip_session_verify` when the final submission matches the PayPal order that was verified, so the attempt is scored once. A blocked create-order request never creates a PayPal order, so there's nothing to complete.

Keeping one session ID per attempt takes care here, because PayPal's requests and the checkout belong to the same attempt:

- A setup-token or vault-order decision is reused only when the checkout sends the same session ID. So after a confirmed success, `paypal-express.js` hands that ID over to the checkout's next acquisition, as long as PayPal is still the selected gateway then.
- When the shopper closes PayPal and pays with another gateway, the script starts a new session for that payment.
- On the Checkout block, PayPal's card fields send their request while the block is already processing the order, so that request sends the checkout's session ID instead of acquiring its own.

[Browser scripts](../internals/browser-scripts.md#paypal-expressjs) has the details.

## Stripe and WooPayments on Add payment method

On My Account > Add payment method, Stripe and WooPayments don't wait for the form post. Stripe's flow:

1. The shopper enters a card in Stripe's fields and submits. Stripe's script turns it into a payment method (`pm_...`) in the browser; the issuer isn't involved yet.
2. Stripe's script sends `wc_stripe_create_and_confirm_setup_intent` through AJAX, with the payment method ID.
3. The server creates and confirms a setup intent. **The issuer sees the card at this step.** The response includes the intent's client secret.
4. If the issuer asks for 3-D Secure, the browser runs the challenge with that client secret.
5. Stripe's script posts the form with the setup intent ID, and Stripe's `add_payment_method()` saves the card that the intent holds.

`AddPaymentMethodProtector` only sees step 5. So `StripeSetupIntentCompat`:

- hooks `wc_stripe_is_valid_create_and_confirm_setup_intent_captcha`, which Stripe applies after its own nonce and rate-limit checks and before it creates the intent, and verifies there;
- on a `Block`, responds with Stripe's error shape and stops;
- keeps the decision in the WooCommerce session, with the session ID, the user and the verified payment method;
- supplies it to the form post of the same attempt, once, when the session ID and the user match. A kept block is always supplied; a kept allow isn't when the setup intent now holds a different payment method, and the form post is verified instead.

The last condition matters. The browser holds the setup intent's client secret, so Stripe's browser API can attach a different card to the same intent after the AJAX request. An allow for one card must not save another.

`stripe-add-payment-method.js` copies the form's session ID into Stripe's AJAX request, and resets the attempt when the setup fails in the browser.

WooPayments follows the same steps with its `create_setup_intent` AJAX request, and `WooPaymentsSetupIntentCompat` and `woopayments-add-payment-method.js` mirror the Stripe pair:

- The layer verifies on `wcpay_create_and_confirm_setup_intention_request`, which WooPayments applies right before it sends the setup intent, after its nonce, fraud-prevention and rate-limit checks. WooPayments also applies it when it saves a card for a $0 checkout order, where the checkout protectors already verify, so the layer acts only in the `create_setup_intent` request.
- It verifies the payment method that the request confirms, because WooPayments reads it from `wcpay-confirmation-token` before `wcpay-payment-method`, and the two can differ.
- WooPayments sends the request with `fetch()`, so the script wraps `fetch()` instead of using a jQuery prefilter.
- On the form post, `WooPaymentsPaymentDataCompat` resolves the card of the posted setup intent, because that's the one WooPayments saves.

## WooCommerce Subscriptions: change payment method

A subscriber can change the payment method of a subscription from My Account. Subscriptions renders WooCommerce's Pay for order form for this, but handles the submission itself, so `woocommerce_before_pay_action` never fires.

`SubscriptionsChangePaymentCompat`:

- loads `pay-for-order.js` from `woocommerce_subscriptions_change_payment_after_submit`;
- verifies on `woocommerce_subscription_change_payment_method_via_pay_shortcode`, which Subscriptions fires after its nonce check;
- on a `Block`, adds the notice, redirects and exits, so neither `update_payment_method()` nor [`process_payment()`](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateway.html#method_process_payment) runs.

Renewals have no browser and are not verified. They charge a payment method that was verified when it was added or changed.

## WooCommerce POS

WooCommerce POS (WCPOS) lets a cashier take payment at a till. When a cashier records cash or another POS tender, WCPOS pays the order through the Pay for order flow, from the cashier's device. There's no shopper browser to assess.

`WcposCompat` supplies an `Allow` through the skip filter only when all of these hold: the source is `pay_for_order`, the request is a WCPOS request with a valid cashier token for a user who can access the POS, and the gateway is a WCPOS gateway. Card payments taken in the POS through other gateways are still verified.

This is the one place where the evidence for skipping comes from another plugin rather than this one, and the class documents why the token can't be forged by a shopper.

## Native integrations

The compatibility layers work, but they're fragile and they live in the wrong place: only the plugin's maintainers can fix them when a gateway changes. Native integrations let each extension do the work itself.

The pieces:

- **Declarations.** A gateway adds `FraudProtectionFeature` strings to its [`$supports`](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateway.html#property_supports) array: `fraud_protection_payment_data`, `fraud_protection_checkout`, `fraud_protection_add_payment_method`, `fraud_protection_express_checkout`. Other extensions register `FraudProtectionIntegration` strings, such as `change_payment_method`, under their plugin slug: they hook the `woocommerce_fraud_protection_register_native_integrations` action and call `register()` on the `NativeIntegrationRegistry` it passes. The action fires once per request, the first time a compatibility layer asks.
- **Standing aside.** Each compatibility layer asks `GatewayNativeSupport` or `ExtensionNativeSupport` inside its hook callbacks whether the matching feature is declared, and does nothing if it is. The check must run inside the callbacks: gateway instances don't exist until [`WC_Payment_Gateways`](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateways.html) initializes them, after [`init`](https://developer.wordpress.org/reference/hooks/init/).
- **Public building blocks.** `GatewayPaymentData`, `GatewayRequestVerifier`, `BlackboxScriptHandler::register_scripts_for()`, `ClassicFormRequestData`, and the browser methods `takeSessionId()`, `prepareSessionId()`, `takePreparedSessionId()` and `addSessionIdToExtensions()`.

A gateway switches a built-in layer off by **declaring** the matching feature; registering a resolver or reference reader alone doesn't. `GatewayPaymentData` and `GatewayRequestVerifier` also have internal `register_compatibility_*()` methods, so that future layers built on those public services give way to a gateway's own registration, whichever registers first. No current layer uses them yet.

**The [integration guide](../integration-guide.md) is the starting point for an extension developer.** It explains which integration a gateway needs, how to declare it, and what to implement, with a short example of each. The [API reference](../api-reference.md) documents every public class, hook and browser method, and the [example integrations](../../examples/README.md) are two complete plugins that the test suite runs.

## Questions to ask about any gateway flow

When you work on a gateway flow, or review a change to one, walk through these:

1. **When does the card reach the network?** Find the first request that sends the card to the processor or issuer. That request, or one before it, is where verification must happen.
2. **Which requests does the browser send, in what order?** List them: the gateway's own AJAX or REST calls, the Store API request, the form post. Which ones go through a protector?
3. **Does each request carry a session ID, and a fresh one?** Is the ID taken when the shopper acts? Can the same ID end up in two requests? What resets the session after a failure that keeps the shopper on the page?
4. **Can the attempt change between requests?** The shopper can change the card, the cart, or the gateway after the first request. A reused decision must not cover what changed.
5. **What proves two requests belong to the same attempt?** Only server-created values, such as a provider order ID kept in the WooCommerce session.
6. **What happens on every surface?** The same gateway can render on product, cart, mini-cart, classic checkout, checkout block, Pay for order, Add payment method, and the change-payment-method form. Each one loads scripts differently.
7. **What happens when the gateway declares native support?** The layer must stand aside for exactly the features declared, and stay on for the others.
8. **What happens when something fails?** No SDK, no Jetpack connection, a gateway API error, an unexpected field type. The payment must still go through.
