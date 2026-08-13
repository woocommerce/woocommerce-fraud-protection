# WooCommerce Fraud Protection

WooCommerce Fraud Protection is a standalone WordPress plugin that integrates with WooCommerce to protect merchants from fraudulent transactions. It operates as a client to an external fraud detection service, communicating session and checkout data to receive allow/block verdicts.

For detailed architecture documentation (API patterns, blocking strategy, session flow, security model), see the private `woo-fraud-protection-docs` repository.

## Tech Stack

PHP 8.1+ (no PHP 8.2+ features), WordPress, WooCommerce, Vanilla JS, Composer, npm/wp-scripts, Node 20.

## Development Environment

This plugin benefits from access to two companion repositories:

- **`woo-fraud-protection-docs`** (private) — Blackbox integration architecture, and detailed design docs. Read these when working on new features or understanding system behavior.
- **`woocommerce`** (read-only reference) — WooCommerce core hooks, internals, and integration points. Useful for understanding how WC APIs work. Do NOT modify WooCommerce Core files.

Their paths are configured via environment variables:
- `WOO_FRAUD_DOCS_DIR` — path to the `woo-fraud-protection-docs` repo
- `WOO_CORE_DIR` — path to the `woocommerce` repo

When starting work, check these env vars. If unset, ask the user for the paths.

**Claude Code**: also add the paths as `additionalDirectories` in `.claude/settings.local.json` to grant file access. If the paths are already in `additionalDirectories` no need to ask for the env vars.

## Build & Development

