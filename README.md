## Requirements

- PHP 8.1+
- Node.js 20
- Composer
- MySQL (for the PHP test suite)

## Installing dependencies

```bash
composer install
npm install
```

## Build

```bash
npm run build           # development build of the JS/CSS assets
npm run build:release   # production build plus a distributable plugin zip
```

The assets in `assets/` are served as-is, so a build step is not required for everyday development.

## Local dev site (wp-env)

With Docker running, start a test store for the current checkout or worktree:

```bash
npm run env:start
```

Open the store URL reported by `env:start` (default: http://localhost:8888). Add `/wp-admin` for the admin (`admin` / `password`). The first start seeds a test store. Later starts keep its data.

```bash
npm run env:stop      # stop the containers (keeps data)
npm run env:destroy   # remove the containers and data
```

Each worktree gets separate containers. To run several at once, add a gitignored `.wp-env.override.json` to each worktree with unique ports:

```json
{ "port": 8890, "testsPort": 8891 }
```

Live service testing is disabled by default. Local UI, checkout, hook, and fail-open testing still work.

### Live service testing

Connect the environment only when you need an end-to-end test.

> **Warning:** This creates a real WordPress.com blog and sends test traffic to the production fraud service. Use test data only. Change the default user passwords before you open the public tunnel, and close the tunnel after connecting.

Give a durable environment its own hostname. A temporary environment can reuse a hostname after the previous environment is disconnected or no longer needed. Connecting another environment through the same hostname replaces the first connection; the first environment then fails open, so a live test can appear to pass without a live verdict.

1. Start the environment and change the admin and customer passwords:

   ```bash
   npm run env:start
   npm run env -- run cli wp user update admin customer --user_pass='<temporary-strong-password>'
   ```

2. Open a public HTTPS tunnel to the port reported by `env:start` using a tool like Jurassic Tube. It must preserve the public hostname in the `Host` header.

3. Connect the site with the tunnel origin. Use `confirm-unused-hostname` only after you confirm that the hostname has never connected another site.

   ```bash
   npm run env -- run cli wp --user=1 eval-file \
     "wp-content/plugins/$(basename "$PWD")/bin/jetpack-connect.php" \
     https://<tunnel-hostname> confirm-unused-hostname
   ```

   If the hostname was used before, the script stops and prints an exact `force-hostname-takeover=<blog-id>` argument. Use it only when the previous connection can be replaced.

4. Close the tunnel with your tunnel tool. The connection remains active across `env:stop` and `env:start`.

No separate enrollment is required. Complete a checkout with test data to exercise the live path.

For the default Checkout block, use the order ID from the confirmation page to inspect that attempt:

```bash
npm run env -- run cli wp db query \
  "SELECT decision, trigger_type FROM wp_wc_fraud_protection_sessions WHERE order_id = <order-id> ORDER BY id DESC LIMIT 1;"
```

With no matching merchant rule, `blackbox` means verification returned a recognized service verdict and `verify_error` means no usable verdict was received and verification failed open. A rule-triggered row cannot confirm the service result. No row means this check could not confirm verification for that order. Shortcode checkout cannot use this query because it verifies before the order is created.

#### Disconnect

Before removing a worktree, disconnect, run `npm run env:destroy`, and then remove it. Disconnect before `npm run env:clean -- development` or `npm run env:clean -- all`; run `npm run env:start` afterward to seed the clean site. If you clean or destroy the site first, its WordPress.com blog is orphaned.

```bash
npm run env -- run cli wp --user=1 eval-file \
  "wp-content/plugins/$(basename "$PWD")/bin/jetpack-disconnect.php"
```

If disconnect fails, keep the environment and follow the recovery instructions from the script. Do not clean or destroy the development environment until the disconnect succeeds.

## Tests

Run the whole suite (smoke, PHP, and JavaScript):

```bash
npm run test
```

### JavaScript tests (Jest)

```bash
npm run test:js
```

### PHP tests (PHPUnit)

PHP tests run against WooCommerce core's test framework, so they need a WordPress test environment (WordPress, the test library, and WooCommerce) and a MySQL database.

Set up the environment once. This creates the database and downloads WordPress, the test library, and WooCommerce:

```bash
tests/bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [wc-version]
```

Then run the tests:

```bash
npm run test:php                          # all PHP tests
npm run test:php -- --filter <ClassName>  # a single test class
```

The test bootstrap locates WooCommerce automatically. To run against an existing WooCommerce checkout instead, set the `WC_DIR` environment variable to its plugin directory. That checkout must have its own dependencies installed: run `composer install` in the WooCommerce plugin directory, otherwise WooCommerce fails to load. (The `install-wp-tests.sh` setup above does not need this; it uses the prebuilt plugin from wordpress.org.)

## Public API

The public code API for this plugin consists of the classes inside the `src/FraudProtection/` directory (`Automattic\WooCommerce\FraudProtection` as the root namespace).

### Obtaining the services

Stateful services (`SessionVerifier`, `FraudProtectionReporter`) declare their dependencies through an `init()` method and are wired by WooCommerce's dependency-injection container, so resolve them from the container rather than constructing them directly:

```php
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

$verifier = wc_get_container()->get( SessionVerifier::class );
$decision = $verifier->verify_session( $session_id, $source, $order_id, $request_data );
```

The remaining public classes are used directly: `BlockedSessionMessage` and `PaymentMethodData` have public constructors (`new`), while the other DTOs have private constructors and are built via their static factories (`ReportContextData::from_array()`, `PaymentInstrumentData::from_array()` / `::empty()`). The enums are used as cases (e.g. `MessageContext::Purchase`).

### Extension filters

Three hooks let an extension (e.g. a payment gateway with a non-standard checkout flow) integrate with the fraud check. All are fail-open: an exception or invalid return falls back to the plugin's default and never blocks a transaction.

- **`woocommerce_fraud_protection_resolved_payment_data`** — the primary hook for payment gateways: enrich or replace the resolved payment data included in the fraud-check payload (card brand, last4, transaction mode, and so on). Return a `PaymentMethodData`; an invalid return falls back to the baseline resolved from the WC payment token.

  ```php
  apply_filters( 'woocommerce_fraud_protection_resolved_payment_data', PaymentMethodData $resolved, array $checkout_payment_fields );
  ```

- **`woocommerce_fraud_protection_skip_session_verify`** — return a `FraudDecision` to skip verification and have the built-in checkout protectors apply that decision instead of calling Blackbox. For a flow that runs its own `SessionVerifier::verify_session()` earlier in the same payment attempt and still holds the decision it produced, so the attempt is not scored twice.

  Return the decision the attempt actually received, not a blanket allow: if your earlier verification blocked, returning `FraudDecision::Allow` here would discard that block. Only `FraudDecision::Allow` and `FraudDecision::Block` are honoured; any other return — including the `false` default — leaves the session to be verified. A consumer with nothing to say for a given call returns the value it received, unchanged.

  ```php
  apply_filters( 'woocommerce_fraud_protection_skip_session_verify', FraudDecision|false $decision, string $source, array $request_data, string $session_id );
  ```

  *Since 0.1.6 a truthy return no longer skips. Skipping without saying what the decision was turned every deferral into an allow, so the skip now carries the decision.*

- **`woocommerce_fraud_protection_enqueue_blackbox_scripts`** — return `true` to load the Blackbox scripts on a page the plugin would not otherwise target (e.g. product or cart pages that render express-checkout buttons).

  ```php
  apply_filters( 'woocommerce_fraud_protection_enqueue_blackbox_scripts', bool $should_enqueue );
  ```

### JavaScript integration

On pages where the Blackbox scripts are loaded, the plugin exposes a small API on `window.wcFraudProtection`:

- **`acquireSessionId(): Promise<string>`** — resolves to a Blackbox session ID, or an empty string on timeout/error (fail-open). Send this value to the server under the field name in `window.wcFraudProtection.config.sessionIdField` (which mirrors `SessionVerifier::SESSION_ID_FIELD`); the server reads it back before calling `verify_session()`.
- **`reset(): void`** — clears Blackbox state so a subsequent payment attempt gets a fresh session.

`config.sessionIdField` is the only supported entry on `config`; the rest are internal to the plugin's own init script and may change.

Enqueue your integration script with `wc-fraud-protection-blackbox-init` as a dependency so it runs after the API is set up, and use the `woocommerce_fraud_protection_enqueue_blackbox_scripts` filter (above) to ensure the scripts load on your page:

```js
( function () {
	const fp = window.wcFraudProtection;
	if ( ! fp ) {
		return;
	}

	// When your gateway is about to submit, acquire a session ID and attach it:
	fp.acquireSessionId().then( function ( sessionId ) {
		requestBody[ fp.config.sessionIdField ] = sessionId;
		// ... send the request, then reset for the next attempt:
		fp.reset();
	} );
} )();
```

The plugin owns only this shared Blackbox infrastructure (the SDK loader, the `wc-fraud-protection-blackbox-init` init script, and the localized `config`). A gateway's own interceptor script is owned and enqueued by the gateway — from its own plugin URL and version — not by this plugin.

All the code in the `src/Internal/` directory (`Automattic\WooCommerce\Internal` as the root namespace) is for **exclusive internal usage** of the plugin and **MUST NOT** be used by other plugins (or otherwise from outside of this plugin): backwards compatibility for this code across plugin versions is not guaranteed.
