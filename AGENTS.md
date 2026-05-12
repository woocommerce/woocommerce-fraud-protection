# WooCommerce Fraud Protection

WooCommerce Fraud Protection is a standalone WordPress plugin that integrates with WooCommerce to protect merchants from fraudulent transactions. It operates as a client to an external fraud detection service, communicating session and checkout data to receive allow/block verdicts.

For detailed architecture documentation (API patterns, blocking strategy, session flow, security model), see the private `woo-fraud-protection-docs` repository.

## Tech Stack

PHP 7.4+ (no PHP 8.0+ features), WordPress, WooCommerce, Vanilla JS, Composer, npm/wp-scripts, Node 20.

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

Test cleanup in `tearDown()`: call `remove_all_actions()` / `remove_all_filters()` for any hooks added during the test. Call `delete_option()` for any WooCommerce options set. Use `LoggerSpyTrait` for asserting log messages with `assertLogged()`.

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

**Strict types**: All PHP files MUST declare `declare(strict_types=1)`.

**Component wiring**: Classes receive dependencies via an `init()` method (not `__construct`) and register hooks in a `register()` method. To add a new component: (1) create the class in `src/`, (2) instantiate and call `init()` in the bootstrap closure, (3) add a typed property to `FraudProtectionController` + parameter to its `init()`, (4) call `$this->component->register()` in `on_init()`. Mark `init()` with `final` and `@internal`. The `__construct()` must have no required parameters. Hook priorities are intentional (e.g. priority 1 for early blocking, 999 for late filtering) — don't change them without understanding the flow.

**No short ternary**: The `?:` operator is disallowed by PHPCS (`Universal.Operators.DisallowShortTernary`). Always use full ternary `$x ? $x : $default`.

**Autoloading**: PSR-4 autoloader via Composer (`vendor/autoload.php`), loaded inside the `woocommerce_loaded` callback. Classes are resolved lazily on first use — no manual `require_once` needed when adding new classes. Global public API functions (e.g. `wc_fraud_protection_report()`) are defined outside the callback but must guard against WooCommerce not being loaded (e.g. `function_exists( 'WC' )`) since the autoloader is only available after `woocommerce_loaded`.