- `npm run build:release` — Production build + plugin zip
- **Manual site testing:** Use the isolated `wp-env` store for the current checkout or worktree. Follow [README → Local dev site (wp-env)](README.md#local-dev-site-wp-env) for setup, ports, and cleanup. Use the live service steps there only for an end-to-end test; they create real production traffic.

JS and CSS assets are served raw from `assets/` — no build step required during development.

## Testing

- `npm run test:php` — Run all PHPUnit tests
- `npm run test:php -- --filter <ClassName>` — Run specific test class
- `npm run test:js` — Jest unit tests
- `npm run test` — Run all tests (JS and PHP)

Prefer integration-style tests that exercise actual WooCommerce flows:
- **Payment gateways**: `WC()->payment_gateways()->get_available_payment_gateways()`
- **REST API**: `rest_get_server()->dispatch()`
- **Actions/output**: `do_action()` with `ob_start()`/`ob_get_clean()`

**Isolate external dependencies and environment with mocks, not the filter pipeline.** When a test needs to control a collaborator or an external boundary (an HTTP/API call, a payment gateway, a third-party class), prefer a mock over hooking WordPress/WooCommerce filters. Two interchangeable forms:
- **PHPUnit mock / partial mock** — stub a class's seam directly, e.g. `getMockBuilder( ApiClient::class )->onlyMethods( array( 'jetpack_remote_request' ) )` to fake the Blackbox transport instead of hooking `pre_http_request`, or `createMock( OrderEventsTracker::class )` injected into the SUT via `init()`.
- **Dedicated test-double class** — for a collaborator that is awkward to mock, define a double under `tests/php/Support/` (e.g. `FraudProtectionControllerForTests`, the in-memory logging controller that `FraudProtectionUnitTestCase` installs by default). A container-resolved collaborator can also be swapped with `wc_get_container()->replace( SomeClass::class, $double )`.

This keeps tests independent of WP/WC plumbing (and of whether Jetpack is loaded), makes the replaced seam explicit, and leaves the surrounding logic (response parsing, decision handling) under test. Reserve filter hooks for tests that specifically assert a hook's or filter's own contract. When the production code lacks a seam, add one (a `protected` method to override, or a constructor/`init` collaborator) rather than reaching for a filter — see `ApiClient::jetpack_remote_request()`, a `protected` transport method that tests override with a partial mock.

Test cleanup in `tearDown()`: call `remove_all_actions()` / `remove_all_filters()` for any hooks added during the test. Call `delete_option()` for any WooCommerce options set. Assert log messages with `assertLogged()` against the in-memory controller spy that `FraudProtectionUnitTestCase` installs by default.

If browser automation tools (e.g. Playwright MCP) are available, use them to verify changes on a test store. Ask the user for the store URL.

## Linting

- `npm run lint:php` — PHP CodeSniffer (tests are excluded from PHPCS)
- `npm run lint:php:autofix` — Auto-fix PHP code style
- `npm run lint:js` — ESLint
- `npm run lint:css` — Stylelint
- `npm run lint` — Lint PHP, JS, and CSS

## Static Analysis

- `npm run phpstan` — PHPStan analysis

PHPStan stubs for external dependencies (e.g. WC Stripe) live in `stubs/`. If you add a new external class dependency to a compat layer, add a corresponding stub file.

## Code Conventions

**Strict types**: All PHP files MUST declare `declare(strict_types=1)`. WP-CLI `eval-file` scripts under `bin/` are the exception because WP-CLI evaluates them after another statement and PHP rejects the declaration.

**Component wiring**: Classes receive dependencies via an `init()` method (not `__construct`) and register hooks in a `register()` method. `FraudProtectionController` is the single place that registers everything; the bootstrap (`PluginInitializer`) only resolves the controller itself. First-party components are injected into `FraudProtectionController::init()` and registered from `handle_init()` (deferred to the WordPress `init` hook); to add one: (1) create the class in `src/Internal/FraudProtectionPlugin/` (the default location for internal classes; put protectors, trackers, session, and rules-engine classes in the `Protectors/`, `Trackers/`, `Sessions/`, and `Rules/` subnamespaces respectively), (2) add a typed property + an `init()` parameter (the container auto-resolves and injects it), (3) call `$this->component->register()` in `handle_init()`. Payment gateway compat layers instead register from `register()` itself (at `woocommerce_loaded`, preserving their original timing); they're resolved on demand in `register_compat_layers()` rather than held as properties — add new ones there. The single `feature_is_enabled()` gate lives at the top of `register()`, so no component repeats it. Mark `init()` with `final` and `@internal`. The `__construct()` must have no required parameters. Hook priorities are intentional (e.g. priority 1 for early blocking, 999 for late filtering) — don't change them without understanding the flow.

**No short ternary**: The `?:` operator is disallowed by PHPCS (`Universal.Operators.DisallowShortTernary`). Always use full ternary `$x ? $x : $default`.

**Autoloading**: PSR-4 autoloader via Composer (`vendor/autoload.php`), loaded inside the `woocommerce_loaded` callback. Classes are resolved lazily on first use — no manual `require_once` needed when adding new classes. The autoloader is only available after `woocommerce_loaded`, which is why every component is instantiated and wired in that callback.

**No standalone functions**: Expose all functionality through PSR-4 classes (public API under `Automattic\WooCommerce\FraudProtection\`; everything else internal — see **Namespace** below), never through global/procedural functions. The sole exception is the pre-autoloader bootstrap (`woocommerce-fraud-protection.php` and `woocommerce-fraud-protection-loader.php`): those files run before the autoloader exists, so they cannot use classes (e.g. the loader's `plugins_url` filter callback).

**Namespace**: PSR-4 with the Composer root `Automattic\WooCommerce\` mapped to `src/`, mirroring WooCommerce core's public/internal split. Public API classes (consumed by third parties) live under `Automattic\WooCommerce\FraudProtection\` (`src/FraudProtection/`): currently `FraudProtectionReporter`, `SessionVerifier` (whose `SESSION_ID_FIELD` constant names the request field the Blackbox JS writes the session ID into), `BlockedSessionMessage` (plus the `MessageContext` enum it takes), the report schemas (the `ReportContextData` DTO plus the `EventPhase`, `ReportResult`, `DisputeReason`, `PaymentRefusalReason`, `LiabilityShift`, `ReportSource`, and `FraudDecision` enums), and the resolved-payment-data schemas (the `PaymentMethodData` and `PaymentInstrumentData` DTOs plus the `PaymentMode` and `CheckResult` enums — the payload of the `woocommerce_fraud_protection_resolved_payment_data` filter that gateway compat layers consume). Consumers obtain the stateful public services (`SessionVerifier`, `FraudProtectionReporter`) from the WooCommerce container — `wc_get_container()->get( SessionVerifier::class )`, the same resolution the plugin uses internally and the way they'll be instantiated once merged into core; the remaining public classes are used directly — `BlockedSessionMessage` and `PaymentMethodData` via `new`, the other DTOs via their static factories (`from_array()`; their constructors are private), and the enums as cases. Everything else is internal under `Automattic\WooCommerce\Internal\FraudProtectionPlugin\` (`src/Internal/FraudProtectionPlugin/`); the `Internal\` location alone marks a class as internal — internal classes do **not** carry a class-level `@internal` tag (see the `@internal` convention below).

> **Why `Internal\FraudProtectionPlugin` and not `Internal\FraudProtection`?** WooCommerce core itself shipped a built-in fraud-protection feature under `Automattic\WooCommerce\Internal\FraudProtection\` (added in WC 10.6.0, removed in 10.6.1); this plugin is its standalone successor. Reusing that exact namespace makes our classes collide with core's identically-named ones on WC versions that still ship them. The `Plugin` suffix is a deliberate, temporary disambiguation — **when this code is merged back into core, rename `Internal\FraudProtectionPlugin` → `Internal\FraudProtection`** (a single find/replace). The public `Automattic\WooCommerce\FraudProtection\` namespace does not collide and stays as-is.

**Where to put a new class**: Put it in `src/FraudProtection/` (public) *only* when it is clearly intended to be part of the plugin's public code API — i.e. something third parties (e.g. payment gateways) are meant to call or construct directly. When that is not clearly the case, or whenever in doubt, put it in `src/Internal/FraudProtectionPlugin/` instead. Internal is the default; moving a class from internal to public later is a safe, non-breaking change, whereas the reverse breaks consumers, so bias toward internal.

**`@internal` annotations**: A class's `Internal\` namespace location is what marks it internal, so internal classes carry **no** class-level `@internal` tag (it would be redundant). Reserve `@internal` for `public` members that are public *only* for framework reasons and must not be called directly: WordPress hook callbacks (registered via `add_action`/`add_filter`/`add_shortcode`) and the `init()` DI method (the latter is also required by the `WooCommerce.Functions.InternalInjectionMethod` sniff). This applies in both namespaces — public classes under `src/FraudProtection/` likewise mark only their hook callbacks and `init()`, not the class itself.

**i18n**: All user-facing text must be translatable. Text domain: `woocommerce-fraud-protection`. Log messages stay in English.

**Logging**: Always use `FraudProtectionController::log()`. Include context like filter names, decision values, and session data. The optional 4th parameter `$forward_to_platform_log` (default `false`) opts an entry into PHP `error_log()` forwarding when set to `true`, via a strict allowlist sanitizer (see `LogContextSanitizer::ALLOWED_KEYS`). Reserve this for entries that signal something an operator would want centrally aggregated (transport failures, response parsing failures, plugin exception paths, third-party filter failures). The forwarded message string is shipped verbatim - do NOT interpolate form fields, raw payment data, or third-party exception text with embedded user input into log messages; pass structured data via context keys instead. Adding a key to the sanitizer allowlist requires a privacy review note in the PR.

Forwarded entries are emitted as `PHP Warning: [woo-fraud-protection <level>] <message>[ <sanitized-json>] in <plugin-main-file> on line <code>`. The `PHP Warning:` prefix and the trailing `in <file> on line <N>` marker are required for the host's PHP-errors parser to map the entry to a structured record (`severity:"Warning"`, plus `file`/`kind`/`name`/`line`). App-level severity is encoded into the trailing `line` field per `FraudProtectionController::LEVEL_LINE_CODES` (warning -10, error -20, critical -30, alert -40, emergency -50), so `line:[-50 TO -10]` isolates our intentional emissions. The `<plugin-main-file>` path is a fixed plugin path - it does not point at the real call site - and is only there to keep `kind`/`name` stable for filtering.

**Schema objects**: DTOs in `src/Internal/FraudProtectionPlugin/Schemas/` (public report and resolved-payment-data DTOs live in `src/FraudProtection/Schemas/`) use private constructors with static factory methods (`from_wc_customer_billing()`, `from_cart()`, `from_array()`, `empty()`). Do NOT use `new` directly — factory methods also handle sanitization. The one exception is the public `PaymentMethodData`, which has a public constructor by design: gateway compat layers build it directly (via `new` or `with_transaction_mode()`) when resolving the `woocommerce_fraud_protection_resolved_payment_data` filter.

**Compat layers**: Gateway compat classes in `src/Internal/FraudProtectionPlugin/Compat/` follow a pass-through pattern: receive `$resolved` as first parameter, return it unchanged if the gateway doesn't match, only override on successful resolution. This allows chaining.

**Filter hooks**: Be judicious — once released, they must be maintained. Always validate filtered output and fall back to the original value on invalid data. The three released public extension filters are `woocommerce_fraud_protection_resolved_payment_data` (the primary hook payment-gateway compat layers attach to — enrich or replace the resolved `PaymentMethodData`), `woocommerce_fraud_protection_skip_session_verify` (since 0.1.6 skipping requires returning the `FraudDecision` to apply; any other return — including the `false` default — verifies), and `woocommerce_fraud_protection_enqueue_blackbox_scripts` (all fail-open). Together with the `window.wcFraudProtection` JS API (`acquireSessionId()` / `reset()`, plus `config.sessionIdField`) and the `wc-fraud-protection-blackbox-init` script handle, they form the public integration surface for gateway compat layers and must stay stable. Third-party gateway interceptor JS is owned and enqueued by the gateway from its own plugin URL/version; the exception is `paypal-express.js`, which this plugin ships in `assets/js/` and enqueues itself via `PayPalCompat::enqueue_paypal_script()`. Beyond that, the plugin owns the shared Blackbox infrastructure (SDK loader, init script, localized `config`). See the README "Public API" section for the consumer-facing contract.

## Architecture

```
src/                                    PHP source; PSR-4 root Automattic\WooCommerce\ -> src/
src/FraudProtection/                    Public API (FraudProtectionReporter, SessionVerifier, BlockedSessionMessage, MessageContext)
src/FraudProtection/Schemas/            Public DTOs (ReportContextData, PaymentMethodData, PaymentInstrumentData) + vocabulary enums (EventPhase, ReportResult, DisputeReason, PaymentRefusalReason, LiabilityShift, ReportSource, FraudDecision, PaymentMode, CheckResult)
src/Internal/FraudProtectionPlugin/           Internal implementation (controller, API client, decision/payment handling, ...)
src/Internal/FraudProtectionPlugin/Protectors/  Checkout/payment flow guards (Blocks, Shortcode, AddPaymentMethod, PayForOrder)
src/Internal/FraudProtectionPlugin/Trackers/    Event/report trackers (Cart, Checkout, PaymentMethod, OrderEvents)
src/Internal/FraudProtectionPlugin/Sessions/    Session identity/data collection (IdentityManager, DataCollector) + sessions log (EventRecorder, EventStore, EventPruner)
src/Internal/FraudProtectionPlugin/Rules/       Merchant rules engine (RuleStore, RuleEvaluator, condition operators, RuleConditions)
src/Internal/FraudProtectionPlugin/Schemas/   Internal DTOs (Address, CartItem, OrderData, ...)
src/Internal/FraudProtectionPlugin/Compat/    Payment gateway compatibility layers (Stripe, Square)
tests/php/                              PHPUnit tests (extend WC_Unit_Test_Case), mirrors src/ layout
tests/js/                               Jest tests
assets/js/                              JavaScript assets (checkout integration, blackbox init)
assets/css/                             CSS assets
stubs/                                  PHPStan stubs for external dependencies
```

The plugin bootstraps on the `woocommerce_loaded` action (not `plugins_loaded` — this is an MU-plugin) in `woocommerce-fraud-protection.php`. All classes are instantiated and wired there via `init()` calls. The main controller is `FraudProtectionController`, which orchestrates all components via its `register()` method.

**Protector pattern**: `*Protector` classes (e.g. `BlocksCheckoutProtector`, `ShortcodeCheckoutProtector`) share the same shape — they take `SessionVerifier` and `BlockedSessionMessage` via `init()`, hook a verification filter/action + a JS enqueue action in `register()`, and call `verify_and_block()` with fail-open try-catch blocks. Each defines a unique `SOURCE` constant (e.g. `'blocks_checkout'`) and has a companion JS file in `assets/js/` that gates form submission to acquire a session ID. New integrations should follow this pattern.

**Blocks integration (JS)**: Gates checkout via `onCheckoutValidation` → `getSessionId()` raced against a 5s timeout (fail-open) → `setExtensionData`. Resets Blackbox via `onCheckoutFail` (success navigates away; no reset needed).

**Classic/shortcode integration (JS)**: Gates form submission via `checkout_place_order` event → `getSessionId()` raced against a 5s timeout (fail-open) → hidden field injection → re-submit. Resets Blackbox via deferred cleanup after the re-submitted form goes through.

## Core Principles

### 1. Fail-Open Pattern (CRITICAL)

**Default to "allow" when an error occurs before an actionable decision exists.** Invalid decisions, API failures, and timeouts MUST result in allowing the session. If a `woocommerce_fraud_protection_automated_decision` callback throws after an actionable decision exists, use the actionable decision that entered the filter. Apply the later learning-mode decision as usual. An error fallback must never block a session that did not already have an actionable Block decision.

### 2. Use the FraudDecision enum

Always use the `FraudDecision` enum: `FraudDecision::Allow`, `FraudDecision::Block`, and `FraudDecision::ACTIONABLE` (the set of decisions the plugin acts on). Never hardcode decision strings; when a raw string must be validated (wire payloads, filter returns) go through `FraudDecision::tryFrom()` and check membership in `FraudDecision::ACTIONABLE`.

### 3. Error Messages Must Not Reveal Fraud Detection

Use generic messages like "We are unable to process this request online". Never reveal fraud detection to users.

### 4. Open Source Awareness

This code is open source. Never expose aggregation/correlation logic, risk scoring internals, or rule definitions/thresholds. Only reference session IDs and verdicts.

### 5. A predicate that omits verification must rest on evidence the plugin issued

Never a query parameter, header, or form field. A request that merely describes itself as trusted is not evidence, and treating it as such omits verification entirely for anyone who can send that request. Record what this plugin actually did — an in-request marker, a session it scored — and key the predicate on that.

### 6. Not verifying is not the same as allowing

`woocommerce_fraud_protection_skip_session_verify` supplies *the decision this attempt received*. A compat layer that verified earlier and is answering for a later request in the same attempt must return the verdict it got, and must record it so it survives the request that produced it — returning Allow because "we already handled this" discards blocks.

## Common Pitfalls

- **No PHP 8.2+ features**: The minimum runtime is PHP 8.1, so 8.0/8.1 features are fine (enums, `match`, `readonly` properties, named arguments, fibers, intersection types, `never` return type, first-class callable syntax, `str_contains()`/`str_starts_with()`). Do NOT use anything introduced after 8.1 — e.g. `readonly` classes / DNF types (8.2), typed class constants / `json_validate()` / `#[\Override]` (8.3), property hooks / asymmetric visibility (8.4). The files the kill-switch CI job exercises are the exception: they must stay PHP 7.x-parseable so the unsupported-PHP kill switch can bail before any 8.1 syntax is loaded. These are the two pre-autoloader entry points (`woocommerce-fraud-protection.php` and `woocommerce-fraud-protection-loader.php`, both `php -l`-checked on 7.4/8.0) plus the kill-switch smoke test and the stubs it loads (`tests/php/smoke/scenarios/10-php-version-kill-switch.php` and `tests/php/smoke/stubs/wp.php`, run on 7.4/8.0). Keep all of them free of 8.1+ syntax.
- **PaymentMethodData gateway param**: The `$gateway` string is the REQUIRED first constructor argument.
- **Automated block verdicts are per-attempt; merchant rules are persistent by design**: An automated (Blackbox) block verdict applies only to the checkout/payment attempt that produced it — the protector rejects that attempt and no verdict-derived state is persisted. Every new attempt is re-verified from scratch, so false positives can retry and the `woocommerce_fraud_protection_automated_decision` whitelist filter works as a recovery path. Merchant rules are different: they are deliberate merchant configuration stored in their own table, re-evaluated against every attempt until the merchant edits or deletes the rule, and they bypass the filter and learning mode — their recovery path is the merchant changing the rule. Do NOT reintroduce verdict-derived block state (session-persisted clearance/block flags), store-wide blocking gates, or cart emptying on block (removed in WOOSUBS-1769; velocity/repeat-abuse handling belongs server-side).
- **Separate try-catch blocks are intentional**: In `SessionVerifier::verify_session()`, payment resolution and session verification have independent try-catch blocks so one failing doesn't prevent the other from running. Do not merge them.
- **Error response mechanism varies by context**: Store API flows (blocks checkout) throw `RouteException`. Classic checkout adds errors to `WP_Error` (via hooks like `woocommerce_after_checkout_validation`). Boolean filter flows (add-to-cart validation) return `false` and call `wc_add_notice()`. Use `get_message_plaintext()` for API responses (`RouteException`, `WP_Error`) and `get_message_html()` for page renders (`wc_add_notice`). Don't return `WP_Error` directly from methods with `WP_REST_Response` return types — the Hydration service expects `WP_REST_Response` objects.
- **Support email priority**: The blocked-session support email (resolved privately inside `BlockedSessionMessage`) follows: WC mailer "from" address → admin email. WooCommerce has no global reply-to address, so reply-to is not part of the chain.
- **Blocked-session message**: The public `BlockedSessionMessage::get_html()` / `get_plaintext()` accept a `MessageContext` enum — use `MessageContext::Purchase` for checkout/cart blocking, `MessageContext::Generic` (default) for non-purchase flows. All callers depend on `BlockedSessionMessage` directly (first-party protectors and gateway compat layers alike), surfacing it on the blocked attempt via their context-specific error mechanism.
- **`is_add_payment_method_page()` is not enough**: It returns true on the payment methods listing page too. Always combine with the query var check: `is_add_payment_method_page() && isset( $wp->query_vars['add-payment-method'] )`.

## Issue Tracking

Issues are tracked in Linear under the WOOSUBS team (identifiers like `WOOSUBS-1234`). When working on an issue, fetch the full details (title, description, acceptance criteria, comments) from Linear using the `context-a8c` MCP tool. Also fetch any external context referenced in the issue (P2 posts, Slack threads). If no issue ID is provided, ask the user what they'd like to work on.

For roadmap context and architecture details, refer to the `woo-fraud-protection-docs` repository.

## Pull Requests

Prefix PR titles with `Fraud Protection:` (e.g. `Fraud Protection: Add Square payment data compatibility`).

Keep the changes description concise but include the **why** and **how** behind the changes — not just what changed. Include detailed testing instructions so reviewers can verify the behavior.

### PR Review Checklist

- [ ] Fail-open pattern: All error cases default to "allow"
- [ ] Decisions: Using the `FraudDecision` enum (`FraudDecision::Allow` / `Block` / `ACTIONABLE`), not decision strings
- [ ] Error messages: Generic, don't reveal fraud detection
- [ ] Open source safe: No aggregation logic, risk scores, or rule details exposed
- [ ] Hooks-based integration: All WC integration through hooks, no direct WC Core modifications
- [ ] Hook registration: first-party components are injected into `FraudProtectionController::init()` and registered from `handle_init()` (WP `init`); compat layers register from `FraudProtectionController::register()` (at `woocommerce_loaded`) via `register_compat_layers()`. The single `feature_is_enabled()` gate is at the top of `register()` (no per-component guard)
- [ ] Filter validation: All filter outputs validated before use
- [ ] Log messages: Using `FraudProtectionController::log()`, include filter/hook names
- [ ] Annotations: `@internal` on hook-callback methods and `init()` only — not on internal classes (the `Internal\` namespace marks those)
- [ ] Tests: Integration-style where possible, hooks/options cleaned up in `tearDown()`
- [ ] Linting passes for PHP and JS: `npm run lint`
- [ ] PHP static analysis passes: `npm run phpstan`
- [ ] Automated test passes for PHP and JS: `npm run test`
- [ ] If browser automation is available, manually tested on a test store

## Detailed Standards

For comprehensive WooCommerce coding standards, see the skills in `.ai/skills/woocommerce-*/`.
