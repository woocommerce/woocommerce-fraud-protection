# Integrating with WooCommerce Fraud Protection

This guide is for payment gateways and other WooCommerce extensions that want to integrate with Fraud Protection natively. It explains what the plugin already does, which integration your extension needs, how to declare it, and what to implement for each one. The [public API reference](api-reference.md) documents every class, hook and script that this guide uses, and the [example integrations](../examples/README.md) show complete working code.

## Do you need to integrate?

Fraud Protection verifies the standard WooCommerce flows by itself: the block and shortcode checkouts, the "Pay for order" page, and the My Account "Add payment method" page. A gateway that takes payment only through `process_payment()` on those flows is already covered and needs no integration. Describing its payment instrument, though, gives each verification better data (see [Payment data](#payment-data)).

An extension needs an integration when it moves part of a payment outside those flows. For example, its buttons may place an order through their own request, or it may save a card before the form is submitted. Fraud Protection ships compatibility layers that cover these flows for WooPayments, Stripe, PayPal Payments, Square and WooCommerce Subscriptions from the outside. A native integration does the same work inside the extension. When the extension declares it, the matching compatibility layer stands aside.

Three rules apply to every integration:

- **Fraud Protection is optional.** Register your integration only when `class_exists( SessionVerifier::class )` is true, and declare features with literal strings rather than the plugin's constants, so your extension keeps working without it.
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

Most integrations combine the same four steps. The sections for each integration show the part specific to it.

### 1. Load the browser scripts

Request the shared scripts from the hook that renders your payment surface, then enqueue your own script with `wc-fraud-protection-blackbox-init` as a dependency. Enqueue your script only when the request succeeds:

```php
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;

add_action(
	'my_gateway_payment_surface_render',
	function (): void {
		if ( ! wc_get_container()->get( BlackboxScriptHandler::class )->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'my-gateway-fraud-protection',
			plugins_url( 'js/fraud-protection.js', __FILE__ ),
			array( 'wc-fraud-protection-blackbox-init' ),
			'1.0.0',
			array( 'in_footer' => true )
		);
	}
);
```

`request_scripts()` returns `false` when the store is not connected to Jetpack, or when the request cannot render a payment surface. Your requests then go out without a session ID and are still verified.

### 2. Send the session ID

In your script, acquire a session ID right before sending the request. Add it under the field name in `config.sessionIdField`, then reset for the next attempt. Check for the methods when you call them, not when your script loads, because they are attached only once the SDK has loaded:

```js
function withSessionId( body ) {
	const fp = window.wcFraudProtection;

	if ( ! fp || typeof fp.acquireSessionId !== 'function' ) {
		return Promise.resolve( body ); // Fail open.
	}

	return fp.acquireSessionId().then( function ( sessionId ) {
		body[ fp.config.sessionIdField ] = sessionId;
		return body;
	} );
}

withSessionId( { action: 'my_gateway_create_order' } ).then( function ( body ) {
	// Send the request, then start a fresh session for the next attempt.
	const fp = window.wcFraudProtection;
	if ( fp && typeof fp.reset === 'function' ) {
		fp.reset();
	}
} );
```

A code path that must add the ID synchronously uses `prepareSessionId()` and `takePreparedSessionId()` instead; see [Express checkout](#express-checkout).

### 3. Verify the request on the server

Pass the submitted session ID to `SessionVerifier::verify_session()` before you act on the request. Stop on a Block decision with the standard message:

```php
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

$verifier = wc_get_container()->get( SessionVerifier::class );
$decision = $verifier->verify_session(
	wp_unslash( $_POST[ SessionVerifier::SESSION_ID_FIELD ] ?? '' ),
	'my_gateway_create_order',
	0,
	array(
		'payment_method' => 'my_gateway',
		'payment_data'   => array(),
	)
);

if ( FraudDecision::Block === $decision ) {
	wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ) ), 403 );
}
```

The second argument identifies your flow: a stable ASCII string of 32 characters or fewer. Use `MessageContext::Purchase` in purchase flows and `MessageContext::Generic` elsewhere, and `get_html()` instead of `get_plaintext()` for a notice rendered in a page.

### 4. Supply the decision to the final submission

When your request comes before a WooCommerce submission that the plugin also verifies, such as the checkout that completes a purchase your request started, give that submission the decision your request received, so the attempt is scored only once.

Keep a record in the WooCommerce session with the session ID that the verification resolved (`last_verified_session_id()`, not the one the browser sent). Also store a reference that only your server creates in that request, such as the ID of the order or setup intent at your payment provider:

```php
function my_gateway_remember_decision( string $session_id, FraudDecision $decision, string $reference ): void {
	if ( ! WC()->session ) {
		return;
	}

	WC()->session->set(
		'my_gateway_fraud_decision',
		'' === $session_id ? null : array(
			'session_id' => $session_id,
			'decision'   => $decision->value,
			'reference'  => $reference,
		)
	);
}
```

Then supply the record, at most once, to the submission that carries the same reference:

```php
use Automattic\WooCommerce\FraudProtection\SuppliedDecision;

add_filter(
	'woocommerce_fraud_protection_skip_session_verify',
	function ( $supplied, $source, $request_data ) {
		if (
			false !== $supplied
			|| ! is_array( $request_data )
			|| ! in_array( $source, array( 'blocks_checkout', 'shortcode_checkout', 'pay_for_order' ), true )
			|| 'my_gateway' !== ( $request_data['payment_method'] ?? '' )
			|| ! WC()->session
		) {
			return $supplied;
		}

		$record = WC()->session->get( 'my_gateway_fraud_decision' );
		WC()->session->set( 'my_gateway_fraud_decision', null ); // One use only.

		$reference = $request_data['payment_data']['my_gateway_order_id'] ?? '';
		$decision  = is_string( $record['decision'] ?? null ) ? FraudDecision::tryFrom( $record['decision'] ) : null;

		if (
			! in_array( $decision, FraudDecision::ACTIONABLE, true )
			|| '' === $reference
			|| ( $record['reference'] ?? '' ) !== $reference
		) {
			return $supplied;
		}

		return new SuppliedDecision( $decision, $record['session_id'] );
	},
	10,
	3
);
```

The `$source` values in the example are the ones the plugin's own checkout and Pay for order verifications pass; the "Add payment method" form passes `add_payment_method`. Return the incoming value whenever you have nothing to supply, so other callbacks and normal verification still work.

## Integrations

### Payment data

**Declare:** `fraud_protection_payment_data` in the gateway's `$supports`.

**Implement:** hook `woocommerce_fraud_protection_resolved_payment_data` and return a `PaymentMethodData` that describes your own attempts. Return the incoming value unchanged for other gateways:

```php
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentInstrumentData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMethodData;
use Automattic\WooCommerce\FraudProtection\Schemas\PaymentMode;

add_filter(
	'woocommerce_fraud_protection_resolved_payment_data',
	function ( $resolved, $payment_fields ) {
		if ( ! $resolved instanceof PaymentMethodData || 'my_gateway' !== $resolved->get_gateway() || ! is_array( $payment_fields ) ) {
			return $resolved;
		}

		$instrument = PaymentInstrumentData::from_array(
			array(
				'brand' => $payment_fields['my_gateway_card_brand'] ?? null,
				'last4' => $payment_fields['my_gateway_card_last4'] ?? null,
			)
		);

		return new PaymentMethodData( 'my_gateway', 'card', false, $instrument, PaymentMode::Live, 'acct_123', 'account' );
	},
	10,
	2
);
```

`$payment_fields` holds the gateway fields submitted with the attempt, or the `payment_data` you passed to `verify_session()`. `$resolved` is pre-filled from the WooCommerce payment token when the shopper pays with a saved method.

### Checkout requests

**Declare:** `fraud_protection_checkout` in the gateway's `$supports`.

**Implement:** for each request that places or authorizes a purchase before or instead of the WooCommerce checkout or Pay for order submission, such as a create-order call:

1. [Load the browser scripts](#1-load-the-browser-scripts) on the product, cart, checkout and Pay for order pages where your buttons render.
2. [Send the session ID](#2-send-the-session-id) with the request.
3. [Verify it](#3-verify-the-request-on-the-server) and stop on a Block decision with `MessageContext::Purchase`.
4. [Supply the decision](#4-supply-the-decision-to-the-final-submission) to the checkout or Pay for order submission that completes the purchase.

```php
add_action(
	'wc_ajax_my_gateway_create_order',
	function (): void {
		check_ajax_referer( 'my-gateway-create-order', 'security' );

		$verifier = wc_get_container()->get( SessionVerifier::class );
		$decision = $verifier->verify_session(
			wp_unslash( $_POST[ SessionVerifier::SESSION_ID_FIELD ] ?? '' ),
			'my_gateway_create_order',
			0,
			array( 'payment_method' => 'my_gateway', 'payment_data' => array() )
		);

		if ( FraudDecision::Block === $decision ) {
			wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ) ), 403 );
		}

		$provider_order_id = my_gateway_create_provider_order(); // Your own call.
		my_gateway_remember_decision( $verifier->last_verified_session_id(), $decision, $provider_order_id );

		wp_send_json_success( array( 'order_id' => $provider_order_id ) );
	}
);
```

The checkout that follows then posts `my_gateway_order_id`, and the filter from step 4 supplies the decision to it.

### Add payment method requests

**Declare:** `fraud_protection_add_payment_method` in the gateway's `$supports`.

**Implement:** the same steps as [Checkout requests](#checkout-requests), for each request that checks or saves a payment method outside a purchase, such as creating a setup intent. These requests come from the My Account "Add payment method" form and from the WooCommerce Subscriptions change-payment-method form. Block with `MessageContext::Generic`, and supply the decision to the form submission (source `add_payment_method`):

```php
add_action(
	'wc_ajax_my_gateway_create_setup_intent',
	function (): void {
		check_ajax_referer( 'my-gateway-setup-intent', 'security' );

		$verifier = wc_get_container()->get( SessionVerifier::class );
		$decision = $verifier->verify_session(
			wp_unslash( $_POST[ SessionVerifier::SESSION_ID_FIELD ] ?? '' ),
			'my_gateway_setup_intent',
			0,
			array( 'payment_method' => 'my_gateway', 'payment_data' => array() )
		);

		if ( FraudDecision::Block === $decision ) {
			wp_send_json_error( array( 'message' => ( new BlockedSessionMessage() )->get_plaintext( MessageContext::Generic ) ), 403 );
		}

		$setup_intent_id = my_gateway_create_setup_intent(); // Your own call.
		my_gateway_remember_decision( $verifier->last_verified_session_id(), $decision, $setup_intent_id );

		wp_send_json_success( array( 'setup_intent' => $setup_intent_id ) );
	}
);
```

PayPal Payments creates setup tokens both at checkout and when a payment method is saved. Its setup-token compatibility layer therefore stands aside only when both `fraud_protection_checkout` and `fraud_protection_add_payment_method` are declared, so PayPal Payments must declare the two together.

### Express checkout

**Declare:** `fraud_protection_express_checkout` in the gateway's `$supports`.

**Implement:** add the session ID to the Store API checkout request your express buttons send. Put it in the request's extension data, under the `woocommerce/fraud-protection` namespace as `blackbox_session_id`. The plugin verifies that request like any other Store API checkout, so there is nothing to do on the server.

Express buttons usually build their request in a synchronous callback. Prepare a session ID when the buttons are set up, and take it when the request is built. Each taken ID is used once, and a fresh session starts automatically, so there is no `reset()` call:

```js
const fp = window.wcFraudProtection;
const canPrepare =
	fp &&
	typeof fp.prepareSessionId === 'function' &&
	typeof fp.takePreparedSessionId === 'function';

// When the express buttons are set up:
if ( canPrepare ) {
	fp.prepareSessionId();
}

// When the Store API checkout request is built:
function addFraudProtectionData( extensions ) {
	const data = Object.assign( {}, extensions );
	delete data[ 'woocommerce/fraud-protection' ]; // Never resend an earlier attempt's ID.

	const sessionId = canPrepare ? fp.takePreparedSessionId() : '';
	if ( sessionId ) {
		data[ 'woocommerce/fraud-protection' ] = { blackbox_session_id: sessionId };
	}

	return data;
}
```

Load the browser scripts on every page where the buttons render, as in [step 1](#1-load-the-browser-scripts). If your button code can wait for a promise, `acquireSessionId()` followed by `reset()` works too.

### Change payment method forms

**Declare:** `change_payment_method` under your plugin slug, through `woocommerce_fraud_protection_native_integrations` (see [Other extensions](#other-extensions)).

**Implement:** for an extension that handles its own change-payment-method form, rendered as WooCommerce's `#order_review` pay form:

1. Call `request_pay_for_order_script()` from the code that renders the form. The script it loads holds the form submission until it has added the session ID as a hidden field.
2. On submission, after your own nonce and permission checks, read the form with `ClassicFormRequestData` and verify it before changing anything.
3. On a Block decision, stop the change and show the standard message with `MessageContext::Generic`.

```php
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;
use Automattic\WooCommerce\FraudProtection\ClassicFormRequestData;

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
