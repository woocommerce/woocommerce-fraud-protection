---
name: woocommerce-code-review
description: Review WooCommerce Fraud Protection code changes for safety rules and coding standards compliance. Use when reviewing code locally, performing automated PR reviews, or checking code quality.
---

# WooCommerce Fraud Protection Code Review

Review code changes against the plugin's safety rules in `AGENTS.md` and the coding conventions in the `woocommerce-backend-dev` skill. The safety rules come first: a style violation is a nit, a fail-open violation is a blocker.

## Critical Violations to Flag

### Safety Rules (`AGENTS.md`, "Safety rules")

These are the highest-priority findings. Cite the `AGENTS.md` section by name.

- ❌ **A Block created by an error path** - transport failures, parsing failures, invalid decisions, timeouts, and throwing filter callbacks must fail open; only a later actionable decision may block ("Fail open")
- ❌ **Skipping verification because request data is missing or partial** ("Fail open")
- ❌ **Hook, filter, or request values used without type validation** - they are mixed input even when the documented type is specific; on invalid input keep the original safe value or skip only the invalid field ("Fail open")
- ❌ **Hardcoded decision strings, or `FraudDecision::tryFrom()` on a non-string** - use `FraudDecision::Allow`, `FraudDecision::Block`, and check `FraudDecision::ACTIONABLE` before enforcing ("Use FraudDecision")
- ❌ **Verdict-derived state that outlives the attempt** - store-wide blocking gates, persisted verdicts that affect later attempts, or emptying the cart ("Preserve each attempt's decision")
- ❌ **A skipped verification turning an earlier Block into an Allow** ("Preserve each attempt's decision")
- ❌ **A merchant Block rule bypassed by the automatic-protection setting or the automated-decision filter** ("Preserve each attempt's decision")
- ❌ **Trusting a query parameter, header, or form field that only claims the request is trusted** - only plugin-produced evidence may skip verification ("Trust plugin evidence")
- ❌ **Customer-facing text, comments, or PR text that reveal fraud detection, scoring, correlation, or rule thresholds** ("Protect private behavior")
- ❌ **Wrong error mechanism for the flow** - Store API throws `RouteException`, classic checkout adds to `WP_Error`, boolean validation filters return `false` plus a notice; `BlockedSessionMessage::get_plaintext()` for API and `WP_Error`, `get_html()` for rendered notices, with `MessageContext::Purchase` for purchase flows ("Hooks, logging, and errors")

### Logging and Hooks (`AGENTS.md`, "Hooks, logging, and errors")

- ❌ **User, form, payment, or third-party exception text interpolated into a forwarded log message** - pass it as structured context; forwarded messages are not sanitized
- ❌ **A new forwarded context key without a privacy review**, or `schema_db_error` reused for data-path query errors
- ❌ **Platform-log forwarding (`true` fourth argument) for an event that does not need central operator attention**
- ❌ **A changed forwarded line format** without confirming the host parser contract and updating its tests
- ❌ **A new public hook without an extension-contract need**, a hook without the `woocommerce_fraud_protection_` prefix, or a filtered value used without validation
- ❌ **A gateway compatibility filter that changes the incoming value when the gateway does not match**, or drops incoming fields on partial resolution
- ❌ **A released contract broken**: the two released filters, the `window.wcFraudProtection` methods, `config.sessionIdField`, or the `wc-fraud-protection-blackbox-init` handle
- ❌ **`error_log()` or `wc_get_logger()` used directly** where `FraudProtectionController::log()` is available

### Backend PHP Code

Consult the `woocommerce-backend-dev` skill for details. Flag:

**Architecture & Structure:**

- ❌ **Global functions** - Must use class methods; only the pre-autoloader entry points are exempt ([file-entities.md](../woocommerce-backend-dev/file-entities.md))
- ❌ **Missing `strict_types=1`, file docblock after `declare`, or missing `ABSPATH` guard** ([file-entities.md](../woocommerce-backend-dev/file-entities.md))
- ❌ **PHP syntax newer than 8.1** anywhere, or PHP 8.1 syntax in the entry points and kill-switch smoke files ([file-entities.md](../woocommerce-backend-dev/file-entities.md))
- ❌ **A new class outside `src/Internal/FraudProtectionPlugin/`** unless it is deliberately public API in `src/FraudProtection/` ([file-entities.md](../woocommerce-backend-dev/file-entities.md))
- ❌ **A constructor with required parameters, dependencies not injected through `final public init()`, or hooks registered outside `register()`** ([dependency-injection.md](../woocommerce-backend-dev/dependency-injection.md))
- ❌ **The feature gate repeated inside a component**, or a component registered from somewhere other than `FraudProtectionController` (or `PluginInitializer` for process-specific entry points) ([dependency-injection.md](../woocommerce-backend-dev/dependency-injection.md))
- ❌ **A stateful service constructed with `new` instead of resolved from the container** - the public DTOs are constructed with `new` or their factories by design ([dependency-injection.md](../woocommerce-backend-dev/dependency-injection.md))
- ❌ **A manual `require_once` for an autoloaded class**

**Naming & Conventions:**

- ❌ **camelCase naming** - Must use snake_case for methods/variables/hooks ([code-entities.md](../woocommerce-backend-dev/code-entities.md))
- ❌ **Yoda condition violations** - Must follow WordPress Coding Standards ([coding-conventions.md](../woocommerce-backend-dev/coding-conventions.md))
- ❌ **Untranslated user-facing text, or a text domain other than `woocommerce-fraud-protection`**; log messages stay in English

**Documentation:**

