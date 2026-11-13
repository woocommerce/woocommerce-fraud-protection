## Requirements

- PHP 8.1+
- WooCommerce 9.8+
- Node.js 24
- Composer
- WP-CLI 2.12.0 (required for `npm run build:release`)
- Docker (for local development and isolated PHP tests)
- MySQL (for direct PHP tests)

## Installing dependencies

```bash
composer install
npm install
```

## Build

```bash
npm start               # watch and rebuild the admin settings assets
npm run build           # development build of the JS/CSS assets
npm run build:qit       # release ZIP plus a QIT ZIP with dependency manifests
npm run build:release   # production build plus a distributable plugin zip
```

The browser-ready files in `assets/` are served as-is. The admin settings application in `client/` must be compiled into `build/`; use `npm start` while developing it or run `npm run build` before loading the settings page.

## Releases

Merchant-facing and developer-facing pull requests add a concise entry under the placeholder release at the top of `changelog.txt`. Changes limited to tests, CI, documentation, or internal refactoring do not need an entry.

The release pull request reviews the complete placeholder block, sets its date, and updates the version files on `release/<version>`. After the pull request is approved and ready to merge, run the `Release` GitHub Actions workflow from that release branch. The workflow builds the release ZIP and a QIT copy with dependency manifests for audits. It runs the QIT activation and security tests against the QIT copy, waits for approval through the `release` environment, and publishes the release ZIP from the tested commit. Merge the release pull request with a merge commit after publication.

## Local dev site (wp-env)

With Docker running, start a test store for the current checkout or worktree:

```bash
npm run env:start
```

Open the store URL reported by `env:start` (default: <http://localhost:8888>). Add `/wp-admin` for the admin (`admin` / `password`). The first start seeds a test store. Later starts keep its data.

```bash
npm run env:stop      # stop the containers (keeps data)
npm run env:destroy   # remove the containers and data
```

Each worktree gets separate containers. To run several at once, add a gitignored `.wp-env.override.json` to each worktree with unique ports:

```json
{ "port": 8890 }
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

PHP tests run in an isolated wp-env configuration. The first run creates the environment and installs the matching WooCommerce test framework. It does not change the local development store.

```bash
npm run test:php:env                          # all PHP tests
npm run test:php:env -- --filter <ClassName>  # a single test class
```

The test environment uses port 8889 by default. Add a gitignored `.wp-env.phpunit.override.json` with another `port` when that port is unavailable.

Use `npm run test:php` to run PHPUnit directly against an existing WordPress test installation. Set `WC_DIR` to an existing WooCommerce plugin directory when the bootstrap cannot locate it. This path requires the WordPress test library, a MySQL database, and the WooCommerce test framework. The CI workflows prepare these dependencies with `tests/bin/install-wp-tests.sh`.

## Public API

Payment gateways and other extensions integrate with the plugin through its public API:

- [Integration guide](docs/integration-guide.md): Start here. It explains what the plugin already covers, which native integration an extension needs, how to declare it, and what to implement for each one, with a short example of each.
- [Public API reference](docs/api-reference.md): Every public PHP class, hook, browser method, script handle and request field, with its contract.
- [Example integrations](examples/README.md): Two working plugins, exercised by the test suite: a gateway with a native integration, and a stand-in for the WooCommerce Subscriptions change-payment-method flow.

The public code API consists of the classes inside the `src/FraudProtection/` directory (`Automattic\WooCommerce\FraudProtection` as the root namespace). All the code in the `src/Internal/` directory (`Automattic\WooCommerce\Internal` as the root namespace) is for **exclusive internal usage** of the plugin and **MUST NOT** be used by other plugins (or otherwise from outside of this plugin): backwards compatibility for this code across plugin versions is not guaranteed.
