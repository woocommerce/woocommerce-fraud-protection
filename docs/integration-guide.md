# Integrating with WooCommerce Fraud Protection

This guide is for payment gateways and other WooCommerce extensions that want to integrate with Fraud Protection natively. It explains what the plugin already does, which integration your extension needs, how to declare it, and what to implement for each one. The [public API reference](api-reference.md) documents every class, hook and script that this guide uses, and the [example integrations](../examples/README.md) show complete working code.

## Do you need to integrate?

Fraud Protection verifies the standard WooCommerce flows by itself: the block and shortcode checkouts, the "Pay for order" page, and the My Account "Add payment method" page. A gateway that takes payment only through `process_payment()` on those flows is already covered and needs no integration. Describing its payment instrument, though, gives each verification better data (see [Payment data](#payment-data)).

An extension needs an integration when it moves part of a payment outside those flows. For example, its buttons may place an order through their own request, or it may save a card before the form is submitted. Fraud Protection ships compatibility layers that cover these flows for WooPayments, Stripe, PayPal Payments, Square and WooCommerce Subscriptions from the outside. A native integration does the same work inside the extension. When the extension declares it, the matching compatibility layer stands aside.

Three rules apply to every integration:

- **Fraud Protection is optional.** Register your integration only when the classes you use exist, for example `class_exists( GatewayRequestVerifier::class )`, which also requires Fraud Protection 0.3.1 or later, and declare features with literal strings rather than the plugin's constants, so your extension keeps working without it.
- **Fail open.** Never stop a payment because Fraud Protection is unavailable. Send a request without a session ID when you have none; the server still verifies it.
- **Declare only what you implement.** A declaration switches off a compatibility layer, so a declared but unimplemented integration leaves that flow unprotected.

## Choose your integrations

| Your extension | Declare | Section |
|----------------|---------|---------|
| Is a gateway and can describe the payment instrument of its attempts | `fraud_protection_payment_data` | [Payment data](#payment-data) |
| Is a gateway that places or authorizes a purchase through its own requests, before or instead of the checkout submission | `fraud_protection_checkout` | [Checkout requests](#checkout-requests) |
| Is a gateway that checks or saves a payment method through its own requests, outside a purchase | `fraud_protection_add_payment_method` | [Add payment method requests](#add-payment-method-requests) |
| Is a gateway with express payment buttons that place the order through the Store API | `fraud_protection_express_checkout` | [Express checkout](#express-checkout) |
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

If your plugin registers one gateway instance per payment method, declare the features on the main gateway. For the gateway families that have a compatibility layer, the plugin asks the gateway of the current attempt first and then the family's main gateway, so a declaration there covers every payment method.

### Other extensions

Any other extension declares its integrations through the `woocommerce_fraud_protection_native_integrations` filter, under its own plugin slug (its WordPress.org or WooCommerce.com slug). Add to the array you receive and return it:

```php
add_filter(
	'woocommerce_fraud_protection_native_integrations',
	function ( $integrations ) {
		if ( is_array( $integrations ) ) {
			$integrations['my-extension'][] = 'change_payment_method';
		}
		return $integrations;
	}
);
```

Declare only under your own slug: each compatibility layer checks the slug of the extension it covers.

### Check the declaration

Run `wp wc fraud-protection native-integrations list`. It lists the declarations of every gateway and extension, one row per feature or integration. Add `--id` with your gateway ID or plugin slug, as in `wp wc fraud-protection native-integrations list --id=my_gateway`, to print only your own declarations. See [WP-CLI](api-reference.md#wp-cli) in the API reference for its output and options.

## The building blocks

Most integrations combine the same three steps. The sections for each integration show the part specific to it.

### 1. Load the browser scripts

Enqueue your script as you already do, then call `request_scripts_for()` with its handle from the code that renders your payment surface:

```php
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;

add_action(
	'my_gateway_payment_surface_render',
	function (): void {
		wp_enqueue_script( 'my-gateway-checkout', plugins_url( 'js/checkout.js', __FILE__ ), array(), '1.0.0', true );

		wc_get_container()->get( BlackboxScriptHandler::class )->request_scripts_for( 'my-gateway-checkout' );
	}
);
```

`request_scripts_for()` loads the shared scripts and makes your script depend on `wc-fraud-protection-blackbox-init`, so `window.wcFraudProtection` is set up before your script runs. It changes nothing and returns `false` when the store is not connected to Jetpack, or when the request cannot render a payment surface: your script loads without the shared scripts, and your requests go out without a session ID and are still verified. Call it before the scripts are printed. A script that exists only for Fraud Protection can be enqueued only when the call returns `true`, using `request_scripts()` first and adding the dependency yourself.

### 2. Send the session ID

In your script, take a session ID with `takeSessionId()` right before sending the request, and add it under the field name in `config.sessionIdField`:

```js
async function addSessionId( body ) {
	const fp = window.wcFraudProtection;
	if ( fp && typeof fp.takeSessionId === 'function' ) {
		body[ fp.config.sessionIdField ] = await fp.takeSessionId();
	}
	return body;
}

const body = await addSessionId( { action: 'my_gateway_create_order' } );
// Send the request with body.
```

`takeSessionId()` never rejects. It resolves to an empty string when there is no session ID, for example when a content blocker stopped the SDK; send the request anyway. It also starts a fresh session for the next attempt, so there is no `reset()` call. The check covers pages where the shared scripts were not loaded, and Fraud Protection versions before 0.3.1.

A code path that must add the ID synchronously uses `prepareSessionId()` and `takePreparedSessionId()` instead; see [Express checkout](#express-checkout).

### 3. Verify the request and reuse its decision

Verify each request your gateway handles itself with `GatewayRequestVerifier::verify_request()` before you act on it, and stop it on a Block decision with the standard message. When the request comes before a WooCommerce submission that the plugin also verifies, such as the checkout that completes a purchase your request started, reuse the decision for that submission, so the attempt is scored only once:

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

The first argument identifies your flow: a stable ASCII string of 32 characters or fewer. `verify_request()` reads the session ID from the request body, under the field name in `config.sessionIdField`: a form field, or a top-level property of a JSON body. When your request carries it anywhere else, such as in a nested JSON object, a header or a REST route parameter, pass the session ID you received as the fifth argument; otherwise the request is verified without it. Use `MessageContext::Purchase` in purchase flows and `MessageContext::Generic` elsewhere, and `get_html()` instead of `get_plaintext()` for a notice rendered in a page.

The reference you pass to `reuse_decision_for()` must be a value that only your server created while handling this request, such as the ID of the order or setup intent at your payment provider. The browser gets it from your response, and the final submission posts it back. Tell the plugin where to find it in that submission, once, while your plugin loads:

```php
wc_get_container()->get( GatewayRequestVerifier::class )->register_reference_reader(
	'my_gateway',
	fn( array $payment_data ) => $payment_data['my_gateway_order_id'] ?? ''
);
```

The next submission paid with your gateway that carries the reference, with the same cart items, reuses the decision instead of being verified, once. Any other submission is verified normally. The [API reference](api-reference.md#gatewayrequestverifier) has the exact rules, and the lower-level [`SessionVerifier`](api-reference.md#sessionverifier) and [`woocommerce_fraud_protection_skip_session_verify`](api-reference.md#woocommerce_fraud_protection_skip_session_verify) for flows it does not cover.

## Integrations

### Payment data

**Declare:** `fraud_protection_payment_data` in the gateway's `$supports`.

**Implement:** while your plugin loads, register a resolver with `GatewayPaymentData::register_resolver()`. It returns a `PaymentMethodData` that describes your gateway's attempts:

```php
use Automattic\WooCommerce\FraudProtection\GatewayPaymentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

wc_get_container()->get( GatewayPaymentData::class )->register_resolver(
	'my_gateway',
	function ( array $payment_fields, PaymentMethodData $resolved ): PaymentMethodData {
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

The plugin calls the resolver only for attempts paid with your gateway. `$payment_fields` holds the gateway fields submitted with the attempt, or the payment data you passed to `verify_request()`. `$resolved` is pre-filled from the WooCommerce payment token when the shopper pays with a saved method. Return `null` to keep it. If the resolver throws, or returns anything other than a `PaymentMethodData` for your gateway, the plugin keeps `$resolved`.

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

### Add payment method requests

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

PayPal Payments creates setup tokens both at checkout and when a payment method is saved. Its setup-token compatibility layer therefore stands aside only when both `fraud_protection_checkout` and `fraud_protection_add_payment_method` are declared, so PayPal Payments must declare the two together.

### Express checkout

**Declare:** `fraud_protection_express_checkout` in the gateway's `$supports`.

**Implement:** add the session ID to the Store API checkout request your express buttons send. Put it in the request's extension data, under the `woocommerce/fraud-protection` namespace as `blackbox_session_id`. The plugin verifies that request like any other Store API checkout, so there is nothing to do on the server.

Express buttons usually build their request in a synchronous callback. Prepare a session ID when the buttons are set up, and add it with `addSessionIdToExtensions()` when the request's extension data is built:

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

`addSessionIdToExtensions()` returns a copy of the extension data with the prepared session ID under the plugin's namespace. It always removes a session ID already in the data, so an earlier attempt's ID is never sent again, and it starts a fresh session for the next attempt, so there is no `reset()` call. Pass an object, `{}` when your request has no other extension data; any other value is returned unchanged. When no session ID is ready, the request goes out without one. The check covers pages where the shared scripts were not loaded, and Fraud Protection versions before 0.3.1.

Load the browser scripts on every page where the buttons render, as in [step 1](#1-load-the-browser-scripts). If your button code can wait for a promise, use `takeSessionId()` as in [step 2](#2-send-the-session-id) instead.

### Change payment method forms

**Declare:** `change_payment_method` under your plugin slug, through `woocommerce_fraud_protection_native_integrations` (see [Other extensions](#other-extensions)).

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

WooCommerce Subscriptions is the extension this integration exists for: the plugin's compatibility layer for its form stands aside when `woocommerce-subscriptions` declares `change_payment_method`.

## Test your integration

- Load the [example integrations](../examples/README.md) in the local store to compare their behavior with yours.
- Run `wp wc fraud-protection native-integrations list` and check that it lists your declarations.
- Watch the `woo-fraud-protection` log under WooCommerce > Status > Logs while you go through your flows. Each decision is logged with the source you passed to `verify_session()`.
- Go through each flow once without a Jetpack connection, or with the Fraud Protection scripts blocked in the browser, and check that payments still complete.

## Next steps

A gateway can also report payment outcomes, such as captures, disputes and refunds, for orders that went through a verification. See `FraudProtectionReporter` in the [public API reference](api-reference.md#fraudprotectionreporter).