- ❌ **Missing docblocks** - Required for all hooks and methods ([code-entities.md](../woocommerce-backend-dev/code-entities.md))
- ❌ **Missing `@since` on a new public hook or public API element** - internal methods do not need it ([code-entities.md](../woocommerce-backend-dev/code-entities.md))
- ❌ **Missing `@internal` on a hook callback or `init()`**, or a class-level `@internal` on an internal-namespace class ([hooks.md](../woocommerce-backend-dev/hooks.md))
- ❌ **Missing `@throws`** on a `src/` method that throws ([code-entities.md](../woocommerce-backend-dev/code-entities.md))
- ❌ **Verbose docblocks** - Keep concise, one line is ideal ([code-entities.md](../woocommerce-backend-dev/code-entities.md))

**Data Integrity:**

- ❌ **Missing validation** - Must verify state and ownership before deletion or modification; merchant-facing REST routes check `manage_woocommerce` ([data-integrity.md](../woocommerce-backend-dev/data-integrity.md))

**Testing:**

- ❌ **A test class not extending `FraudProtectionUnitTestCase`** ([unit-tests.md](../woocommerce-backend-dev/unit-tests.md))
- ❌ **Using `$instance` in tests** - Must use `$sut` ([unit-tests.md](../woocommerce-backend-dev/unit-tests.md))
- ❌ **Missing `@testdox`** - Required in test method docblocks ([unit-tests.md](../woocommerce-backend-dev/unit-tests.md))
- ❌ **Hooks or options left behind by a test**, or an unrelated filter used only to control setup ([unit-tests.md](../woocommerce-backend-dev/unit-tests.md))
- ❌ **Logging asserted through a fake `WC_Logger`** instead of `assertLogged()` ([unit-tests.md](../woocommerce-backend-dev/unit-tests.md))
- ❌ **No test for the fail-open path** of a new error branch
- ❌ **Tests that only repeat WooCommerce behavior** or assert implementation details without protecting a contract

### Front-End Code (`client/`, `tests/js/`)

Consult the `woocommerce-frontend-dev` skill and go through its [review-checklist.md](../woocommerce-frontend-dev/review-checklist.md). The blockers reviewers have applied:

- ❌ **Tooltip where a Popover is needed** (content with a link, or a non-focusable trigger), **tabs whose panels do not contain the content**, or **icon buttons without accessible names** ([ui-components.md](../woocommerce-frontend-dev/ui-components.md))
- ❌ **Settings read from a PHP-injected global or a one-time page load** instead of the shared store, or **controls shown while the setting is unknown** ([data-and-state.md](../woocommerce-frontend-dev/data-and-state.md))
- ❌ **Navigation state in `localStorage`, or the bare router pathname pushed to the history** instead of a full admin URL through `getHistory()` ([dataviews.md](../woocommerce-frontend-dev/dataviews.md))
- ❌ **UI state corrected from a failed request**, or **stale responses applied** ([data-and-state.md](../woocommerce-frontend-dev/data-and-state.md))
- ❌ **`DataViews` imported from the package root** instead of `@wordpress/dataviews/wp`, **sorting by a value the merchant does not see**, or **one empty message for every cause** ([dataviews.md](../woocommerce-frontend-dev/dataviews.md))
- ❌ **Local SVGs, hand-rolled controls, hard-coded colours, or left/right paddings** instead of `@wordpress/icons`, `@wordpress/ui`, tokens, and logical properties ([styling.md](../woocommerce-frontend-dev/styling.md))
- ❌ **A drawer or dialog that Escape can close while a save is in flight** ([ui-components.md](../woocommerce-frontend-dev/ui-components.md))
- ❌ **Missing text domain or untranslated strings** in TSX ([js-i18n-patterns.md](../woocommerce-dev-cycle/js-i18n-patterns.md))

### Pull Request Hygiene (`AGENTS.md`, "Issues and pull requests")

- ❌ **Missing `changelog.txt` entry** under the placeholder release for a merchant-facing or developer-facing change (tests, CI, documentation, and internal refactoring are exempt)
- ❌ **README public API section not updated** when the public API changed
- ❌ **Private service behavior disclosed** in comments, commit messages, or the pull request description

### UI Text & Copy

Consult the `woocommerce-copy-guidelines` skill. Flag:

- ❌ **Title Case in UI** - Must use sentence case ([sentence-case.md](../woocommerce-copy-guidelines/sentence-case.md))
    - Wrong: "Save Changes", "Checkout Attempts", "Block This Email Address"
    - Correct: "Save changes", "Checkout attempts", "Block this email address"
    - Exceptions: proper nouns, acronyms (API, IP), brand names (WooCommerce, PayPal)
- ❌ **Customer-facing messages other than the generic wording** produced by `BlockedSessionMessage`
- ❌ **"Positive/negative list" or "whitelist/blacklist" wording** in merchant-facing text; the plugin uses allow rules and block rules

## Review Approach

1. **Read the diff against the safety rules first**, then structure, then style
2. **Cite the `AGENTS.md` section or skill file** when flagging issues
3. **Provide correct examples** from the skill documentation
4. **Group related issues** for clarity
5. **Be constructive** - explain why the standard exists when relevant

## Output Format

For each violation found:

```text
❌ [Issue Type]: [Specific problem]
Location: [File path and line number]
Standard: [AGENTS.md section or link to the relevant skill file]
Fix: [Brief explanation or example]
```

## Notes

- `AGENTS.md` is the source of truth for the safety, logging, and process rules; the skills carry the coding conventions
- All detailed conventions are in the `woocommerce-backend-dev`, `woocommerce-frontend-dev`, `woocommerce-dev-cycle`, and `woocommerce-copy-guidelines` skills
- When in doubt, refer to the specific document linked above
