## Requirements

- PHP 8.1+
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
npm run build:release   # production build plus a distributable plugin zip
```

The assets in `assets/` are served as-is, so a build step is not required for everyday development.

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

All the code in the `src/Internal/` directory (`Automattic\WooCommerce\Internal` as the root namespace) is for **exclusive internal usage** of the plugin and **MUST NOT** be used by other plugins (or otherwise from outside of this plugin): backwards compatibility for this code across plugin versions is not guaranteed.
