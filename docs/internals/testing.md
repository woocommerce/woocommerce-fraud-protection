# Testing

How the plugin is tested, which helpers exist, and how to test the things that unit tests can't reach. The [README](../../README.md#tests) covers how to run the suites; the `woocommerce-dev-cycle` skill under `.ai/skills/` covers the day-to-day workflow.

## Contents

- [Test suites](#test-suites)
- [PHP tests](#php-tests)
- [JavaScript tests](#javascript-tests)
- [Smoke tests](#smoke-tests)
- [Example integration tests](#example-integration-tests)
- [Continuous integration](#continuous-integration)
- [What to test](#what-to-test)
- [Manual testing](#manual-testing)

## Test suites

| Suite | Command | Location |
|-------|---------|----------|
| Smoke | `npm run test:smoke` | `tests/php/smoke/` |
| PHP | `npm run test:php:env` (isolated wp-env) or `npm run test:php` | `tests/php/` |
| JavaScript | `npm run test:js` | `tests/js/` |
| All three | `npm run test` | |
| Static analysis | `npm run phpstan` | `phpstan.neon` |
| Lint | `npm run lint` | PHP, JavaScript, CSS, TypeScript |

## PHP tests

PHP tests are PHPUnit tests that run inside WordPress with WooCommerce loaded, using WooCommerce's test framework. `tests/php/src/` mirrors `src/`, one test class per class.

Extend `FraudProtectionUnitTestCase`, which extends WooCommerce's `WC_Unit_Test_Case` and adds:

| Helper | Purpose |
|--------|---------|
| In-memory logger | Installed by default in `setUp()` (override `uses_logging_spy()` to opt out); `spy_on_controller_logging()` returns it |
| `assertLogged( $level, $substring, $context, $forwarded )` | Asserts that a log entry was written, optionally with its context and whether it was forwarded |
| `$forwarded_platform_logs` | Lines that would have gone to the PHP error log |
| `mock_jetpack_blog_id( $id )` | Pretends the store is connected to Jetpack |
| `register_payment_gateways( ...$gateways )` | Registers test gateways through WooCommerce's real registration |
| `make_blackbox_script_handler()` | A script handler set up for tests |
| `reset_fraud_protection_scripts()` | Removes the plugin's enqueued scripts between tests |
| `set_server_variables()`, `unset_server_variables()` | Changes `$_SERVER` and restores it after the test |
| `reset_woocommerce_cart_checkout_page_cache()` | Clears WooCommerce's page-ID caches |

`tearDown()` restores the WooCommerce session and cart, server variables, gateways and logger.

`tests/php/Support/` holds shared fixtures: `FakeNativeGateway` (a gateway that declares features), PayPal Payments stubs, session event fixtures, and test versions of the controller and logger.

### Mocking the service

The service call goes through Jetpack's connection client, which uses [`wp_remote_request()`](https://developer.wordpress.org/reference/functions/wp_remote_request/). Tests can answer it with a [`pre_http_request`](https://developer.wordpress.org/reference/hooks/pre_http_request/) filter, or mock `ApiClient` where the test is about something else.

### Rules

From [AGENTS.md](../../AGENTS.md#tests):

- Test observable behavior through real WooCommerce flows where practical: gateways through [`get_available_payment_gateways()`](https://woocommerce.github.io/code-reference/classes/WC-Payment-Gateways.html#method_get_available_payment_gateways), REST requests through [`rest_get_server()->dispatch()`](https://developer.wordpress.org/reference/classes/wp_rest_server/dispatch/), actions and output through the real hook.
- Mock an external request or collaborator at its direct boundary. Don't use an unrelated filter just to control setup.
- Remove hooks and options a test creates.
- Test behavior the plugin owns, not WooCommerce's.

## JavaScript tests

Jest tests under `tests/js/` cover each browser script and the settings app.

- Browser-script tests load the file from `assets/js/` into JSDOM, with fakes for `Blackbox`, `jQuery`, [`wp.data`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-data/), [`wp.hooks`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-hooks/) and `fetch`, and drive it with events.
- Settings app tests (`*.test.tsx`) render components with Testing Library.
- `@wordpress/ui` injects its CSS at runtime, which doesn't happen in Jest. Check styling in a browser.

[Browser scripts](browser-scripts.md#testing-scripts) lists what each script test should cover.

## Smoke tests

`tests/php/smoke/` runs each scenario under `scenarios/` as a separate PHP process with minimal WordPress and WooCommerce stubs. They prove that the plugin degrades instead of crashing the store, and loads the right copy:

- missing dependencies: no WooCommerce, a missing loader target, a missing autoloader, a missing Store API schema, a `null` WooCommerce version, no mailer, an old WooCommerce, an old PHP version;
- failures at runtime: a failing service, a report without context;
- copies and loading: the must-use copy yielding to a regular copy, activating the regular plugin while the must-use copy runs, network activation, managed asset URLs, and a direct request to the loader.

The PHP version scenario and the stubs it loads must stay parseable on PHP 7.4, like the plugin's entry points.

## Example integration tests

`tests/php/examples/` and `tests/js/example-gateway-express.test.js` run the plugins under `examples/` against the plugin. They prove that the public API works the way the [integration guide](../integration-guide.md) says. When the public API changes, keep the examples and these tests working.

## Continuous integration

| Workflow | Runs |
|----------|------|
| `php-lint-test.yml` | PHPCS, PHPStan, smoke tests, PHPUnit; lints the entry files and runs the kill-switch scenario on PHP 7.4 and 8.0 |
| `js-lint-test.yml` | JavaScript, CSS and type linting; Jest |
| `php-compatibility.yml` | PHPUnit on PHP 8.1 to 8.5 |
| `compatibility.yml` | Tests against other WooCommerce and WordPress versions |
| `coverage.yml` | Coverage reports |
| `qit.yml` | WooCommerce QIT activation and security tests |
| `release.yml` | Release build, QIT, and publication |
| `ai-code-review.yml` | Automated AI review on pull requests |

## What to test

For each change, ask what would break for a shopper, a merchant, or an extension, and test that:

- **Enforcement paths first.** A block must stop the flow with the right mechanism and message, and an allow must let it continue.
- **Fail open.** Make each dependency fail (service error, thrown filter, missing session, missing Jetpack) and check that the failure itself doesn't block the attempt and is logged. That isn't always an allow: without a session ID, verification still runs and its decision applies, and a matching merchant rule applies whatever failed.
- **Hook wiring.** Calling a callback directly doesn't prove it's attached to the right hook with the right priority. Go through the real flow, or at least assert the registration.
- **Invariants where they're enforced.** A "blocks don't follow the shopper" test belongs at the protector level, across two attempts, not on a stateless helper.
- **Every contract value.** All enum cases, both decisions, each missing-session reason.
- **Native integrations.** For a compatibility layer: it works, it stands aside for its declared feature, and it stays on for the others.
- **Pinned public literals.** Field names, handles and hook names that extensions depend on.

## Manual testing

Unit tests can't reproduce browser timing, real gateways, or real service answers. Before handoff, test the flows your change touches in the local store from the [README](../../README.md#local-dev-site-wp-env):

- **Classic and block checkout**, with the dummy gateway and, for gateway changes, the real gateway in test mode.
- **Pay for order and Add payment method.**
- **Express buttons** on product and cart pages.
- **Without the SDK**: block `blackbox-api.wp.com` in the browser's developer tools and check that payments go through, with the right reason in the log.
- **A second attempt** on the same page after a failure, checking the session ID in the request changes.
- **Firefox** for form submission changes.

The plugin's log (WooCommerce > Status > Logs, source `woo-fraud-protection`) shows each verification with its source and session ID. On a store connected to the live service, follow the README's live-testing steps and use test data only.

[Chapter 5 of the tutorial](../tutorial/05-reviewing-changes.md#reproduce-dont-just-read) lists reproduction techniques that found real bugs.
