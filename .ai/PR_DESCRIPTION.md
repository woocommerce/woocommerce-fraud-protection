# Fraud Protection: Improve unit test seams and group internal classes into subnamespaces

## TL;DR

- Add an instance `write_log` method in `FraudProtectionController`, make the static `log` method call it.
- Add a `FraudProtectionControllerForTests` class that inherits from `FraudProtectionController` and stores written logs in a plain array.
- Modify all the tests that checks written logs to read them from the array exposed by `FraudProtectionControllerForTests`. Remove the usage of `LoggerSpyTrait`.
- Move the call to the JetPack API to a protected `jetpack_remote_request` method in `ApiClient`.
- Create a mock of `ApiClient` that replaces `jetpack_remote_request` with a stub and use it in unit tests instead of hooking into `pre_http_request`.
- Reorganize most of the classes in the root `src/FraudProtection` directory in three new subdirectories: `Sessions`, `Protectors` and `Trackers`.
- Add a new `woocommerce_fraud_protection_api_request_callback` filter to hook into the blackbox API call.
- Update the AI-faced documentation accordingly.

It's recommended to review the individual commits separately.

## Why

Several of the plugin's components could only be exercised in tests by hooking the WordPress/WooCommerce plumbing — the WP HTTP pipeline (`pre_http_request`), the `woocommerce_logging_class` filter, the core `LoggerSpyTrait` — which made tests depend on framework internals (and on whether Jetpack was loaded) and obscured what was actually being faked. This branch adds first-class seams so collaborators and external boundaries can be replaced with mocks or DI container substitutions, and reorganizes the flat `Internal\FraudProtectionPlugin` namespace into cohesive subnamespaces now that it had grown large.

There is **no functional/behavioral change** to the plugin at runtime. The one new public surface is a filter (see below); everything else is internal refactoring and test changes.

## What changed

### 1. Instance-based, mockable logging facade

`FraudProtectionController::log()`remains available as a **static facade** (every existing call site is untouched), but `log()` now delegates to an instance method `write_log()` on the controller instance held in a private static field, which is assigned in `init()` (called once when the instance is retrieved from the DI container). This lets tests swap the controller for an in-memory double and assert on what components *intended* to log, without touching the real WooCommerce logger.

### 2. In-memory test doubles + DI container replacement

- Added `tests/php/Support/FraudProtectionControllerForTests.php` (records `write_log()` calls) and `OrderEventsTrackerForTests.php` (records `fraud_protection_report()` calls).
- `FraudProtectionUnitTestCase` installs the logging spy by default (guarded by an overridable `uses_logging_spy()`), exposes `assertLogged()`, and restores the canonical controller in `tearDown()`. Doubles are installed via `wc_get_container()->replace()`.
- Converted the logging-verification tests (ApiClient, DecisionHandler, BlackboxScriptHandler, OrderEventsTracker, SessionVerifier, ReportContextData, PaymentInstrumentData) off the WC-core `LoggerSpyTrait`, and stopped loading that trait in `tests/bootstrap.php`. The controller's own logging tests keep exercising the real logger via a `WC_Logger` mock.
- Added `FraudProtectionReporterTest`, injecting the tracker double through the container.

### 3. Replaceable Blackbox API transport (`ApiClient`)

Split `make_request()` into a transport-agnostic part plus `send_api_request()`, which resolves the request callback from a new filter and invokes it with validation + fail-open behavior. The default callback is a new `protected jetpack_remote_request()` that holds the Jetpack-specific guards (`class_exists`, blog ID) and the signed request.

- **New public filter:** `woocommerce_fraud_protection_api_request_callback` (default `[ $this, 'jetpack_remote_request' ]`). A callback receives `( array $request_args, string $body )` and must return a WP HTTP response array or a `WP_Error`. Third parties can route the request differently or replace it entirely (e.g. in a non-Jetpack environment). A non-callable, throwing, or wrong-typed result is logged (forwarded to the platform log) and the request fails open.
- `ApiClientTest` now stubs the `jetpack_remote_request()` seam (partial mock) instead of `pre_http_request`, so it no longer depends on Jetpack Connection being loaded, and gains coverage for the filter (replace / non-callable fallback / throwing / unexpected return).

### 4. Namespace reorganization

Grouped the flat internal classes into subnamespaces (class names unchanged), mirroring the existing `Compat/`, `Logging/`, `Schemas/`:

- `Protectors\` — `AddPaymentMethodProtector`, `BlocksCheckoutProtector`, `PayForOrderProtector`, `ShortcodeCheckoutProtector`
- `Trackers\` — `CartEventTracker`, `CheckoutEventTracker`, `PaymentMethodEventTracker`, `OrderEventsTracker`
- `Sessions\` — `SessionBlockingHandler`, `SessionClearanceManager`, `SessionDataCollector`

The bootstrap/orchestration core (`FraudProtectionController`, `PluginInitializer`) and cross-cutting pieces (`ApiClient`, `DecisionHandler`, `PaymentDataResolver`, `BlackboxScriptHandler`, `BlockedSessionNotice`, `ClassicFormDataExtractionTrait`) stay at the root. Pure move + reference update — no container or `composer.json` change (classes are auto-wired by FQCN via reflection; the PSR-4 root already covers subdirs). Test files were moved to mirror `src/`. The eventual core-merge rename (`Internal\FraudProtectionPlugin` → `Internal\FraudProtection`) remains a single prefix find/replace.

### 5. Documentation (`AGENTS.md`)

- Architecture tree + "Component wiring" note updated for the new subnamespaces.
- Testing section: added the "isolate dependencies with mocks/DI, not the filter pipeline" convention (with the `ApiClient` seam as the worked example), and refreshed the stale `LoggerSpyTrait` reference to the controller-spy approach.
- Fixed a pre-existing stale claim that protectors take `PaymentDataResolver` via `init()` (they take only `SessionVerifier` + `BlockedSessionNotice`).

## How to test

```bash
npm run test:php     # PHPUnit (494 tests) — set WC_DIR if WooCommerce isn't auto-located
npm run test:smoke   # 9 standalone smoke scenarios
npm run phpstan      # static analysis
npm run lint:php     # PHPCS
```

All of the above pass on this branch (PHPUnit 494/494, smoke 9/9, PHPStan clean, PHPCS clean).

Manual / behavioral checks (nothing should change at runtime):

- Checkout/cart blocking, logging, and reporting behave exactly as before — the static `FraudProtectionController::log()` / `feature_is_enabled()` API is unchanged.
- Exercise the new filter, e.g. add a callback to `woocommerce_fraud_protection_api_request_callback` that returns a canned `[ 'response' => [ 'code' => 200 ], 'body' => '...' ]` and confirm verify/report use it in place of the Jetpack request.
