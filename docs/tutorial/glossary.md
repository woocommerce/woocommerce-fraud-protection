# Glossary

Terms used across the documentation, grouped by area. Each entry links to where the term is explained in depth when there is such a place.

## Contents

- [WordPress and WooCommerce](#wordpress-and-woocommerce)
- [Payments](#payments)
- [Fraud Protection](#fraud-protection)

## WordPress and WooCommerce

| Term | Meaning |
|------|---------|
| **Action** | A hook that announces a moment: `do_action()` fires it, `add_action()` attaches to it. |
| **Filter** | A hook that lets callbacks change a value: `apply_filters()` fires it, `add_filter()` attaches to it. |
| **Priority** | The order in which callbacks attached to the same hook run. Lower runs first; the default is 10. |
| **Must-use plugin (MU-plugin)** | A plugin in `wp-content/mu-plugins/` that always loads and skips WordPress's plugin header checks. |
| **Nonce** | A short-lived WordPress token that protects a form or request against cross-site request forgery. Not authentication. Not the same as a payment nonce. |
| **Classic checkout** | The checkout rendered by the `[woocommerce_checkout]` shortcode, posted to `?wc-ajax=checkout`. See [chapter 1](01-woocommerce-checkout.md#the-classic-checkout-step-by-step). |
| **Block checkout** | The checkout rendered by the `woocommerce/checkout` block, posted to the Store API. See [chapter 1](01-woocommerce-checkout.md#the-block-checkout-step-by-step). |
| **Shortcode checkout** | Same as classic checkout. The plugin's code uses this name. |
| **Store API** | WooCommerce's public REST API for the storefront, under `/wp-json/wc/store/v1/`. The block checkout and cart use it. |
| **Extension data (`extensions`)** | Extra data that extensions attach to a Store API request, under their own namespace. |
| **Cart-Token** | A signed token that identifies a Store API session without a cookie. |
| **WooCommerce session** | Server-side storage for one shopper (`WC()->session`), tied to a cookie or Cart-Token. Not the fraud-detection session. |
| **Pay for order** | The page that pays an existing unpaid order: `/checkout/order-pay/{id}/`. See [chapter 1](01-woocommerce-checkout.md#pay-for-order). |
| **Add payment method** | The My Account page where a customer saves a payment method without buying. See [chapter 1](01-woocommerce-checkout.md#add-payment-method-and-saved-cards). |
| **Draft order** | An order with status `checkout-draft`, created by the block checkout before payment. |
| **HPOS** | High-Performance Order Storage: orders in custom tables instead of posts. Use the order CRUD API. |
| **Payment token** | A saved payment method in WooCommerce (`WC_Payment_Token_CC` and others), owned by a customer and a gateway. |
| **WooCommerce container** | WooCommerce's dependency-injection container, `wc_get_container()`. The plugin resolves its classes from it. |
| **Action Scheduler** | WooCommerce's job queue for background tasks. |

## Payments

| Term | Meaning |
|------|---------|
| **Payment gateway** | A WooCommerce class (`WC_Payment_Gateway`) that takes payment through a payment provider. See [chapter 2](02-payment-gateways.md). |
| **Payment provider (processor)** | The company that moves the money: Stripe, PayPal, Square, WooPayments (built on Stripe). |
| **Acquirer** | The merchant's bank, which accepts card payments on the merchant's behalf. |
| **Issuer** | The cardholder's bank, which approves or declines a payment. |
| **Card network** | Visa, Mastercard, American Express and others, which route payments between acquirers and issuers. |
| **Authorization** | The issuer's approval to take an amount. It reserves the funds. |
| **Capture** | Taking the authorized funds. Can happen with the authorization, or later. |
| **Void** | Cancelling an authorization before capture. |
| **Refund** | Returning captured funds. |
| **Decline** | The issuer refusing a payment. |
| **Chargeback (dispute)** | The cardholder asking the issuer to reverse a payment. The merchant can contest it. |
| **Card testing** | Fraudsters trying many stolen card numbers with small payments, or by saving cards, to find the ones that work. |
| **Tokenization** | Replacing card details with a token from the provider, so the store never handles card numbers. |
| **Hosted fields** | Card inputs served from the provider's domain inside an iframe. They keep the store's pages out of most PCI DSS scope. |
| **PCI DSS** | The card industry's security standard for handling card data. |
| **Payment method (Stripe)** | A Stripe object (`pm_...`) describing a card or other instrument. |
| **Payment intent** | A Stripe object (`pi_...`) that tracks one payment through authentication, authorization and capture. See [chapter 2](02-payment-gateways.md#intents). |
| **Setup intent** | A Stripe object (`seti_...`) that saves a payment method for later without charging it. Confirming it can contact the issuer. |
| **Client secret** | A value that lets the browser confirm a specific intent with the provider's script. |
| **Confirmation** | The step that sends an intent to the network. It can run in the browser or on the server. |
| **3-D Secure (3DS)** | A cardholder authentication step run by the issuer, often a bank app or code challenge. |
| **SCA** | Strong Customer Authentication: the European rule that requires 3-D Secure for many online payments. |
| **Liability shift** | When 3-D Secure succeeds, fraud chargeback liability moves from the merchant to the issuer. |
| **Payment nonce** | A single-use token from a provider's browser SDK (Square, Braintree) that stands for the card. Not a WordPress nonce. |
| **BIN** | Bank Identification Number: the first six or eight digits of a card, identifying the issuer. |
| **Last4** | The last four digits of a card number, safe to display. |
| **Fingerprint** | A provider-generated value that is the same for the same card number, across tokens. |
| **Express checkout** | Wallet buttons (Apple Pay, Google Pay, PayPal, Link) that skip the checkout form. See [chapter 2](02-payment-gateways.md#express-checkouts). |
| **Wallet** | An app that stores payment methods and shares them with a store at checkout: Apple Pay, Google Pay, PayPal. |
| **Redirect gateway** | A gateway that sends the shopper to the provider's site to pay, then back to the store. |
| **Webhook** | A server-to-server notification from the provider, such as "payment succeeded". It runs without the shopper's session. |
| **Merchant identifier** | The merchant's account ID at the provider, such as a Stripe account ID. |
| **Live and test mode** | Whether the gateway uses real money or the provider's sandbox. |
| **Renewal** | A scheduled subscription payment, charged without the shopper present. |

## Fraud Protection

| Term | Meaning |
|------|---------|
| **Blackbox** | The fraud-detection service the plugin uses. Its browser SDK collects telemetry; its API returns decisions. |
| **SDK** | The service's browser script, `v.js`, loaded with the handle `wc-fraud-protection-blackbox`. |
| **Session (fraud-detection)** | The service's description of one browser, identified by a session ID. Different from the WooCommerce session. |
| **Session ID** | The identifier of a fraud-detection session. Used for one payment attempt. See [chapter 3](03-fraud-protection.md#one-session-id-per-attempt). |
| **Identity (`wfp_id`)** | A first-party cookie with a random identity value, lasting for the browser session. The SDK receives it when it's configured. |
| **Payment attempt** | One try at paying or saving a payment method. It can span several requests. |
| **Payment surface** | A page where an attempt can start: checkout, cart, product page, Pay for order, Add payment method. |
| **Verification** | The server-side call that sends a session ID and context to the service and gets a decision. |
| **Source** | The string naming the flow that asked for a verification, such as `blocks_checkout`. |
| **Decision** | A `FraudDecision`. `Allow` and `Block` are actionable; anything else is treated as `Allow`. |
| **Fail open** | Allowing the attempt when something goes wrong, so a broken check never stops a legitimate payment. |
| **Protector** | A plugin class that verifies one WooCommerce flow and enforces the decision. |
| **Compatibility layer** | A plugin class that does the same for one gateway's or extension's own flow. |
| **Native integration** | A gateway or extension that protects its own flows with the public API and declares it, so the matching compatibility layer stands aside. |
| **Feature (`FraudProtectionFeature`)** | A string a gateway declares in `$supports`, such as `fraud_protection_checkout`. |
| **Integration (`FraudProtectionIntegration`)** | A string another extension declares through a filter, such as `change_payment_method`. |
| **Supplied decision** | A decision returned through `woocommerce_fraud_protection_skip_session_verify`, which skips the service call. |
| **Decision reuse** | Applying the decision of a gateway's earlier request to the submission that completes the same attempt. |
| **Reference** | A value the gateway's server created in a verified request, used to recognize the submission that completes it. |
| **Automatic protection** | The merchant setting that enforces the service's block decisions. |
| **Merchant rule** | A merchant's allow or block rule for an email or IP address. Takes priority over the service. |
| **Attempts log** | The sessions table and the checkout attempts list built from it. |
| **Trigger** | What decided a recorded attempt: `blackbox`, `verify_error`, `request_rejected`, `allow_rule` or `block_rule`. |
| **Platform log forwarding** | Sending a sanitized copy of a log entry to the PHP error log for central monitoring. |
