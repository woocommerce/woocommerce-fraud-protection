## Requirements

- PHP 8.1+
- WooCommerce 9.8+
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
npm run build:qit       # release ZIP plus a QIT ZIP with dependency manifests
npm run build:release   # production build plus a distributable plugin zip
```

The assets in `assets/` are served as-is, so a build step is not required for everyday development.

## Releases

Merchant-facing and developer-facing pull requests add a concise entry under the placeholder release at the top of `changelog.txt`. Changes limited to tests, CI, documentation, or internal refactoring do not need an entry.

The release pull request reviews the complete placeholder block, sets its date, and updates the version files on `release/<version>`. After the pull request is approved and ready to merge, run the `Release` GitHub Actions workflow from that release branch. The workflow builds the release ZIP and a QIT copy with dependency manifests for audits. It runs the QIT activation and security tests against the QIT copy, waits for approval through the `release` environment, and publishes the release ZIP from the tested commit. Merge the release pull request with a merge commit after publication.

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

Stateful services (`SessionVerifier`, `FraudProtectionReporter`, `BlackboxScriptHandler`) declare their dependencies through an `init()` method and are wired by WooCommerce's dependency-injection container, so resolve them from the container rather than constructing them directly:

```php
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

$verifier = wc_get_container()->get( SessionVerifier::class );
$decision = $verifier->verify_session( $session_id, $source, $order_id, $request_data );
```

Use a stable application-defined ASCII source identifier of 32 characters or fewer. The verifier shortens longer values to 32 characters at ingestion before it applies filters, sends the request, or records the local event.

The remaining public classes are used directly: `BlockedSessionMessage`, `PaymentMethodData`, and `SuppliedDecision` have public constructors (`new`), while the other DTOs have private constructors and are built via their static factories (`ReportContextData::from_array()`, `PaymentInstrumentData::from_array()` / `::empty()`). The enums are used as cases (e.g. `MessageContext::Purchase`).

### Extension filters

Two hooks let an extension (e.g. a payment gateway with a non-standard checkout flow) integrate with the fraud check. Errors fail open.

- **`woocommerce_fraud_protection_resolved_payment_data`** — the primary hook for payment gateways: enrich or replace the resolved payment data included in the fraud-check payload (card brand, last4, transaction mode, and so on). Return a `PaymentMethodData`; an invalid return falls back to the baseline resolved from the WC payment token.

  ```php
  apply_filters( 'woocommerce_fraud_protection_resolved_payment_data', PaymentMethodData $resolved, array $checkout_payment_fields );
  ```

- **`woocommerce_fraud_protection_skip_session_verify`** — return a `SuppliedDecision` to skip verification and have the built-in checkout protectors apply an earlier result. Use it for a flow that verified earlier in the same payment attempt, so the attempt is not scored twice.

  Construct the result with the decision the attempt received and its response-backed session ID only when that ID can be stored on the current order. Never use a submitted or stale session ID. Pass null when the earlier result has no authorized order association. Only `FraudDecision::Allow` and `FraudDecision::Block` are accepted. A later callback can return another result.

  A callback with nothing to supply returns the value it received. A final value other than `SuppliedDecision`, or a `SuppliedDecision` with a non-actionable decision, runs normal verification. If a callback throws, verification also continues normally.

  ```php
  apply_filters( 'woocommerce_fraud_protection_skip_session_verify', SuppliedDecision|false $supplied_decision, string $source, array $request_data, string $session_id );
  ```

  `SuppliedDecision::$decision` and `SuppliedDecision::$session_id_for_order` are read-only.

### JavaScript integration

On pages where the Blackbox scripts are loaded, the plugin exposes a small API on `window.wcFraudProtection`:

- **`acquireSessionId(): Promise<string>`** — resolves to a Blackbox session ID, or an empty string on timeout/error (fail-open). Send this value to the server under the field name in `window.wcFraudProtection.config.sessionIdField` (which mirrors `SessionVerifier::SESSION_ID_FIELD`); the server reads it back before calling `verify_session()`.
- **`reset(): void`** — clears Blackbox state so a subsequent payment attempt gets a fresh session.

`config.sessionIdField` is the only supported entry on `config`; the rest are internal to the plugin's own init script and may change.

Request the Blackbox scripts from the hook that renders your payment surface, then enqueue your integration script with `wc-fraud-protection-blackbox-init` as a dependency so it runs after the API is set up. `BlackboxScriptHandler::request_scripts()` enqueues the shared scripts when they are available; on `false`, skip your script because the store has no Jetpack blog ID or the request cannot render a payment page. The server still verifies fail-open:

```php
use Automattic\WooCommerce\FraudProtection\BlackboxScriptHandler;

add_action(
	'my_gateway_payment_surface_render',
	function (): void {
		if ( ! wc_get_container()->get( BlackboxScriptHandler::class )->request_scripts() ) {
			return;
		}

		wp_enqueue_script(
			'my-gateway-fraud-integration',
			plugins_url( 'js/fraud-integration.js', __FILE__ ),
			array( 'wc-fraud-protection-blackbox-init' ),
			'1.0.0',
			array( 'in_footer' => true )
		);
	}
);
```

Then, in your integration script:

```js
// In your gateway's submit path, immediately before sending the request —
// not when your script loads: window.wcFraudProtection always exists on
// targeted pages (it carries `config`), but the API methods are attached
// only once the Blackbox SDK has loaded, possibly after your script runs,
// or never (e.g. content blockers).
function withSessionId( requestBody ) {
	const fp = window.wcFraudProtection;

	if ( ! fp || typeof fp.acquireSessionId !== 'function' ) {
		// Fail-open: send the request without a session ID. Never block
		// the payment because fraud protection is unavailable.
		return Promise.resolve( requestBody );
	}

	return fp.acquireSessionId().then( function ( sessionId ) {
		requestBody[ fp.config.sessionIdField ] = sessionId;
		return requestBody;
	} );
}

withSessionId( requestBody ).then( function ( body ) {
	// ... send the request, then reset for the next attempt:
	if ( window.wcFraudProtection && window.wcFraudProtection.reset ) {
		window.wcFraudProtection.reset();
	}
} );
```

The plugin owns this shared Blackbox infrastructure (the SDK loader, the `wc-fraud-protection-blackbox-init` init script, and the localized `config`). A third-party gateway's own interceptor script is owned and enqueued by the gateway — from its own plugin URL and version; the one exception is the PayPal interceptor (`paypal-express.js`), which this plugin ships and enqueues itself.

All the code in the `src/Internal/` directory (`Automattic\WooCommerce\Internal` as the root namespace) is for **exclusive internal usage** of the plugin and **MUST NOT** be used by other plugins (or otherwise from outside of this plugin): backwards compatibility for this code across plugin versions is not guaranteed.