**Namespace**: PSR-4 under `Automattic\WooCommerce\FraudProtection\`.

**i18n**: All user-facing text must be translatable. Text domain: `woocommerce-fraud-protection`. Log messages stay in English.

**Logging**: Always use `FraudProtectionController::log()`. Include context like filter names, decision values, and session data.

**Schema objects**: DTOs in `src/Schemas/` use private constructors with static factory methods (`from_wc_customer_billing()`, `from_cart()`, `empty()`). Do NOT use `new` directly — factory methods also handle sanitization.

**Compat layers**: Gateway compat classes in `src/Compat/` follow a pass-through pattern: receive `$resolved` as first parameter, return it unchanged if the gateway doesn't match, only override on successful resolution. This allows chaining.

**Filter hooks**: Be judicious — once released, they must be maintained. Always validate filtered output and fall back to the original value on invalid data.

## Architecture

```
src/                 PHP source (PSR-4 namespaced)
src/Schemas/         Data transfer objects (Address, CartItem, OrderData, etc.)
src/Compat/          Payment gateway compatibility layers (Stripe, Square)
tests/php/           PHPUnit tests (extend WC_Unit_Test_Case)
tests/js/            Jest tests
assets/js/           JavaScript assets (checkout integration, blackbox init)
assets/css/          CSS assets
stubs/               PHPStan stubs for external dependencies
```

The plugin bootstraps on the `woocommerce_loaded` action (not `plugins_loaded` — this is an MU-plugin) in `woocommerce-fraud-protection.php`. All classes are instantiated and wired there via `init()` calls. The main controller is `FraudProtectionController`, which orchestrates all components via its `register()` method.

**Protector pattern**: `*Protector` classes (e.g. `BlocksCheckoutProtector`, `ShortcodeCheckoutProtector`) share the same shape — they take `SessionVerifier`, `BlockedSessionNotice`, and `PaymentDataResolver` via `init()`, hook a verification filter/action + a JS enqueue action in `register()`, and call `verify_and_block()` with fail-open try-catch blocks. Each defines a unique `SOURCE` constant (e.g. `'blocks_checkout'`) and has a companion JS file in `assets/js/` that gates form submission to acquire a session ID. New integrations should follow this pattern.

**Blocks integration (JS)**: Gates checkout via `onCheckoutValidation` → `getSessionId()` raced against a 5s timeout (fail-open) → `setExtensionData`. Resets Blackbox via `onCheckoutFail` (success navigates away; no reset needed).

**Classic/shortcode integration (JS)**: Gates form submission via `checkout_place_order` event → `getSessionId()` raced against a 5s timeout (fail-open) → hidden field injection → re-submit. Resets Blackbox via deferred cleanup after the re-submitted form goes through.

## Core Principles

### 1. Fail-Open Pattern (CRITICAL)

**Always default to "allow" when errors occur.** Invalid decisions, API failures, timeouts, or filter errors MUST all result in allowing the session. Never block legitimate transactions due to system errors.

### 2. Use Decision Constants

Always use `ApiClient::DECISION_ALLOW`, `ApiClient::DECISION_BLOCK`, `ApiClient::VALID_DECISIONS`. Never hardcode decision strings.

### 3. Error Messages Must Not Reveal Fraud Detection

Use generic messages like "We are unable to process this request online". Never reveal fraud detection to users.

### 4. Open Source Awareness

This code is open source. Never expose aggregation/correlation logic, risk scoring internals, or rule definitions/thresholds. Only reference session IDs and verdicts.

## Common Pitfalls

- **No PHP 8.0+ features**: No enums, named arguments, typed class constants, `match`, fibers, readonly properties, intersection types, or `str_contains()`/`str_starts_with()`.
- **PaymentMethodData gateway param**: The `$gateway` string is the REQUIRED first constructor argument.
- **Sticky blocked state**: Once a session is blocked via `DecisionHandler`, it stays blocked even if a subsequent verify returns "allow". This is intentional — don't "fix" it.
- **Separate try-catch blocks are intentional**: In `SessionVerifier::verify_session()`, payment resolution and session verification have independent try-catch blocks so one failing doesn't prevent the other from running. Do not merge them.
- **Error response mechanism varies by context**: Store API flows (blocks checkout) throw `RouteException`. Classic checkout adds errors to `WP_Error` (via hooks like `woocommerce_after_checkout_validation`). Boolean filter flows (add-to-cart validation) return `false` and call `wc_add_notice()`. Use `get_message_plaintext()` for API responses (`RouteException`, `WP_Error`) and `get_message_html()` for page renders (`wc_add_notice`). Don't return `WP_Error` directly from methods with `WP_REST_Response` return types — the Hydration service expects `WP_REST_Response` objects.
- **Support email priority**: Use `BlockedSessionNotice::get_support_email()` which follows: WC mailer "from" address → admin email. WooCommerce has no global reply-to address, so reply-to is not part of the chain.
- **BlockedSessionNotice message contexts**: `get_message_html()` and `get_message_plaintext()` accept a `$context` parameter — use `'purchase'` for checkout/cart blocking, `'generic'` (default) for non-purchase flows.
- **`is_add_payment_method_page()` is not enough**: It returns true on the payment methods listing page too. Always combine with the query var check: `is_add_payment_method_page() && isset( $wp->query_vars['add-payment-method'] )`.

## Issue Tracking

Issues are tracked in Linear under the WOOSUBS team (identifiers like `WOOSUBS-1234`). When working on an issue, fetch the full details (title, description, acceptance criteria, comments) from Linear using the `context-a8c` MCP tool. Also fetch any external context referenced in the issue (P2 posts, Slack threads). If no issue ID is provided, ask the user what they'd like to work on.

For roadmap context and architecture details, refer to the `woo-fraud-protection-docs` repository.

## Pull Requests

Prefix PR titles with `Fraud Protection:` (e.g. `Fraud Protection: Add Square payment data compatibility`).

Keep the changes description concise but include the **why** and **how** behind the changes — not just what changed. Include detailed testing instructions so reviewers can verify the behavior.

### PR Review Checklist

- [ ] Fail-open pattern: All error cases default to "allow"
- [ ] Constants: Using `ApiClient::DECISION_*` constants, not strings
- [ ] Error messages: Generic, don't reveal fraud detection
- [ ] Open source safe: No aggregation logic, risk scores, or rule details exposed
- [ ] Hooks-based integration: All WC integration through hooks, no direct WC Core modifications
- [ ] Hook registration: First-party components via `FraudProtectionController::on_init()`; compat layers self-register with `feature_is_enabled()` guard
- [ ] Filter validation: All filter outputs validated before use
- [ ] Log messages: Using `FraudProtectionController::log()`, include filter/hook names
- [ ] Annotations: `@internal` on new classes
- [ ] Tests: Integration-style where possible, hooks/options cleaned up in `tearDown()`
- [ ] Linting passes for PHP and JS: `npm run lint`
- [ ] PHP static analysis passes: `npm run phpstan`
- [ ] Automated test passes for PHP and JS: `npm run test`
- [ ] If browser automation is available, manually tested on a test store

## Detailed Standards

For comprehensive WooCommerce coding standards, see the skills in `.ai/skills/woocommerce-*/`.
