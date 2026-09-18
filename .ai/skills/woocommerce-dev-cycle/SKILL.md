---
name: woocommerce-dev-cycle
description: Run tests, linting, and quality checks for WooCommerce Fraud Protection development. Use when running tests, fixing code style, or following the development workflow.
---

# WooCommerce Fraud Protection Development Cycle

This skill provides guidance for the plugin's development workflow, including running tests, code quality checks, and troubleshooting.

## Prerequisites

- Node.js 24 (`.nvmrc`). **Always run `nvm use` before any `npm` or `npx` command**, e.g. `nvm use && npm run test:php:env`. The repository uses npm, not pnpm.
- PHP 8.1 and Composer for the PHP toolchain, Docker for the wp-env stores.
- Install dependencies once with `composer install` and `npm install`.

## Instructions

Follow these guidelines for the development workflow:

1. **Running tests**: See [running-tests.md](running-tests.md) for the PHP, smoke, and JavaScript test commands, the test environment, and troubleshooting
2. **Code quality**: See [code-quality.md](code-quality.md) for linting, static analysis, and code style fixes
3. **PHP linting patterns**: See [php-linting-patterns.md](php-linting-patterns.md) for common PHP linting issues and fixes
4. **JS/TS i18n patterns**: See [js-i18n-patterns.md](js-i18n-patterns.md) for translatable string patterns and placeholder usage
5. **Markdown**: See [markdown-linting.md](markdown-linting.md) for markdown file conventions

## Development Workflow

The standard development workflow:

1. Make code changes
2. Run the relevant tests: `nvm use && npm run test:php:env -- --filter YourTestClass` for PHP, `npm run test:js` for JavaScript
3. Lint and analyse what you changed: `vendor/bin/phpcs path/to/file.php` and `npm run phpstan` for PHP; `npm run lint:js`, `npm run lint:types`, and `npm run lint:css` for front-end files
4. Fix any issues: `vendor/bin/phpcbf path/to/file.php` (or `npm run lint:php:autofix` for the whole tree), `npx wp-scripts lint-js --fix path/to/file`
5. Before handoff, run the checks that match the changed files and behavior. `npm run test` runs the smoke, PHP, and JavaScript suites; `npm run lint` runs every linter
6. Use the local wp-env store (`npm run env:start`) for customer or merchant flows that unit tests cannot prove. Use the live-service process described in `README.md` only when the change requires an end-to-end service check, because it sends real traffic

## Repository Layout for Front-End Code

- `assets/js/`: plain browser scripts (Blocks and shortcode checkout, pay-for-order, add-payment-method, PayPal, Blackbox init). They are served as-is with no build step and contain no user-facing text. Tested in `tests/js/*.test.js`.
- `client/`: the React + TypeScript admin settings application (`admin-settings/`, `admin-checkout-attempts/`), built with `wp-scripts` into `build/`. Run `npm start` while developing it or `npm run build` before loading the settings page. It uses `@wordpress/components`, `@wordpress/ui`, `@wordpress/dataviews`, and `@wordpress/data`. Tested in `tests/js/*.test.tsx`.
- `build/` is generated. Changes under `assets/` need no build.

## Key Principles

- Always run tests after making changes to verify functionality
- Use specific test filters to run relevant tests during development
- The whole repository must pass linting; fix what you touch and do not reformat unrelated files in the same pull request
- Test failures provide detailed output showing expected vs actual values
- The PHP test environment handles WordPress and WooCommerce setup automatically
