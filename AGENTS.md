# WooCommerce Fraud Protection

WooCommerce Fraud Protection is a standalone WordPress plugin that protects WooCommerce transactions through an external fraud-detection service.

## Read the current sources

Before changing code:

- Read the relevant issue, including its acceptance criteria and comments.
- Check current `trunk` and the affected tests. Plans, line numbers, and class lists can become outdated.
- Read [README.md](README.md) for setup, local testing, and the public API.
- Read the private `woo-fraud-protection-docs` repository for architecture, roadmap, or cross-repository contracts.
- Use WooCommerce Core as a read-only reference. Do not modify Core files for work in this repository.
- For a new feature, evaluate whether operators need a supported WP-CLI diagnostic or maintenance command.

## Runtime and commands

The plugin supports PHP 8.1 or later, but production code must not use features added after PHP 8.1. It uses Node.js 20.

Common commands:

- `npm run test:php -- --filter <ClassName>` — focused PHP test
- `npm run test:js` — JavaScript tests
- `npm run test` — smoke, PHP, and JavaScript tests
- `npm run lint` — PHP, JavaScript, and CSS linting
- `npm run phpstan` — PHP static analysis
- `npm run build:release` — production build and plugin ZIP

Use the isolated `wp-env` store for the current checkout or worktree. Follow [README.md](README.md#local-dev-site-wp-env) for setup and ports. Use the live-service process only when the change requires an end-to-end service check because it sends real traffic.

Assets under `assets/` are served directly. Most development changes do not require a build.

## Code structure

- All project PHP files must declare `strict_types=1`. WP-CLI `eval-file` scripts under `bin/` and `tests/bootstrap.php` are the exceptions.
- PHP 7.4 and 8.0 are parseability targets only for the two plugin entry points and the kill-switch smoke files; they are not supported plugin runtimes. Do not add PHP 8.1 syntax to those files.
- Composer maps `Automattic\WooCommerce\` to `src/`. Do not add manual `require_once` calls for autoloaded classes.
- Public API belongs under `Automattic\WooCommerce\FraudProtection\` in `src/FraudProtection/`.
- Internal code belongs under `Automattic\WooCommerce\Internal\FraudProtectionPlugin\` in `src/Internal/FraudProtectionPlugin/`. Internal is the default for new classes.
- Do not add global functions. The pre-autoloader entry points are the only exception.
- Stateful public services are resolved through the WooCommerce container. Follow each public DTO's existing constructor or factory contract.
- Application components receive dependencies through a `final public init()` method and register hooks through `register()`. Constructors must not have required parameters. `FraudProtectionController` owns application component registration. Process-specific entry points, such as WP-CLI commands, register from `PluginInitializer` when they must remain outside the feature gate.
- The bootstrap resolves the controller on `woocommerce_loaded`. First-party components register from `handle_init()` on WordPress `init`; gateway compatibility classes register from the controller at `woocommerce_loaded`.
- Keep the feature gate at the start of `FraudProtectionController::register()`. Components must not repeat it.
- Follow the timing and structure of the nearest existing component. Hook timing and priorities are intentional.
- Mark public hook callbacks and `init()` methods with `@internal`. Do not add a class-level `@internal` tag to classes in the internal namespace.
- All user-facing text must be translatable with the `woocommerce-fraud-protection` text domain. Logs remain in English.

When changing the public API, read the README public API section and inspect all current consumers. Released public hooks and JavaScript interfaces must remain compatible.

The released extension filters are `woocommerce_fraud_protection_resolved_payment_data` and `woocommerce_fraud_protection_skip_session_verify`. Gateway integrations that render payment surfaces resolve `BlackboxScriptHandler` from the WooCommerce container, call `request_scripts()`, and enqueue their browser script with `wc-fraud-protection-blackbox-init` as a dependency only when the call succeeds. Keep the `window.wcFraudProtection` methods `acquireSessionId()` and `reset()`, the `config.sessionIdField` value, and the init script handle compatible.

## Safety rules

### Fail open

An error before an actionable decision exists must not create a Block. Continue any independent operation that can still produce a valid decision. Fall back to `FraudDecision::Allow` only when processing ends without a later actionable decision. Transport failures, response parsing failures, invalid decisions, and timeouts fail open. Missing or partial request data does not by itself skip verification.

Treat request data and values received from WordPress or WooCommerce hooks and filters as mixed input, even when the normal caller provides a documented type. Validate their types before operations that require a specific type. On invalid input, preserve the original safe value or skip only the invalid field while keeping usable data.

If the `woocommerce_fraud_protection_automated_decision` filter throws after an actionable decision exists, keep the actionable decision that entered the filter. Apply learning mode afterward. An error fallback must not create a new block.

### Use FraudDecision

Use `FraudDecision::Allow`, `FraudDecision::Block`, and `FraudDecision::ACTIONABLE`. Before calling `FraudDecision::tryFrom()` on mixed data, require a string. A valid enum value can still be non-actionable, so check membership in `FraudDecision::ACTIONABLE` before enforcement. Do not hardcode decision strings.

### Preserve each attempt's decision

Automated decisions apply only to the payment attempt that produced them. A bounded record may preserve that attempt's decision across requests when a payment flow requires it. Key the record to evidence produced by the plugin and limit its reuse. Do not persist verdict-derived state that affects later attempts, add store-wide blocking gates, or empty the cart.

Merchant rules are persistent merchant configuration. They are evaluated on each attempt until the merchant changes them. A matching rule takes priority over the service decision and bypasses the automated-decision filter and learning mode. A merchant Block rule remains enforced during learning mode.

A component that verified earlier in the same attempt must preserve and return the decision it received. Skipping a later verification must not turn a previous block into an allow.

### Trust plugin evidence

A decision to omit verification must rely on evidence produced by this plugin, such as an in-request marker or a recorded scored session. Do not trust a query parameter, header, or form field that only claims the request is trusted.

### Protect private behavior

Use generic customer messages such as “We are unable to process this request online.” Do not reveal fraud detection, aggregation logic, correlation logic, risk-scoring details, or rule thresholds.

## Hooks, logging, and errors

- Add public hooks only when an extension contract is required. Released hooks must be maintained.
- Validate every filtered value. On invalid data, return to the original safe value.
- Gateway compatibility filters must return the incoming resolved value unchanged when the gateway does not match. On partial resolution or failure, preserve all incoming fields and add only values that were resolved successfully.
- Use `FraudProtectionController::log()` after the controller is available. The loader and initializer may use `error_log()` for failures that occur before the plugin logger can load.
- Prefer structured context for user and third-party values. For new or changed platform-forwarded entries, do not interpolate form data, payment data, or third-party exception text that may contain user input into the message. Existing forwarded `ApiClient` messages do this and require separate cleanup; do not copy that pattern.
- Use platform-log forwarding only for failures that require central operator attention.
- Adding a forwarded context key requires a privacy review.

### Platform-log forwarding

The optional fourth argument to `FraudProtectionController::log()` forwards the prepared message and a sanitized copy of its structured context to the PHP error log when set to `true`. Only the structured context is sanitized. The argument defaults to `false`. Forward only events that need central operator attention, such as transport failures, response parsing failures, plugin exception paths, and third-party filter failures. The entry is still written to the local WooCommerce log.

Forwarded context passes through `LogContextSanitizer`. It keeps only reviewed, top-level allowlisted keys with scalar values, truncates strings to 200 characters, and drops all other values. Nested keys are not extracted. Keep detailed or sensitive data only in the local context. Add an allowlist key only with a privacy review in the pull request. Use a new key for each reviewed purpose; in particular, do not reuse `schema_db_error` for data-path query errors because their text can contain stored values.

The message is forwarded without sanitization. Do not include form fields, raw payment data, personal data, full payloads or responses, or user-controlled third-party exception text in a forwarded message. Pass reviewed values as structured context instead.

Do not change the forwarded line format without confirming the host PHP-errors parser contract and updating its tests. The required form is:

`PHP Warning: [woo-fraud-protection <level>] <message>[ <sanitized-json>] in <plugin-main-file> on line <code>`

- Keep the literal `PHP Warning:` prefix. The host parser maps it to `severity:"Warning"`.
- Keep the `[woo-fraud-protection <level>]` tag. The application level remains in this tag.
- Append sanitized JSON only when at least one allowed context value remains. Do not append empty braces.
- Keep `in <plugin-main-file> on line <code>` as the final segment because the parser match is end-anchored.
- Use the fixed `woocommerce-fraud-protection.php` marker path. It identifies the plugin for host filtering; it is not the call site.
- Encode application severity in the line code: warning `-10`, error `-20`, critical `-30`, alert `-40`, and emergency `-50`. An unmapped level uses `-10`.

Use the error mechanism for the active WooCommerce flow:

- Store API flows throw `RouteException`.
- Classic checkout adds errors to `WP_Error`.
- Boolean validation filters return `false` and add a WooCommerce notice.

Use `BlockedSessionMessage::get_plaintext()` for API and `WP_Error` responses. Use `BlockedSessionMessage::get_html()` for rendered WooCommerce notices. Pass `MessageContext::Purchase` for checkout, cart, and other purchase flows. Use `MessageContext::Generic` for non-purchase flows such as adding or changing a payment method.

Keep the separate payment-resolution and session-verification error handling in `SessionVerifier::verify_session()`. One failure must not prevent the other operation.

On add-payment-method screens, `is_add_payment_method_page()` must be combined with the `add-payment-method` query-variable check. The function also returns true on the payment-method list page.

## Tests

Test the plugin's observable behavior. Prefer real WooCommerce flows where practical:

- payment gateways through `get_available_payment_gateways()`;
- REST requests through `rest_get_server()->dispatch()`;
- actions and rendered output through the real hook.

Mock an external request or collaborator at its direct boundary. Do not use an unrelated WordPress or WooCommerce filter only to control test setup. Reuse existing test support classes and data providers before adding new fixtures or helpers.

Tests must cover behavior owned by this plugin. Avoid tests that only repeat WooCommerce behavior or assert implementation details without protecting a contract.

Remove hooks and options created by a test. Use the in-memory logging controller supplied by `FraudProtectionUnitTestCase` for log assertions.

Run focused tests during development. Before handoff, run the checks that match the changed files and behavior. Use the local test store for customer or merchant flows that unit tests cannot prove.

## Issues and pull requests

Fraud Protection issues use the `WOOFP` identifier in Linear. Fetch the complete issue and any linked Slack, P2, design, or architecture context needed for the task.

The agent preparing each merchant-facing or developer-facing pull request must add a concise entry under the `YYYY-xx-xx` placeholder release at the top of `changelog.txt`. If no placeholder exists after a release, the agent preparing the first such pull request adds one for the next patch version. Use the existing `Added`, `Updated`, `Fixed`, or `Dev` category and describe observable plugin behavior. Pull requests limited to tests, CI, documentation, or internal refactoring do not need an entry. The agent preparing the release reviews the complete block with the user and replaces the placeholder date only after the user approves it.

Prefix pull-request titles with `Fraud Protection:`. Before drafting a description, inspect recent repository pull requests and follow their current structure. Explain why the change is needed and how it solves the issue. Include useful manual tests that a reviewer can run on a generic test site. Do not add manual steps that only repeat automated checks.

Do not disclose private service behavior in source comments, commit messages, or pull-request text.
