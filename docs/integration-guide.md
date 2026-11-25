# Integrating with WooCommerce Fraud Protection

This guide is for payment gateways and other WooCommerce extensions that want to integrate with Fraud Protection natively. It explains what the plugin already does, which integration your extension needs, how to declare it, and what to implement for each one. The [public API reference](api-reference.md) documents every class, hook and script that this guide uses, and the [example integrations](../examples/README.md) show complete working code. The [glossary](#glossary) at the end defines the terms this guide uses, such as session ID, payment attempt and verification.

## Fraud Protection in a nutshell

Fraud Protection is a WooCommerce plugin, made by Automattic, that checks payment attempts for fraud before the store processes them. It relies on [Blackbox](#blackbox), Automattic's fraud-detection service, to assess each attempt, and it flags the attempts that Blackbox considers suspicious: they are recorded as flagged for the merchant. With [automatic blocking](#automatic-blocking) on, which is the default, it also stops them. The merchant's own block [rules](#merchant-rules) stop the attempts they match either way.

Fraud Protection requires the store to be connected to WordPress.com through Jetpack. It reaches Blackbox through that connection, and it loads its browser scripts only on connected stores. On a store without the connection, only the merchant rules can block an attempt.

Every protected [payment attempt](#payment-attempt) goes through the same steps:

1. **A browser session starts.** On the pages that show a [payment surface](#payment-surface), such as the checkout page, Fraud Protection loads its [shared scripts](#shared-scripts). One of them, the [Blackbox SDK](#blackbox-sdk), starts a browser session for the shopper.
2. **The request carries a session ID.** When the shopper starts the attempt, for example by placing the order, the plugin's scripts take a [session ID](#session-id) for it and add it to the request that the browser sends to the store.
3. **The server verifies the attempt.** Before the payment or the payment method is processed, Fraud Protection [verifies](#verification) the attempt: it sends the session ID and the attempt's data, such as the details of the payment method and the addresses, to Blackbox, which answers with a [decision](#decision). A matching merchant rule replaces that decision. When automatic blocking is off, an attempt that Blackbox blocks is flagged but allowed.
4. **The decision is enforced.** On Allow, the request continues as usual. On Block, Fraud Protection stops it and shows the shopper a generic message that doesn't mention fraud detection.
5. **Outcomes can be reported.** Fraud Protection stores the session ID on the order. The gateway can later [report](api-reference.md#fraudprotectionreporter) what happened to the payment, such as a capture, a dispute or a refund, and the report is tied to the verification.

An error never blocks a payment. When the scripts don't load, the request goes out without a session ID and is still verified; when Blackbox can't be reached or doesn't answer in time, the attempt is allowed unless a merchant rule blocks it.

On the standard WooCommerce flows, Fraud Protection takes all these steps by itself. This is its [built-in protection](#built-in-protection). An extension integrates when it takes part in these steps: when it knows details of the payment method that WooCommerce doesn't hold (step 3), or when its own requests place an order or save a payment method, and so need the session ID, the verification and the enforcement (steps 2 to 4).

## Do you need to integrate?

Fraud Protection verifies the standard WooCommerce flows by itself: the block and shortcode checkouts, the "Pay for order" page, and the My Account "Add payment method" page. This built-in protection applies to every gateway on those flows, and no declaration turns it off. When WooCommerce Subscriptions is active, its change-payment-method form is verified too. A gateway that takes payment only through `process_payment()` on those flows is already covered and needs no native integration. Providing the details of the payment method that each payment uses, such as the card brand and last four digits, still gives each verification better data (see [Payment data](#payment-data)).

An extension needs a native integration when it moves part of a payment outside those flows. For example, its buttons may place an order through their own request, or it may save a card before the form is submitted. A native integration is code in your extension that protects those requests, and a declaration tells Fraud Protection that your extension has it.

A request that only checks the checkout form before the submission needs no native integration, even when it fires `woocommerce_checkout_process` or `woocommerce_after_checkout_validation`. Fraud Protection verifies the shortcode checkout inside `WC_Checkout::process_checkout()`, so firing those hooks yourself verifies nothing, and the [submission](#submission) that follows is verified as usual. Don't verify such a request yourself with [`verify_request()`](api-reference.md#verify_request): a session ID can be verified only once, and the form your request posts can already hold the one for the submission.

Three rules apply to every native integration:

- **Fraud Protection is optional.** Register your native integration only when the classes you use exist, for example `class_exists( GatewayRequestVerifier::class )`; and declare features with literal strings rather than the plugin's constants, so your extension keeps working without it.
- **Fail open.** Never stop a payment because Fraud Protection is unavailable. Send a request without a session ID when you have none; the server still verifies it.
- **Declare only what you implement.** A declaration never turns off the built-in protection of the standard flows. It tells Fraud Protection that your extension handles that part itself, so Fraud Protection can stop any handling specific to your extension. Declaring something you don't implement can leave your extension's own requests unprotected, or your payments verified without payment data.

## Choose your integrations

Most gateways should provide [payment data](#payment-data). It needs only a [resolver](#resolver) on the server, and it improves the verification of every payment made with your gateway, including the ones that the built-in protection covers. The other integrations apply only when your extension sends its own requests outside the standard flows, as described in [Do you need to integrate?](#do-you-need-to-integrate).

| Your extension | Declare | Section |
|----------------|---------|---------|
| Is a gateway and can provide the details of the payment method that each payment uses, such as the card brand and last four digits | `fraud_protection_payment_data` | [Payment data](#payment-data) |
| Is a gateway that places or authorizes a purchase through its own requests, before or instead of the checkout submission | `fraud_protection_checkout` | [Checkout requests](#checkout-requests) |
| Is a gateway that checks or saves a payment method through its own requests, before the "Add payment method" or change-payment-method form is submitted | `fraud_protection_add_payment_method` | [Add payment method requests](#add-payment-method-requests) |
| Is a gateway whose express payment buttons or embedded checkout skip the checkout form, but place the order through WooCommerce's checkout: the Store API, or the classic checkout process | `fraud_protection_express_checkout` | [Express checkout](#express-checkout) |
| Is not a gateway and handles its own change-payment-method form | `change_payment_method` | [Change payment method forms](#change-payment-method-forms) |

## Declare your integrations

### Payment gateways

A gateway declares its integrations as gateway features, in the `$supports` array of its `WC_Payment_Gateway` subclass:

```php
$this->supports = array(
	'products',
	'refunds',
	'fraud_protection_payment_data',
	'fraud_protection_checkout',
	'fraud_protection_add_payment_method',
	'fraud_protection_express_checkout',
);
```

If your plugin registers one gateway instance per payment method, declare the features on every instance that implements them. The resolvers and [reference readers](#reference) you register below can cover all those instances at once with a [gateway ID pattern](api-reference.md#gateway-id-patterns), such as `my_gateway_*`.

### Other extensions

Any other extension declares its integrations under its own plugin slug (its WordPress.org or WooCommerce.com slug). Hook the `woocommerce_fraud_protection_register_native_integrations` action while your plugin loads, and call `register()` on the registry it passes, once per integration:

```php
use Automattic\WooCommerce\FraudProtection\NativeIntegrationRegistry;

add_action(
	'woocommerce_fraud_protection_register_native_integrations',
	function ( NativeIntegrationRegistry $registry ): void {
		$registry->register( 'my-extension', 'change_payment_method' );
	}
);
```

The action fires only while Fraud Protection is active, so your callback needs no class check. It fires at most once per request, the first time Fraud Protection needs the declarations, and a registration made outside it is ignored. Declare only under your own slug: Fraud Protection reads each extension's declarations under that extension's slug.

### Check the declaration

Run `wp wc fraud-protection native-integrations list`. It lists the declarations of every gateway and extension, one row per feature or integration. Add `--id` with your gateway ID or plugin slug, as in `wp wc fraud-protection native-integrations list --id=my_gateway`, to print only your own declarations. See [WP-CLI](api-reference.md#wp-cli) in the API reference for its output and options.

## The building blocks

The steps below protect a request that your extension's script sends from the shopper's browser outside the standard flows, such as an AJAX call to your server that creates an order at your payment provider. This guide calls it "your request". The page loads the browser scripts (step 1), your script adds a session ID to your request (step 2), and your server verifies your request before acting on it (step 3).

Checkout requests and Add payment method requests use all three steps. Express checkout uses the first two: its request runs WooCommerce's checkout, through the Store API or the classic checkout process, where Fraud Protection verifies it by itself. The section for each integration shows the part specific to it. Payment data needs none of these steps: a resolver on the server is all it takes. Change payment method forms use a ready-made script, described in their own section.

### 1. Load the browser scripts

Register your script as you already do, and call `register_scripts_for()` with its handle right after:

```php
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;

add_action(
	'wp_enqueue_scripts',
	function (): void {
		wp_register_script( 'my-gateway-checkout', plugins_url( 'js/checkout.js', __FILE__ ), array(), '1.0.0', true );

		wc_get_container()->get( BlackboxScriptHandler::class )->register_scripts_for( 'my-gateway-checkout' );
	}
);
```

Then enqueue your script where your payment surface renders, as you already do.

`register_scripts_for()` registers the shared scripts and makes your script depend on `wc-fraud-protection-blackbox-init`. It loads nothing by itself: WordPress prints the shared scripts only on the pages where your script is enqueued, before your script, so `window.wcFraudProtection` is set up before your script runs. So you can call it wherever you register your scripts, even on every page, or from `get_payment_method_script_handles()` for a payment method in the Checkout block. Pass it only the scripts that run on your payment surfaces: a script you enqueue on every page, such as a site-wide helper, would load the shared scripts on every page too. It also works for a script that is already enqueued, as long as it hasn't been printed.

It changes nothing and returns `false` when the store is not connected to Jetpack, or in the WordPress admin. On pages that must not load the shared scripts, such as the order confirmation page or a post preview, they are left out when the scripts are printed. Either way, your script loads without them, and your requests go out without a session ID and are still verified. A script that exists only for Fraud Protection can instead be enqueued from your payment surface only when `request_scripts()` returns `true`, with `wc-fraud-protection-blackbox-init` as a dependency.

### 2. Send the session ID

When the shopper starts the payment attempt, for example with a click on your payment button, your script takes a session ID with `takeSessionId()` and adds it to your request, under the field name in `config.sessionIdField`. Take it right before your script sends your request, not when your script loads or the payment surface renders:

```js
async function addSessionId( body ) {
	const fp = window.wcFraudProtection;
	if ( fp && typeof fp.takeSessionId === 'function' ) {
		body[ fp.config.sessionIdField ] = await fp.takeSessionId();
	}
	return body;
}

const body = await addSessionId( { action: 'my_gateway_create_order' } );
// Send your request to your server with body.
```

`takeSessionId()` never rejects. It resolves to an empty string when there is no session ID, for example when a content blocker stopped the Blackbox SDK; send your request anyway. It also starts a fresh session for the next payment attempt, so there is no `reset()` call. The check covers pages where the shared scripts were not loaded, and Fraud Protection versions before 0.4.0.

`takeSessionId()` sends the shopper's latest session data in a network request first, and resolves when that completes or after at most 3 seconds. Where that delay matters, for example in code that must open a payment sheet or popup in response to a click, or in a code path that must add the ID synchronously, use the [prepared session ID](#prepared-session-id) instead (see [Express checkout](#express-checkout)).

### 3. Verify the request and reuse its decision

On your server, verify your request with `GatewayRequestVerifier::verify_request()` before you act on it, and stop it on a Block decision with the [standard message](api-reference.md#blockedsessionmessage). When your request comes before a WooCommerce submission that the plugin also verifies, such as the checkout that completes a purchase your request started, reuse the decision for that submission, so the attempt is verified only once:

```php
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier;
use Automattic\WooCommerce\FraudProtection\MessageContext;

$request = wc_get_container()->get( GatewayRequestVerifier::class )->verify_request( 'my_gateway_create_order', 'my_gateway' );

if ( $request->is_blocked() ) {
	wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ) ), 403 );
}

$provider_order_id = my_gateway_create_provider_order(); // Your own call.
$request->reuse_decision_for( $provider_order_id );
```

The first argument, the [source](#source), identifies your flow: a stable ASCII string of 32 characters or fewer. `verify_request()` reads the session ID from the request body, under the field name in `config.sessionIdField`: a form field, or a top-level property of a JSON body. When your request carries it anywhere else, such as in a nested JSON object, a header or a REST route parameter, pass the session ID you received as the fifth argument; otherwise the request is verified without it. Use `MessageContext::Purchase` in purchase flows and `MessageContext::Generic` elsewhere, and `get_html()` instead of `get_plaintext()` for a notice rendered in a page.

The reference you pass to `reuse_decision_for()` must be a value that only your server created while handling this request, such as the ID of the order or setup intent at your payment provider. The browser gets it from your response, and the final submission posts it back. Tell the plugin where to find it in that submission, once, while your plugin loads:

```php
wc_get_container()->get( GatewayRequestVerifier::class )->register_reference_reader(
	'my_gateway',
	fn( array $payment_data ) => $payment_data['my_gateway_order_id'] ?? ''
);
```

The next submission paid with your gateway that carries the reference, with the same cart items, reuses the decision instead of being verified, once. Merchant rules are still checked against that submission, so a rule that matches its billing email replaces the reused decision even when your request came before the shopper entered it. Any other submission is verified normally. The [API reference](api-reference.md#gatewayrequestverifier) has the exact rules, and the lower-level [`SessionVerifier`](api-reference.md#sessionverifier) and [`woocommerce_fraud_protection_skip_session_verify`](api-reference.md#woocommerce_fraud_protection_skip_session_verify) for flows it does not cover.

## Integrations

### Payment data

Fraud Protection knows which gateway takes each payment, but not the details of the payment method the shopper uses. Those details are in your gateway's fields or at your payment provider: the payment method type, such as card or bank debit, the card brand, last four digits and expiry date, the wallet, and the results of the CVC and address checks. Only a saved payment method's WooCommerce token holds some of them. A resolver provides these details for each payment made with your gateway, together with whether the payment runs in live or test mode and which merchant account receives it. The [`PaymentInstrumentData`](api-reference.md#paymentinstrumentdata) reference lists every detail you can provide.

**Declare:** `fraud_protection_payment_data` in the gateway's `$supports`.

**Implement:** while your plugin loads, register a resolver with `GatewayPaymentData::register_resolver()`. It returns a `PaymentMethodData` with the details of a payment made with your gateway:

```php
use Automattic\WooCommerce\FraudProtection\GatewayPaymentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

wc_get_container()->get( GatewayPaymentData::class )->register_resolver(
	'my_gateway',
	function ( array $payment_fields, PaymentMethodData $resolved ): PaymentMethodData {
		if ( $resolved->to_array()['is_saved_payment_method'] ) {
			return $resolved->with_transaction_mode( PaymentMode::Live )
				->with_merchant_identifier( 'acct_123', 'account' );
		}

		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand' => $payment_fields['my_gateway_card_brand'] ?? null,
				'last4' => $payment_fields['my_gateway_card_last4'] ?? null,
			)
		);

		return new PaymentMethodData( 'my_gateway', 'card', false, $instrument, PaymentMode::Live, 'acct_123', 'account' );
	}
);
```

The plugin calls the resolver only for attempts paid with your gateway. `$payment_fields` holds the gateway fields submitted with the attempt, or the payment data you passed to `verify_request()`. `$resolved` is pre-filled from the WooCommerce payment token when the shopper pays with a saved method, so the example keeps it and adds only what the token doesn't hold. Return `null` to keep it unchanged. If the resolver throws, or returns anything other than a `PaymentMethodData` for your gateway, the plugin keeps `$resolved`.

The resolver also runs when the My Account "Add payment method" form or the WooCommerce Subscriptions change-payment-method form is submitted with your gateway selected, with the fields your gateway posts on that form. You need no code specific to those forms.

### Checkout requests

**Declare:** `fraud_protection_checkout` in the gateway's `$supports`.

**Implement:** for each request that places or authorizes a purchase before or instead of the WooCommerce checkout or Pay for order submission, such as a create-order call:

1. [Load the browser scripts](#1-load-the-browser-scripts) on the product, cart, checkout and Pay for order pages where your buttons render.
2. [Send the session ID](#2-send-the-session-id) with the request.
3. [Verify it](#3-verify-the-request-and-reuse-its-decision), stop on a Block decision with `MessageContext::Purchase`, and reuse the decision for the checkout or Pay for order submission that completes the purchase.

```php
add_action(
	'wc_ajax_my_gateway_create_order',
	function (): void {
		check_ajax_referer( 'my-gateway-create-order', 'security' );

		$request = wc_get_container()->get( GatewayRequestVerifier::class )->verify_request( 'my_gateway_create_order', 'my_gateway' );
		if ( $request->is_blocked() ) {
			wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ) ), 403 );
		}

		$provider_order_id = my_gateway_create_provider_order(); // Your own call.
		$request->reuse_decision_for( $provider_order_id );

		wp_send_json_success( array( 'order_id' => $provider_order_id ) );
	}
);
```

The checkout that follows then posts `my_gateway_order_id`, where the reader you passed to `register_reference_reader()` finds it.

These requests include one that creates the order without `WC_Checkout::process_checkout()`, even when it fires WooCommerce's validation hooks: Fraud Protection doesn't verify it for you. They don't include an authorization that your provider's browser SDK performs before the normal checkout submission, because that submission is verified.

### Add payment method requests

The submissions of the My Account "Add payment method" form and the WooCommerce Subscriptions change-payment-method form are already verified, before your gateway saves the payment method. A gateway that saves it only while handling that submission, such as in `add_payment_method()`, needs no integration for these forms. Declare this feature when your gateway checks or saves the payment method through its own request before the form is submitted, such as creating a setup intent, because the payment method can be saved before the submission is verified.

**Declare:** `fraud_protection_add_payment_method` in the gateway's `$supports`.

**Implement:** the same steps as [Checkout requests](#checkout-requests), for each request that checks or saves a payment method outside a purchase, such as creating a setup intent. These requests come from the My Account "Add payment method" form and from the WooCommerce Subscriptions change-payment-method form. Block with `MessageContext::Generic`, and reuse the decision for the form submission:

```php
add_action(
	'wc_ajax_my_gateway_create_setup_intent',
	function (): void {
		check_ajax_referer( 'my-gateway-setup-intent', 'security' );

		$request = wc_get_container()->get( GatewayRequestVerifier::class )->verify_request( 'my_gateway_setup_intent', 'my_gateway' );
		if ( $request->is_blocked() ) {
			wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Generic ) ), 403 );
		}

		$setup_intent_id = my_gateway_create_setup_intent(); // Your own call.
		$request->reuse_decision_for( $setup_intent_id );

		wp_send_json_success( array( 'setup_intent' => $setup_intent_id ) );
	}
);
```

The reader you pass to `register_reference_reader()` serves both flows: return the setup intent ID when the submission carries one.

### Express checkout

This integration covers any payment surface that skips WooCommerce's checkout form but still places the order through WooCommerce's checkout, so the form's scripts never add the session ID: express payment buttons, or an embedded checkout that your gateway renders in place of the form. The examples below use express buttons.

**Declare:** `fraud_protection_express_checkout` in the gateway's `$supports`.

**Implement:** add the session ID to the Store API checkout request your express buttons send. Put it in the request's extension data, under the `woocommerce/fraud-protection` namespace as `blackbox_session_id`. The plugin verifies that request like any other Store API checkout, so there is nothing to do on the server.

If your buttons post the order to a request that runs WooCommerce's classic checkout process instead, such as your own AJAX endpoint that calls `WC_Checkout::process_checkout()`, add the session ID to the request body under the field name in `config.sessionIdField`. Prepare a session ID when the buttons are set up, as below, and take one with `takePreparedSessionId()` for each request. The plugin verifies the request in the classic checkout, so again there is nothing to do on the server.

Express buttons usually build their request in a synchronous callback, which can't wait for a promise. Prepare a session ID when the buttons are set up, and add it with `addSessionIdToExtensions()` when the request's extension data is built:

```js
const fp = window.wcFraudProtection;
const canAdd = fp && typeof fp.addSessionIdToExtensions === 'function';

// When the express buttons are set up:
if ( canAdd ) {
	fp.prepareSessionId();
}

// When the Store API checkout request is built:
const extensions = canAdd ? fp.addSessionIdToExtensions( buttonExtensions ) : buttonExtensions;
```

`prepareSessionId()` fetches the session ID in the background, so `addSessionIdToExtensions()` adds it without waiting. Call `addSessionIdToExtensions()` for every request, so each click gets its own session ID, and never keep an ID for a later request.

`addSessionIdToExtensions()` returns a copy of the extension data with the prepared session ID under the plugin's namespace. It always replaces a session ID already in the data, so an earlier attempt's ID is never sent again, and it starts a fresh session for the next attempt, so there is no `reset()` call. Pass an object, `{}` when your request has no other extension data; any other value is returned unchanged. When no session ID is ready, the request goes out with the reason why it has none instead. The check covers pages where the shared scripts were not loaded, and Fraud Protection versions before 0.4.0.

Make your button script depend on the browser scripts as in [step 1](#1-load-the-browser-scripts), and enqueue it on every page where the buttons render. Use the prepared session ID even when your button code could wait for a promise: `takeSessionId()` sends a network request and can take up to 3 seconds, which an express flow can't afford between the click and the payment sheet or the order request.

### Change payment method forms

**Declare:** `change_payment_method` under your plugin slug, from the `woocommerce_fraud_protection_register_native_integrations` action (see [Other extensions](#other-extensions)).

**Implement:** for an extension that handles its own change-payment-method form, rendered as WooCommerce's `#order_review` pay form:

1. Call `request_pay_for_order_script()` from the code that renders the form. The script it loads holds the form submission until it has added the session ID as a hidden field.
2. On submission, after your own nonce and permission checks, read the form with `ClassicFormRequestData` and verify it before changing anything.
3. On a Block decision, stop the change and show the standard message with `MessageContext::Generic`.

```php
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

// While rendering the form:
wc_get_container()->get( BlackboxScriptHandler::class )->request_pay_for_order_script();

// On submission, after the nonce and permission checks:
$form     = new ClassicFormRequestData();
$decision = wc_get_container()->get( SessionVerifier::class )->verify_session(
	$form->get_submitted_session_id(),
	'my_extension_change_payment',
	$order->get_id(),
	$form->build_request_data( $_POST )
);

if ( FraudDecision::Block === $decision ) {
	wc_add_notice( ( new BlockedSessionMessage() )->get_html( MessageContext::Generic ), 'error' );
	wp_safe_redirect( $order->get_view_order_url() );
	exit;
}
```

## Test your integration

- Load the [example integrations](../examples/README.md) in the local store to compare their behavior with yours.
- Run `wp wc fraud-protection native-integrations list` and check that it lists your declarations.
- Watch the `woo-fraud-protection` log under WooCommerce > Status > Logs while you go through your flows. Each decision is logged with the source you passed to `verify_session()`. Check that each payment attempt verifies its session ID only once. A request of yours that fires `woocommerce_checkout_process` is logged as `Checkout validation ran outside the checkout process`; if that request creates an order, it needs a [checkout integration](#checkout-requests).
- Go through each flow once without a Jetpack connection, or with the Fraud Protection scripts blocked in the browser, and check that payments still complete.

## Next steps

A gateway can also report payment outcomes, such as captures, disputes and refunds, for orders that went through a verification. See `FraudProtectionReporter` in the [public API reference](api-reference.md#fraudprotectionreporter).

## Glossary

### Automatic blocking

The store setting that makes Fraud Protection stop the attempts that Blackbox flags. It is on by default. When the merchant turns it off, Fraud Protection still verifies every attempt, but it allows the attempts that Blackbox blocks and records them as flagged. Merchant block rules apply either way.

### Blackbox

The fraud-detection service that Fraud Protection uses, provided by Automattic, the company behind WooCommerce. Fraud Protection sends it the data of each verification, and Blackbox answers with a decision. Fraud Protection then applies the store's merchant rules and settings to that decision. Fraud Protection reaches Blackbox through the store's Jetpack connection. Without one, Blackbox can't be reached, and verifications [fail open](#do-you-need-to-integrate): no payment is blocked because of it. How Blackbox reaches its decisions is not part of the public API.

### Blackbox SDK

The browser library of [Blackbox](#blackbox). The plugin loads it from Blackbox instead of shipping it. It creates the shopper's browser session and the session IDs that identify it, and it records the shopper's activity on the page for that session. It is one of the shared scripts, and it gives its name to identifiers such as `BlackboxScriptHandler` and `blackbox_session_id`. It may not load, for example when a content blocker stops it. The shared scripts then hand out no session ID, and requests are verified without one.

### Built-in protection

The verifications that Fraud Protection runs by itself on the standard WooCommerce flows: the block and shortcode checkouts, the "Pay for order" page, the My Account "Add payment method" page and, when WooCommerce Subscriptions is active, its change-payment-method form. No declaration turns it off. See [Do you need to integrate?](#do-you-need-to-integrate).

### Declaration

A statement that your extension implements a native integration: a feature string in a gateway's `$supports` array, or an integration string that another extension registers under its plugin slug. Fraud Protection doesn't check that you implement what you declare. See [Declare your integrations](#declare-your-integrations).

### Decision

The result of a verification: `FraudDecision::Allow` or `FraudDecision::Block` (see [Enums](api-reference.md#enums)). On Allow, the request continues. On Block, stop it and show the [standard message](api-reference.md#blockedsessionmessage). Merchant rules and the store's [automatic blocking](#automatic-blocking) setting are already applied to the decision you receive. When a verification can't complete, the decision is Allow.

### Merchant rules

Rules that the merchant creates to allow or block the attempts made with a given billing email address or from a given IP address. The WooCommerce admin calls them custom rules, under the Fraud prevention settings, and the `wp wc fraud-protection custom-rules` commands manage them too. Fraud Protection checks them on every verification, and the first rule that matches decides the attempt: its decision replaces the one from Blackbox, and neither automatic blocking nor the [`woocommerce_fraud_protection_automated_decision`](api-reference.md#woocommerce_fraud_protection_automated_decision) filter changes it. A block rule stops the attempt even when automatic blocking is off or Blackbox can't be reached. The [`woocommerce_fraud_protection_rule_applied`](api-reference.md#woocommerce_fraud_protection_rule_applied) action tells extensions when a rule decided an attempt.

### Native integration

Code in your extension that protects the requests it sends outside the standard flows, or that provides payment data, together with the [declaration](#declaration) that tells Fraud Protection about it. See [Choose your integrations](#choose-your-integrations).

### Payment attempt

One try by the shopper to pay for an order or to save a payment method, from the moment they start it, such as a click on a payment button, to the submission that completes it. An attempt can span several requests, such as your create-order request and the checkout submission that follows it, and it is verified once, with one session ID. A retry after a decline, or a payment with another payment method, is a new attempt.

### Payment surface

The part of a page where the shopper starts a payment attempt: the checkout form, express payment buttons, an embedded checkout, the "Pay for order" form, or a form that adds or changes a payment method. Load the shared scripts on the pages that show yours.

### Prepared session ID

A session ID that `prepareSessionId()` fetches in the background, so that `takePreparedSessionId()` or `addSessionIdToExtensions()` can add it to a request without waiting. Use it in code that can't wait for a promise. See [Express checkout](#express-checkout).

### Reference

A value that only your server created while handling a request it verified, such as the ID of the order or setup intent at your payment provider. Your server keeps the request's decision with it, and the submission that completes the attempt posts it back. A reference reader, registered with `register_reference_reader()`, tells Fraud Protection where to find it in that submission, which then reuses the decision instead of being verified again. See [step 3](#3-verify-the-request-and-reuse-its-decision).

### Resolver

A function that your gateway registers with `GatewayPaymentData::register_resolver()`. Before each verification of an attempt paid with your gateway, Fraud Protection calls it to get the details of the payment method that the attempt uses. See [Payment data](#payment-data).

### Session ID

The identifier of the shopper's browser session, which the [Blackbox SDK](#blackbox-sdk) creates on the pages where the shared scripts load. The session includes the shopper's activity on those pages, and a verification uses it together with the attempt's data. Your script sends the session ID with each request that is verified, in the places listed under [Where the server reads the session ID](api-reference.md#where-the-server-reads-the-session-id). Each session ID belongs to one payment attempt and can be verified only once. A request without one is still verified.

### Shared scripts

The [Blackbox SDK](#blackbox-sdk) and the `wc-fraud-protection-blackbox-init` script, which sets up [`window.wcFraudProtection`](api-reference.md#windowwcfraudprotection). This guide also calls them the browser scripts. They load only on stores connected to Jetpack. See [Load the browser scripts](#1-load-the-browser-scripts).

### Source

A stable ASCII string of 32 characters or fewer that identifies the flow a verification belongs to, such as `my_gateway_create_order`. You pass it to `verify_request()` or `verify_session()`, and the plugin's logs show it with each decision. See [`verify_session()`](api-reference.md#verify_session).

### Submission

The WooCommerce request that completes a payment attempt on a standard flow, and that the built-in protection verifies: the checkout or "Pay for order" submission, or the submission of the "Add payment method" or change-payment-method form.

### Verification

Fraud Protection's check of a payment attempt, on your store's server, before the payment or the payment method is processed. It sends the attempt's data, such as the session ID, the payment data and the addresses, to [Blackbox](#blackbox) and returns a decision. The built-in protection verifies the standard submissions. Your extension verifies a request of its own by calling `GatewayRequestVerifier::verify_request()`, or `SessionVerifier::verify_session()` for a form, and acting on the decision. A verification never throws, and an error during it results in Allow.
