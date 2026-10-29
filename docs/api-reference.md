# Fraud Protection public API reference

This is the reference for every public part of WooCommerce Fraud Protection: the PHP classes, the hooks, the browser API and script handles, and the request fields the plugin reads. To find out which of these your extension needs, start with the [integration guide](integration-guide.md); it also holds the examples for the common integration tasks, which this reference links to instead of repeating.

## Contents

- [Scope and compatibility](#scope-and-compatibility)
- [PHP services](#php-services)
    - [Obtaining the services](#obtaining-the-services)
    - [SessionVerifier](#sessionverifier)
    - [GatewayRequestVerifier](#gatewayrequestverifier)
    - [GatewayPaymentData](#gatewaypaymentdata)
    - [BlackboxScriptHandler](#blackboxscripthandler)
    - [FraudProtectionReporter](#fraudprotectionreporter)
- [PHP helpers](#php-helpers)
    - [ClassicFormRequestData](#classicformrequestdata)
    - [BlockedSessionMessage](#blockedsessionmessage)
    - [SessionIdNormalizer](#sessionidnormalizer)
- [Declarations](#declarations)
    - [FraudProtectionFeature](#fraudprotectionfeature)
    - [FraudProtectionIntegration](#fraudprotectionintegration)
- [Value objects](#value-objects)
    - [SuppliedDecision](#supplieddecision)
    - [VerifiedRequest](#verifiedrequest)
    - [PaymentMethodData](#paymentmethoddata)
    - [PaymentInstrumentData](#paymentinstrumentdata)
    - [ReportContextData](#reportcontextdata)
- [Enums](#enums)
- [Hooks](#hooks)
    - [woocommerce_fraud_protection_resolved_payment_data](#woocommerce_fraud_protection_resolved_payment_data)
    - [woocommerce_fraud_protection_skip_session_verify](#woocommerce_fraud_protection_skip_session_verify)
    - [woocommerce_fraud_protection_native_integrations](#woocommerce_fraud_protection_native_integrations)
    - [woocommerce_fraud_protection_automated_decision](#woocommerce_fraud_protection_automated_decision)
    - [woocommerce_fraud_protection_rule_applied](#woocommerce_fraud_protection_rule_applied)
    - [woocommerce_payment_gateway_supports](#woocommerce_payment_gateway_supports)
- [Browser API](#browser-api)
    - [window.wcFraudProtection](#windowwcfraudprotection)
    - [Script handles](#script-handles)
    - [Where the server reads the session ID](#where-the-server-reads-the-session-id)
- [WP-CLI](#wp-cli)

## Scope and compatibility

The public API consists of:

- the classes in `src/FraudProtection/`, under the `Automattic\WooCommerce\FraudProtection` namespace;
- the hooks listed under [Hooks](#hooks);
- the `window.wcFraudProtection` methods and `config.sessionIdField`, the script handles, and the request fields listed under [Browser API](#browser-api).

Released public API stays compatible across plugin versions. Everything in `src/Internal/` (`Automattic\WooCommerce\Internal`) is for the plugin's exclusive use and can change in any release, as can the entries of `window.wcFraudProtection.config` other than `sessionIdField`.

Fraud Protection is optional for the extensions that integrate with it. Guard each use with `class_exists()`, and use the literal feature and integration strings rather than the plugin's constants in code that must run without it.

## PHP services

### Obtaining the services

`SessionVerifier`, `GatewayRequestVerifier`, `BlackboxScriptHandler` and `FraudProtectionReporter` receive their dependencies through an `init()` method that WooCommerce's dependency-injection container calls. Always resolve them from the container; an instance created with `new` is not initialized. Resolve `GatewayPaymentData` from the container too, so your resolver reaches the instance the plugin registered:

```php
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

$verifier = wc_get_container()->get( SessionVerifier::class );
```

The container returns the same instance on every call. The other public classes are used directly: see each one below.

### SessionVerifier

Verifies a payment attempt and returns the decision to enforce. Fraud Protection's own checkout, Pay for order and "Add payment method" verifications use it, and so do extensions that verify their own requests.

#### Constants

| Constant | Value | Meaning |
|----------|-------|---------|
| `SESSION_ID_FIELD` | `wc_fraud_protection_session_id` | The request field that carries the session ID in a classic form or AJAX request. It matches `window.wcFraudProtection.config.sessionIdField`. |
| `ORDER_BLACKBOX_SESSION_ID_KEY` | `_wc_fraud_protection_session_id` | The order meta key where the plugin stores the session ID of the verification associated with an order. `FraudProtectionReporter` reads it. |

#### verify_session()

```php
public function verify_session( mixed $session_id, string $source, int $order_id = 0, array $request_data = array() ): FraudDecision
```

| Parameter | Description |
|-----------|-------------|
| `$session_id` | The session ID as submitted. Pass it as received: the verifier normalizes it, and a missing or invalid value is still verified. |
| `$source` | A stable ASCII identifier of your flow, 32 characters or fewer; longer values are shortened to 32 characters. It reaches `woocommerce_fraud_protection_skip_session_verify` callbacks and the plugin's logs. |
| `$order_id` | The order the attempt belongs to, or `0` when no order exists yet. With an order, the plugin stores the verification's session ID on it. |
| `$request_data` | `payment_method`: the gateway ID. `payment_data`: a flat map of the gateway-specific fields, passed to `woocommerce_fraud_protection_resolved_payment_data` callbacks. The whole array is also passed to `woocommerce_fraud_protection_skip_session_verify` callbacks; `ClassicFormRequestData::build_request_data()` adds `billing_address` and `shipping_address` for classic forms. |

The method returns `FraudDecision::Allow` or `FraudDecision::Block` and never throws: any error during verification returns `FraudDecision::Allow`. A decision supplied through `woocommerce_fraud_protection_skip_session_verify` is returned without verifying. Merchant rules, the `woocommerce_fraud_protection_automated_decision` filter and the automatic-protection setting are already applied to the returned decision.

The plugin's own verifications pass these sources, which `woocommerce_fraud_protection_skip_session_verify` callbacks can match:

| Source | Flow |
|--------|------|
| `blocks_checkout` | Block checkout (Store API) |
| `shortcode_checkout` | Shortcode checkout |
| `pay_for_order` | Pay for order page |
| `add_payment_method` | My Account "Add payment method" form |

A gateway verifying its own requests uses [`GatewayRequestVerifier`](#gatewayrequestverifier), which calls this method. The [integration guide](integration-guide.md#change-payment-method-forms) shows a direct call for a classic form.

#### last_verified_session_id()

```php
public function last_verified_session_id(): string
```

The session ID that the last `verify_session()` call resolved and stored, or an empty string when that call supplied a decision, failed open, or got no usable session ID. Key any record of the decision by this ID, not by the one the browser sent.

### GatewayRequestVerifier

Verifies a request that a payment gateway handles itself, such as a create-order or setup-intent call, and reuses its decision for the WooCommerce submission that completes the same attempt, so the attempt is scored once. It wraps `SessionVerifier` and the `woocommerce_fraud_protection_skip_session_verify` filter, and keeps the decision record for you. Since 0.3.1.

#### verify_request()

```php
public function verify_request( string $source, string $gateway_id, array $payment_data = array(), int $order_id = 0, mixed $session_id = null ): VerifiedRequest
```

| Parameter | Description |
|-----------|-------------|
| `$source` | A stable ASCII identifier of the request, 32 characters or fewer, as for `verify_session()`. |
| `$gateway_id` | The ID of the gateway the request pays with. |
| `$payment_data` | A flat map of the gateway fields that describe the payment instrument, passed to `woocommerce_fraud_protection_resolved_payment_data` callbacks. |
| `$order_id` | The order the request pays for, or `0` before the order exists. |
| `$session_id` | The session ID the browser sent. `null` reads it, together with the reason the browser reports for a missing ID, from the request body under `SessionVerifier::SESSION_ID_FIELD`: a form field, or a top-level property of a body sent as `application/json`. A form field wins over a JSON body. Pass it explicitly when the request carries it anywhere else, such as in a nested JSON object or a REST route parameter; otherwise the request is verified without it. |

Returns a [`VerifiedRequest`](#verifiedrequest). Like `verify_session()`, it never throws, and errors produce `FraudDecision::Allow`. Check your nonce and permissions before calling it.

#### register_reference_reader()

```php
public function register_reference_reader( string $gateway_id, callable $reference_reader ): void
```

Call it once while your plugin loads. Before the plugin verifies a later submission paid with `$gateway_id`, such as a checkout, Pay for order or payment-method submission, it calls `$reference_reader( array $payment_data, array $request_data, string $source )`. Return the reference the submission carries, or an empty string. The submission reuses the decision kept with `VerifiedRequest::reuse_decision_for()` instead of being verified when:

- the reference matches the one passed to `reuse_decision_for()` for the gateway;
- the cart items and quantities have not changed since `reuse_decision_for()` was called (a changed total alone does not count);
- the submission's source differs from the source of the request that produced the decision.

A decision is reused at most once. A reader that throws, or returns anything but a matching string, leads to a normal verification. A later call for the same gateway replaces the reader.

```php
use Automattic\WooCommerce\FraudProtection\GatewayRequestVerifier;

wc_get_container()->get( GatewayRequestVerifier::class )->register_reference_reader(
	'my_gateway',
	fn( array $payment_data ) => $payment_data['my_gateway_order_id'] ?? ''
);
```

The [integration guide](integration-guide.md#3-verify-the-request-and-reuse-its-decision) shows the full flow.

### GatewayPaymentData

Describes the payment instrument of a gateway's attempts. It wraps the `woocommerce_fraud_protection_resolved_payment_data` filter, so the gateway's code runs only for its own attempts and cannot break other gateways' data. Since 0.3.1.

#### register_resolver()

```php
public function register_resolver( string $gateway_id, callable $resolver ): void
```

Call it once while your plugin loads. Before each verification of an attempt paid with `$gateway_id`, the plugin calls `$resolver( array $payment_fields, PaymentMethodData $resolved )`:

| Argument | Description |
|----------|-------------|
| `$payment_fields` | The flat map of gateway fields submitted with the attempt, or the `payment_data` passed to the verification. Always an array. |
| `$resolved` | The data resolved so far: from the WooCommerce payment token of a saved payment method, or a `PaymentMethodData` that only holds the gateway ID. |

Return a [`PaymentMethodData`](#paymentmethoddata) for `$gateway_id`, or `null` to keep `$resolved`. A resolver that throws, or returns another value or the data of another gateway, keeps `$resolved`, and the plugin logs a warning. A later call for the same gateway replaces the resolver. The [integration guide](integration-guide.md#payment-data) shows a resolver that builds the data from the payment fields.

### BlackboxScriptHandler

Loads the shared browser scripts on a payment surface.

#### request_scripts()

```php
public function request_scripts(): bool
```

Enqueues the Blackbox SDK and the `wc-fraud-protection-blackbox-init` script, which sets up `window.wcFraudProtection`. Call it from the hook that renders your payment surface, and enqueue your own script with `wc-fraud-protection-blackbox-init` as a dependency only when it returns `true`. Calling it again in the same request returns `true` without enqueuing anything twice.

It returns `false` when the store has no Jetpack blog ID, and in requests that do not render a payment surface: admin pages, REST requests, post previews by an editor, the Customizer preview and the order-received page. The [integration guide](integration-guide.md#1-load-the-browser-scripts) shows the call.

#### request_pay_for_order_script()

```php
public function request_pay_for_order_script(): bool
```

Calls `request_scripts()` and also enqueues `wc-fraud-protection-pay-for-order`. That script holds the submission of the page's `#order_review` form until it has added the session ID as a hidden `SESSION_ID_FIELD` field. Read it on the server with `ClassicFormRequestData`. Returns `false`, enqueuing nothing, when `request_scripts()` does. Since 0.3.1.

### FraudProtectionReporter

Reports what happened to a payment after its verification, such as a capture, a decline, a dispute, a refund or a review outcome, so the event is correlated with the original verification.

```php
public function report( \WC_Order $order, ReportSource $source, string $report_id, ?ReportContextData $context, ?\DateTimeInterface $occurred_at = null, string $notes = '' ): void
```

| Parameter | Description |
|-----------|-------------|
| `$order` | The order the event belongs to. It must carry a session ID from an earlier verification (`SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY`); otherwise the report is skipped. |
| `$source` | Where the event comes from: a `ReportSource` case. |
| `$report_id` | Your idempotency key for the event: a non-empty string of 255 characters or fewer, reused when you send the same event again. An invalid value skips the report. |
| `$context` | The event, built with `ReportContextData::from_array()`. `null`, which `from_array()` returns for an event it cannot map, skips the report. |
| `$occurred_at` | When the event happened; now when `null`. |
| `$notes` | Free-form notes. Do not include raw gateway or customer data. |

The method never throws, and does nothing while the plugin is disabled. Report from your gateway's webhook or order-status handlers:

```php
use Automattic\WooCommerce\FraudProtection\FraudProtectionReporter;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportContextData;
use Automattic\WooCommerce\FraudProtection\Schemas\ReportSource;

$context = ReportContextData::from_array(
	array(
		'type'                       => 'dispute',
		'result'                     => 'open',
		'reason'                     => 'fraud',
		'amount_minor_units'         => 4999,
		'amount_currency'            => 'USD',
		'correlation_dispute_id'     => $dispute_id,
		'correlation_transaction_id' => $charge_id,
	)
);

wc_get_container()->get( FraudProtectionReporter::class )->report(
	$order,
	ReportSource::Chargeback,
	'my_gateway:' . $dispute_id . ':open',
	$context,
	new \DateTimeImmutable( '@' . $dispute_created_at )
);
```

## PHP helpers

### ClassicFormRequestData

Reads a classic (non-Store API) form submission for `SessionVerifier::verify_session()`. It is stateless: create it with `new`. Check the nonce before calling it. Since 0.3.1.

| Method | Description |
|--------|-------------|
| `get_submitted_session_id(): mixed` | The `SESSION_ID_FIELD` value from the POST body, unslashed, or an empty string when the field is absent. Pass it to `verify_session()` as is. |
| `build_request_data( array $form_data ): array` | The request data for `verify_session()`. `payment_method` and the `billing_` and `shipping_` addresses come from `$form_data`; `payment_data` holds the POST fields that are not WooCommerce checkout or account fields. The result can carry internal diagnostic keys; pass it on unchanged. |

The [integration guide](integration-guide.md#change-payment-method-forms) shows both methods in a change-payment-method form.

### BlockedSessionMessage

The message to show when a decision is Block. It does not mention fraud detection, and it gives the store's email address for support when the store has one. Create it with `new`.

| Method | Use it for |
|--------|------------|
| `get_html( MessageContext $context = MessageContext::Generic ): string` | Notices rendered in a page, such as `wc_add_notice()`. |
| `get_plaintext( MessageContext $context = MessageContext::Generic ): string` | JSON and API responses, `WP_Error` messages and Store API exceptions. |

Pass `MessageContext::Purchase` in checkout, cart and other purchase flows, and `MessageContext::Generic` when adding or changing a payment method. In a Store API extension, throw a `RouteException` with the plaintext message:

```php
use Automattic\WooCommerce\FraudProtection\BlockedSessionMessage;
use Automattic\WooCommerce\FraudProtection\MessageContext;
use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;

throw new RouteException(
	'my_extension_blocked',
	( new BlockedSessionMessage() )->get_plaintext( MessageContext::Purchase ),
	403
);
```

### SessionIdNormalizer

Converts a session ID value from an untrusted source into the form `verify_session()` sends. `verify_session()` and the plugin's own code use it; an integration that passes the submitted value to `verify_session()` does not need it.

| Method | Description |
|--------|-------------|
| `normalize( mixed $value ): string` | A string of letters, digits, `_` and `-` (shortened to 255 characters), or a marker that records why the value is invalid. Since 0.1.8. |
| `normalize_stored( mixed $value ): string` | Like `normalize()`, but returns an empty string for an invalid value. Since 0.1.8. |
| `is_invalid_marker( string $value ): bool` | Whether `$value` is one of the invalid markers. Since 0.1.8. |

## Declarations

### FraudProtectionFeature

The feature strings a payment gateway adds to its `$supports` array to declare a native integration. Each one switches off the matching compatibility layer for that gateway. Use the literal strings in your gateway; the constants are for code that runs with the plugin. Since 0.3.1.

| Constant | String | The gateway commits to | Stands aside |
|----------|--------|------------------------|--------------|
| `PAYMENT_DATA` | `fraud_protection_payment_data` | Returning the payment type, instrument, transaction mode and merchant identifier for its own attempts through `woocommerce_fraud_protection_resolved_payment_data`. | The built-in payment-data resolver for the gateway. |
| `CHECKOUT` | `fraud_protection_checkout` | Loading the browser scripts on its product, cart, checkout and Pay for order surfaces; sending the session ID with the requests that place or authorize a purchase outside the WooCommerce checkout submission; verifying them; blocking on a Block decision; and supplying the decision to the final checkout through `woocommerce_fraud_protection_skip_session_verify`. | The PayPal Payments request, script and decision-reuse layers on purchase surfaces. |
| `ADD_PAYMENT_METHOD` | `fraud_protection_add_payment_method` | The same, for the requests that check or save a payment method on the My Account "Add payment method" form and the WooCommerce Subscriptions change-payment-method form, with the `MessageContext::Generic` message, and supplying the decision to the "Add payment method" form submission. | The Stripe setup-intent layer, and the PayPal Payments script layer on those forms. |
| `EXPRESS_CHECKOUT` | `fraud_protection_express_checkout` | Adding the session ID to the Store API checkout request of its express payment buttons. | The built-in express checkout script for the gateway. |
| `ALL` | — | Every feature above. | — |

The PayPal Payments setup-token layer stands aside only when both `fraud_protection_checkout` and `fraud_protection_add_payment_method` are declared, because the request does not tell a purchase from a saved payment method. With only one of them, the plugin stops adding the session ID on that feature's surfaces but still verifies setup-token requests there, without it.

For WooPayments, Stripe, PayPal Payments and Square, which register one gateway instance per payment method, the plugin asks the gateway of the current attempt first and then the family's main gateway (`woocommerce_payments`, `stripe`, `ppcp-gateway`, `square_credit_card`). Code that runs before a gateway is selected, such as script loading, asks only the main gateway. The plugin asks inside its hook callbacks, after WordPress `init`, and does not check that a declaring gateway implements the feature.

### FraudProtectionIntegration

The integration strings an extension that is not a payment gateway declares under its own plugin slug, through [`woocommerce_fraud_protection_native_integrations`](#woocommerce_fraud_protection_native_integrations). Since 0.3.1.

| Constant | String | The extension commits to |
|----------|--------|--------------------------|
| `CHANGE_PAYMENT_METHOD` | `change_payment_method` | Loading the browser scripts on its change-payment-method form, verifying each submission before the payment method changes, and stopping the change on a Block decision. |
| `ALL` | — | Every integration above. |

The compatibility layer for the WooCommerce Subscriptions change-payment-method form stands aside when `woocommerce-subscriptions` declares `change_payment_method`.

## Value objects

### SuppliedDecision

An earlier decision that a [`woocommerce_fraud_protection_skip_session_verify`](#woocommerce_fraud_protection_skip_session_verify) callback returns. Since 0.1.9.

```php
new SuppliedDecision( FraudDecision $decision, ?string $session_id_for_order = null )
```

`$decision` is the decision the earlier verification returned; only `FraudDecision::Allow` and `FraudDecision::Block` are applied. `$session_id_for_order` is the session ID that verification resolved (`SessionVerifier::last_verified_session_id()`), which the plugin stores on the order being verified, or `null` when it has none. Never use a session ID submitted by the browser. Both properties are public and read-only.

### VerifiedRequest

The result of `GatewayRequestVerifier::verify_request()`. Since 0.3.1.

| Method | Description |
|--------|-------------|
| `get_decision(): FraudDecision` | `FraudDecision::Allow` or `FraudDecision::Block`. |
| `is_blocked(): bool` | `true` for a Block decision: stop the request with the [`BlockedSessionMessage`](#blockedsessionmessage). |
| `reuse_decision_for( string $reference ): bool` | Keeps the decision for reuse by the submission that completes the attempt, matched by `$reference`: a value only your server created while handling this request, such as the ID of the order or setup intent at your payment provider. Each gateway keeps one reusable decision per customer session; a new one replaces the previous one. Returns `false`, keeping nothing, when the reference is empty or the verification resolved no session ID, for example because it failed open; the submission is then verified normally. |

Obtain it from `verify_request()`; its constructor is internal.

### PaymentMethodData

The payment data of an attempt, as passed to and returned from [`woocommerce_fraud_protection_resolved_payment_data`](#woocommerce_fraud_protection_resolved_payment_data). Immutable.

```php
new PaymentMethodData(
	string $gateway,
	?string $payment_type = null,
	bool $is_saved_payment_method = false,
	?PaymentInstrumentData $instrument = null,
	PaymentMode $transaction_mode = PaymentMode::Unknown,
	?string $merchant_identifier = null,
	?string $merchant_identifier_type = null
)
```

| Parameter | Description |
|-----------|-------------|
| `$gateway` | The WooCommerce gateway ID. |
| `$payment_type` | The payment method type at the provider, such as `card`, `sepa_debit`, `ideal` or `link`. |
| `$is_saved_payment_method` | Whether the shopper pays with a saved payment method. |
| `$instrument` | The instrument details; an empty instrument when `null`. |
| `$transaction_mode` | Whether the gateway runs in test or live mode. |
| `$merchant_identifier`, `$merchant_identifier_type` | The merchant's account or location at the provider, and its type. The built-in layers use `account` for PayPal, Stripe and WooPayments and `location` for Square; other gateways can use other strings. When only the type is known, pass `null` as the identifier: the type is still sent. |

| Method | Description |
|--------|-------------|
| `get_gateway(): string` | The gateway ID. |
| `get_instrument_wallet(): ?string` | The instrument's wallet, or `null` when it has none. Since 0.2.7. |
| `with_transaction_mode( PaymentMode $mode ): self` | A copy with another transaction mode. |
| `with_merchant_identifier( ?string $identifier, ?string $type ): self` | A copy with another merchant identifier and type. |
| `with_instrument_wallet( string $wallet ): self` | A copy with another wallet. Since 0.2.7. |
| `with_instrument_wallet_if_empty( ?string $wallet ): self` | A copy with the wallet, only when the instrument has none. Since 0.2.7. |
| `to_array(): array` | The data as an array. |

The wallet is an open string. The built-in gateway layers use `woopay`, `apple_pay`, `google_pay`, `amazon_pay`, `paypal`, `venmo`, `link`, `cash_app_pay`, `alipay`, `grabpay` and `wechat_pay`; use the same values where they apply.

Use the `with_*()` methods to add to data that the payment token already resolved, without losing it:

```php
wc_get_container()->get( GatewayPaymentData::class )->register_resolver(
	'my_gateway',
	fn( array $payment_fields, PaymentMethodData $resolved ) => $resolved
		->with_transaction_mode( my_gateway_is_test_mode() ? PaymentMode::Test : PaymentMode::Live )
		->with_merchant_identifier( my_gateway_account_id(), 'account' )
);
```

### PaymentInstrumentData

The payment instrument part of `PaymentMethodData`. Immutable. Build it with a factory; the constructor is private:

- `PaymentInstrumentData::from_array( array $data = array() ): self` reads these optional keys and ignores any other:
    - strings: `brand`, `funding`, `last4`, `fingerprint`, `country`, `billing_postcode`, `wallet`, `payer_email`, `bank_code`, `bin`;
    - integers: `exp_month`, `exp_year`;
    - `CheckResult` cases or their string values: `cvc_check`, `avs_address_check`, `avs_postcode_check`.
- `PaymentInstrumentData::empty(): self` returns an instrument with no data.

A value of the wrong type is converted when possible and dropped otherwise, and the problem is logged, so untrusted gateway data can be passed in directly. `to_array()` returns every key, with `null` for missing values.

### ReportContextData

The event that [`FraudProtectionReporter::report()`](#fraudprotectionreporter) sends. Immutable. Build it with `ReportContextData::from_array( array $data ): ?self`; the constructor is private.

| Key | Required | Value |
|-----|----------|-------|
| `type` | Yes | An `EventPhase` value: `payment`, `dispute` or `refund`. |
| `result` | Yes | A `ReportResult` value valid for the `type` (see [ReportResult](#reportresult)). |
| `reason` | No | For a declined or blocked payment, a `PaymentRefusalReason` value; for a dispute, a `DisputeReason` value. Ignored for other results and for refunds. |
| `liability_shift` | No | A `LiabilityShift` value. |
| `amount_minor_units`, `amount_currency` | No | The amount in minor units, such as cents, and its ISO 4217 currency code. |
| `gateway` | No | The gateway ID; the order's payment method when omitted. |
| `correlation_order_id` | No | The WooCommerce order ID; the reported order's ID when omitted. |
| `correlation_transaction_id`, `correlation_payment_attempt_id`, `correlation_dispute_id`, `correlation_refund_id`, `correlation_network_transaction_id` | No | The IDs of the charge, payment attempt, dispute or refund at the provider, and the card network's transaction reference. |
| `instrument` | No | An array with the `PaymentInstrumentData::from_array()` keys. |

`from_array()` returns `null`, and logs why, when `type` or `result` is missing or invalid. An invalid optional value is dropped. `SCHEMA_VERSION` holds the version of the context format.

## Enums

All the enums are string-backed, and their cases are part of the public API. In the table, `Schemas` is the `Automattic\WooCommerce\FraudProtection\Schemas` namespace and root is `Automattic\WooCommerce\FraudProtection`.

| Enum | Namespace | Cases |
|------|-----------|-------|
| `FraudDecision` | `Schemas` | `Allow` (`allow`), `Block` (`block`), `Challenge` (`challenge`) |
| `MessageContext` | root | `Purchase` (`purchase`), `Generic` (`generic`) |
| `PaymentMode` | `Schemas` | `Test` (`test`), `Live` (`live`), `Unknown` (`unknown`) |
| `CheckResult` | `Schemas` | `Pass` (`pass`), `Fail` (`fail`), `Unavailable` (`unavailable`), `Unchecked` (`unchecked`) |
| `ReportSource` | `Schemas` | `Chargeback` (`chargeback`), `ManualReview` (`manual_review`), `Api` (`api`) |
| `EventPhase` | `Schemas` | `Payment` (`payment`), `Dispute` (`dispute`), `Refund` (`refund`) |
| `LiabilityShift` | `Schemas` | `Shifted` (`shifted`), `Attempted` (`attempted`), `NotShifted` (`not_shifted`) |

`FraudDecision::ACTIONABLE` holds `Allow` and `Block`, the decisions the plugin enforces. `Challenge` is defined but not enforced: a value outside `ACTIONABLE` is treated as `Allow`. Before calling `FraudDecision::tryFrom()` on stored or submitted data, check that the value is a string.

### ReportResult

`ReportResult::for_phase( EventPhase $phase ): array` returns the cases valid for a phase:

| Phase | Results |
|-------|---------|
| `payment` | `captured`, `authorized`, `pending`, `declined`, `blocked`, `review_pending`, `review_approved`, `review_rejected`, `review_expired`, `voided`, `canceled` |
| `dispute` | `inquiry`, `open`, `won`, `lost`, `accepted`, `withdrawn`, `prevented` |
| `refund` | `refunded`, `partially_refunded` |

The payment cases are named `PaymentCaptured`, `PaymentAuthorized` and so on; the dispute cases `DisputeInquiry`, `DisputeOpen` and so on; and the refund cases `Refunded` and `PartiallyRefunded`.

### DisputeReason and PaymentRefusalReason

The `reason` of a report context.

- `DisputeReason`: `fraud`, `unrecognized`, `subscription_canceled`, `canceled_or_returned`, `product_not_received`, `product_not_as_described`, `credit_not_processed`, `duplicate`, `bank`, `other`.
- `PaymentRefusalReason`: `lost_or_stolen`, `suspected_fraud`, `restricted_card`, `security_violation`, `incorrect_cvc`, `incorrect_avs`, `incorrect_number`, `incorrect_expiry`, `do_not_honor`, `generic_decline`, `compliance`, `card_not_supported`, `unsupported_currency`, `expired_card`, `invalid_account`, `not_permitted`, `insufficient_funds`, `limit_exceeded`, `velocity_exceeded`, `authentication_required`, `issuer_unavailable`, `processing_error`, `test_mode`, `duplicate`, `request_error`, `operational`.

The case names are the values in PascalCase, such as `DisputeReason::ProductNotReceived` and `PaymentRefusalReason::DoNotHonor`.

## Hooks

A callback that throws in one of the plugin's hooks does not break the flow: the plugin logs the error and continues with the safe default that each hook describes. Treat every value a hook passes you as untrusted, because other callbacks may have changed it, and return the value you received when you have nothing to change.

### woocommerce_fraud_protection_resolved_payment_data

Filter. Describes the payment of an attempt.

```php
apply_filters( 'woocommerce_fraud_protection_resolved_payment_data', PaymentMethodData $resolved, array $payment_fields );
```

`$resolved` starts as the data resolved from the WooCommerce payment token of a saved payment method, or as a `PaymentMethodData` that only holds the gateway ID. `$payment_fields` is the flat map of gateway fields of the attempt: the `payment_data` passed to `SessionVerifier::verify_session()`. Return a `PaymentMethodData`. Any other value, or a callback that throws, falls back to the initial value. Since 0.1.0.

A gateway that resolves its own data declares `fraud_protection_payment_data` and registers a resolver with [`GatewayPaymentData`](#gatewaypaymentdata), which hooks this filter for it. Hook the filter directly only to change the data of attempts paid with other gateways.

### woocommerce_fraud_protection_skip_session_verify

Filter. Supplies the decision of an earlier verification in the same payment attempt, so the attempt is not verified twice.

```php
apply_filters( 'woocommerce_fraud_protection_skip_session_verify', SuppliedDecision|false $supplied_decision, string $source, array $request_data, string $session_id );
```

| Parameter | Description |
|-----------|-------------|
| `$supplied_decision` | `false`, or the decision an earlier callback supplied. |
| `$source` | The `$source` of the `verify_session()` call (see [SessionVerifier](#verify_session)). |
| `$request_data` | The `$request_data` of the `verify_session()` call. |
| `$session_id` | The normalized session ID submitted with the request being verified. |

Return a [`SuppliedDecision`](#supplieddecision) to skip verification; the decision is enforced as if the verification had returned it. Return the incoming value when you have nothing to supply. A later callback can replace your result. Any other value, a `SuppliedDecision` whose decision is not in `FraudDecision::ACTIONABLE`, or a callback that throws, verifies normally.

Supply a decision only to the attempt that received it: key your record by evidence your server produced in the earlier request, and use it once. [`GatewayRequestVerifier`](#gatewayrequestverifier) does this for a gateway's own requests; use the filter directly only for flows it does not cover. Since 0.1.0; returns a `SuppliedDecision` since 0.1.9.

### woocommerce_fraud_protection_native_integrations

Filter. Lets extensions that are not payment gateways declare native integrations. Since 0.3.1.

```php
apply_filters( 'woocommerce_fraud_protection_native_integrations', array<string, string[]> $integrations );
```

The array maps a plugin slug to the [`FraudProtectionIntegration`](#fraudprotectionintegration) strings that the extension implements. Add your entry under your own slug and return the array. The plugin applies the filter when a compatibility layer needs the answer, inside its hook callbacks. A non-array result, or a callback that throws, keeps every compatibility layer on; entries with an invalid key or value are skipped. See the [integration guide](integration-guide.md#other-extensions) for the declaration.

### woocommerce_fraud_protection_automated_decision

Filter. Changes the decision received for a verification before it is enforced, for example to always allow trusted customers. Since 0.1.0; named `woocommerce_fraud_protection_decision` before 0.1.6.

```php
apply_filters( 'woocommerce_fraud_protection_automated_decision', FraudDecision $decision, array $session_data );
```

`$decision` is `FraudDecision::Allow` or `FraudDecision::Block`; return one of the two. Any other value keeps the received decision, and so does a callback that throws. `$session_data` is the data the verification collected, including `verify_result`: an array with `risk_score` (`float|null`, informational only and subject to change, so do not build thresholds on it) and `payment_method` (the gateway ID).

The filter does not run when a merchant rule decided the verification; see `woocommerce_fraud_protection_rule_applied`. When automatic protection is turned off in the settings, a Block returned by the filter is not enforced either.

```php
use Automattic\WooCommerce\FraudProtection\Schemas\FraudDecision;

add_filter(
	'woocommerce_fraud_protection_automated_decision',
	function ( $decision ) {
		if ( FraudDecision::Block === $decision && current_user_can( 'manage_woocommerce' ) ) {
			return FraudDecision::Allow;
		}
		return $decision;
	}
);
```

### woocommerce_fraud_protection_rule_applied

Action. Fires when a merchant rule decided a verification, superseding the automated decision. Since 0.1.6.

```php
do_action( 'woocommerce_fraud_protection_rule_applied', int $rule_id, FraudDecision $applied_decision, FraudDecision $received_decision, array $session_data );
```

`$applied_decision` is the rule's action, which is enforced; `$received_decision` is the automated decision it replaced; `$session_data` has the same shape as in `woocommerce_fraud_protection_automated_decision`. A callback that throws is logged and does not change the decision.

```php
add_action(
	'woocommerce_fraud_protection_rule_applied',
	function ( $rule_id, $applied_decision ) {
		if ( FraudDecision::Block === $applied_decision ) {
			my_extension_count_rule_block( (int) $rule_id );
		}
	},
	10,
	2
);
```

### woocommerce_payment_gateway_supports

WooCommerce's own filter, applied by `WC_Payment_Gateway::supports()`. The plugin reads gateway feature declarations through `supports()`, so a callback at a late priority can force the answer. For example, a site can turn a compatibility layer back on for a gateway release whose native integration is broken:

```php
add_filter(
	'woocommerce_payment_gateway_supports',
	function ( $supports, $feature, $gateway ) {
		if ( 'fraud_protection_checkout' === $feature && 'my_gateway' === $gateway->id ) {
			return false;
		}
		return $supports;
	},
	100,
	3
);
```

## Browser API

### window.wcFraudProtection

On pages where `BlackboxScriptHandler::request_scripts()` loaded the shared scripts, `window.wcFraudProtection` holds `config`, `takeSessionId()`, `prepareSessionId()`, `takePreparedSessionId()` and `addSessionIdToExtensions()` by the time a script that depends on `wc-fraud-protection-blackbox-init` runs; without the Blackbox SDK they hand out no session ID. `acquireSessionId()` and `reset()` are added only when the SDK loaded, which it may not do, for example when a content blocker stops it. Check for each method when you call it, and send the request without a session ID when it is missing.

| Member | Description |
|--------|-------------|
| `takeSessionId(): Promise<string>` | Resolves to a session ID for one payment attempt, or to an empty string on timeout, error, or without the SDK; it never rejects. After handing out an ID, it starts a fresh session for the next attempt, so no `reset()` call is needed. Use it for code that can wait for a promise. Since 0.3.1. |
| `acquireSessionId(): Promise<string>` | Resolves to a session ID, or to an empty string on timeout or error. Call `reset()` after the attempt that used it. |
| `reset(): void` | Starts a fresh session for the next payment attempt. Call it after each attempt that used `acquireSessionId()`. |
| `prepareSessionId(): void` | For code that must add the session ID synchronously: call it once when the payment surface is set up, and a session ID is kept ready for `takePreparedSessionId()`. A prepared ID that gets too old is replaced on the shopper's next activity. Calling it again does nothing. |
| `takePreparedSessionId(): string` | Hands out the prepared session ID for one payment attempt, or an empty string when none is ready. The same ID is never returned twice, and a fresh session starts for the next attempt, so no `reset()` call is needed. |
| `addSessionIdToExtensions( extensions: object ): object` | For a Store API request whose extension data is built synchronously, such as an express button's. Returns a copy of `extensions` with the ID from `takePreparedSessionId()` under the `woocommerce/fraud-protection` namespace, as `blackbox_session_id`. It always removes a session ID already in the data, so an earlier attempt's ID is never sent again. A value that is not an object is returned unchanged. Since 0.3.1. |
| `config.sessionIdField` | The name of the request field for the session ID: `SessionVerifier::SESSION_ID_FIELD`. The other `config` entries are internal. |

A session ID belongs to one payment attempt: send each ID with exactly one request. The [integration guide](integration-guide.md#2-send-the-session-id) shows `takeSessionId()`, and its [express checkout section](integration-guide.md#express-checkout) shows `prepareSessionId()` and `addSessionIdToExtensions()`.

### Script handles

| Handle | Description |
|--------|-------------|
| `wc-fraud-protection-blackbox-init` | Sets up `window.wcFraudProtection`. Use it as a dependency of your integration script. |
| `wc-fraud-protection-pay-for-order` | Adds the session ID to the `#order_review` form on submission. Enqueue it with `BlackboxScriptHandler::request_pay_for_order_script()`. |

Your own integration script is yours to register, from your plugin's URL and with your plugin's version.

### Where the server reads the session ID

| Request | Where the session ID goes |
|---------|---------------------------|
| A classic form or AJAX request | The field named by `config.sessionIdField` (`wc_fraud_protection_session_id`). Your server code reads it and passes it to `verify_session()`. |
| A Store API checkout (`/wc/store/v1/checkout` and `/wc/store/v1/checkout/{order_id}`) | The request's extension data, under the `woocommerce/fraud-protection` namespace, as `blackbox_session_id`. The plugin reads and verifies it. |

A Store API checkout sent without the plugin's checkout scripts, such as an order placed by your own block-based payment button, carries the session ID like this. Code that builds the extension data synchronously can use `addSessionIdToExtensions()` instead:

```js
fetch( '/wp-json/wc/store/v1/checkout', {
	method: 'POST',
	headers: { 'Content-Type': 'application/json', Nonce: storeApiNonce },
	body: JSON.stringify( {
		payment_method: 'my_gateway',
		billing_address: billingAddress,
		extensions: {
			'woocommerce/fraud-protection': { blackbox_session_id: sessionId },
		},
	} ),
} );
```

## WP-CLI

`wp wc fraud-protection status` shows the plugin's local status.

`wp wc fraud-protection native-integrations list` lists the feature declarations of the registered gateways, then the integration declarations from `woocommerce_fraud_protection_native_integrations`, with one row per declared feature or integration:

| Field | Value |
|-------|-------|
| `type` | `gateway` or `extension` |
| `id` | The gateway ID, or the plugin slug the extension declares under |
| `integration` | The `FraudProtectionFeature` or `FraudProtectionIntegration` string. Unknown strings are listed too, so a misspelled declaration shows up. |

It accepts the standard `--format` (`table`, `csv`, `json`, `yaml`, `count`) and `--fields` options:

```bash
$ wp wc fraud-protection native-integrations list
+-----------+---------------------------+-----------------------------------+
| type      | id                        | integration                       |
+-----------+---------------------------+-----------------------------------+
| gateway   | fp_example                | fraud_protection_payment_data     |
| gateway   | fp_example                | fraud_protection_checkout         |
| gateway   | fp_example                | fraud_protection_express_checkout |
| extension | woocommerce-subscriptions | change_payment_method             |
+-----------+---------------------------+-----------------------------------+
```

Pass `--id` with a gateway ID or plugin slug to print only the features or integrations declared under it, one per line. The output is empty when nothing is declared under it, and `--format` and `--fields` are ignored:

```bash
$ wp wc fraud-protection native-integrations list --id=woocommerce-subscriptions
change_payment_method
```
