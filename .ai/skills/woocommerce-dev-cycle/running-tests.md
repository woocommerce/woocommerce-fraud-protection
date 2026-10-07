# Running Tests

> **IMPORTANT:** Node.js 24 is required (`.nvmrc`). Run `nvm use` before any `npm` or `npx` command, e.g. `nvm use && npm run test:php:env`. The repository uses npm, not pnpm.

## Table of Contents

- [Test Suites](#test-suites)
- [PHP Unit Tests](#php-unit-tests)
    - [Basic Test Commands](#basic-test-commands)
    - [Test Environment](#test-environment)
    - [Running PHPUnit Directly](#running-phpunit-directly)
    - [Troubleshooting PHP Tests](#troubleshooting-php-tests)
- [PHP Smoke Tests](#php-smoke-tests)
- [JavaScript Tests](#javascript-tests)
- [Interpreting Test Output](#interpreting-test-output)
- [Best Practices](#best-practices)

## Test Suites

| Suite | Command | What it covers |
| ----- | ------- | -------------- |
| PHP unit tests | `npm run test:php:env` | PHPUnit in an isolated wp-env container with WordPress, WooCommerce, and the WooCommerce test framework |
| PHP smoke tests | `npm run test:smoke` | Startup-hardening scenarios under `tests/php/smoke/`, each run as an isolated PHP process with minimal stubs and no WordPress |
| JavaScript tests | `npm run test:js` | Jest over `assets/js/` and `client/` |
| Everything | `npm run test` | Smoke, PHP, and JavaScript suites in sequence |

## PHP Unit Tests

### Basic Test Commands

```bash
# Run all PHP unit tests
npm run test:php:env

# Run specific test class
npm run test:php:env -- --filter RuleStoreTest

# Run specific test method
npm run test:php:env -- --filter RuleStoreTest::test_rejects_duplicate_active_rule

# Run all tests in a directory
npm run test:php:env -- tests/php/src/Internal/FraudProtectionPlugin/Rules/

# Run tests matching a pattern
npm run test:php:env -- --filter "Protector"

# Stop on first failure
npm run test:php:env -- --stop-on-failure --filter RuleStoreTest
```

Everything after `--` is passed to PHPUnit unchanged.

### Test Environment

`npm run test:php:env` runs `bin/run-php-tests-wp-env.sh`, which:

1. Starts a dedicated wp-env instance from `.wp-env.phpunit.json` (PHP 8.1, port 8889, the latest WooCommerce). This is separate from the development store started by `npm run env:start` and does not touch its data.
2. On first run, installs the WooCommerce test framework matching the installed WooCommerce version inside the container.
3. Runs `vendor/bin/phpunit` inside the container with the arguments you passed.

The first run is slow because it creates the environment; later runs reuse it. If port 8889 is in use, add a gitignored `.wp-env.phpunit.override.json` with another `port`. Each worktree gets its own containers.

To stop or reset the test environment, run `wp-env stop` or `wp-env destroy` with the same `--config=.wp-env.phpunit.json` argument the script passes to `wp-env start`.

### Running PHPUnit Directly

`npm run test:php` runs `vendor/bin/phpunit` against an existing WordPress test installation on the host. It needs the WordPress test library, a MySQL database, and the WooCommerce test framework; CI prepares them with `tests/bin/install-wp-tests.sh`. Set `WC_DIR` to a WooCommerce plugin directory when the bootstrap cannot locate it. Prefer `test:php:env` locally.

### Troubleshooting PHP Tests

| Problem | Solution |
| ------- | -------- |
| "Class not found" for plugin or vendor classes | `composer install` (regenerates the autoloader) |
| "wp-env is unavailable" | `npm install` |
| Tests hang or the container is stale | Stop the test environment and rerun; destroy it if that is not enough |
| Docker: "all predefined address pools have been fully subnetted" | Remove orphaned `<hash>_default` Docker networks (`docker network ls`, `docker network rm`) |
| Permission errors | Check Docker permissions |
| Xdebug warnings | Ignore (they don't affect results) |

## PHP Smoke Tests

```bash
npm run test:smoke        # all scenarios
tests/php/smoke/run.sh -v # stream each scenario's output
```

Each `tests/php/smoke/scenarios/*.php` file boots the plugin entry points with the stubs in `tests/php/smoke/stubs/` and must print `OK`. They cover the kill switches, missing autoloader, missing WooCommerce, and similar startup failures. CI also parses the two entry points on PHP 7.4 and 8.0 to prove the unsupported-PHP kill switch still loads there.

## JavaScript Tests

```bash
# Run all JavaScript tests
npm run test:js

# Run tests in watch mode
npm run test:js -- --watch

# Run test files matching a pattern
npm run test:js -- rules-store

# Clear the Jest cache
npm run test:js -- --clearCache
```

- Runner: `wp-scripts test-unit-js` with the `@wordpress/jest-preset-default` preset. The configuration is the `jest` block in `package.json`; there is no separate Jest config file.
- Tests live in `tests/js/` as `*.test.js`, `*.test.ts`, or `*.test.tsx`. Shared mocks live in `tests/js/mocks/`.
- `tests/js/setup.js` adds JSDOM shims (Fetch `Request`, `PointerEvent`, `ResizeObserver`, the `:modal` selector, `HTMLFormElement.submit`) and silences the CSS-parsing errors `@wordpress/ui` triggers in JSDOM. `tests/js/jest-global-setup.js` pins the timezone to `America/New_York` so date assertions are deterministic.
- Browser scripts in `assets/js/` are IIFEs. Their tests start with `/** @jest-environment jsdom */`, set up `window` globals (`wcFraudProtection`, `wp`, `wc`, jQuery), `require()` the script, and assert on the callbacks it registered.
- `client/` components are tested with `@testing-library/react` and `@testing-library/user-event`, with `@wordpress/api-fetch` mocked through `jest.mock()` and a fresh `@wordpress/data` registry created per render.

## Interpreting Test Output

### Successful Test Run

```text
PHPUnit 9.6.x

..................................................  50 / 100 ( 50%)
..................................................  100 / 100 (100%)

Time: 00:02.345, Memory: 24.00 MB

OK (100 tests, 250 assertions)
```

### Failed Test

```text
There was 1 failure:

1) Automattic\WooCommerce\Tests\Internal\FraudProtectionPlugin\Rules\RuleStoreTest::test_rejects_duplicate_active_rule
A duplicate active rule must be rejected
Failed asserting that false is true.

/var/www/html/wp-content/plugins/<worktree>/tests/php/src/Internal/FraudProtectionPlugin/Rules/RuleStoreTest.php:123

FAILURES!
Tests: 100, Assertions: 250, Failures: 1.
```

Test failures provide:

- **Which test failed:** Test class and method name, plus the data set name when a data provider is used
- **Expected vs actual:** What was expected and what was received
- **Location:** File and line number where the assertion failed (paths are inside the container; the plugin directory is named after the worktree)

## Best Practices

### During Development

1. **Run specific tests** for the code you're changing with `--filter`
2. **Stop on first failure** to focus on one issue at a time
3. **Run the JavaScript tests** whenever you touch `assets/js/` or `client/`

### Before Handoff

1. **Run all affected tests**, for example the directory that contains the changed component's tests
2. **Run `npm run test:smoke`** when you touch the entry points, `PluginInitializer`, or startup behavior
3. **Ensure all tests pass**, then run the code quality checks (see code-quality.md)
4. **Use the local test store** (`npm run env:start`) for customer or merchant flows that unit tests cannot prove
