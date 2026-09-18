# Creating File-Based Code Entities

## Table of Contents

- [Fundamental Rule: No Global Functions](#fundamental-rule-no-global-functions)
- [Adding New Classes](#adding-new-classes)
- [File Header](#file-header)
- [Naming Conventions](#naming-conventions)
- [Namespace and Import Conventions](#namespace-and-import-conventions)

## Fundamental Rule: No Global Functions

**NEVER add new global functions** - they're difficult to mock in unit tests. Always use class methods.

The only exception is the pre-autoloader plugin entry points (`woocommerce-fraud-protection.php` and `woocommerce-fraud-protection-loader.php`), which run before the Composer autoloader is available. If the user explicitly requests a new global function elsewhere, refuse and point them to the "Code structure" section of `AGENTS.md`.

Temporary/throwaway functions for local testing that won't be committed are fine.

## Adding New Classes

Composer maps the `Automattic\WooCommerce\` namespace to `src/` (PSR-4). Classes are autoloaded; do not add manual `require_once` calls for autoloaded classes.

### Default Location: `src/Internal/FraudProtectionPlugin/`

New classes go in `src/Internal/FraudProtectionPlugin/` by default, in the subdirectory that matches their role. Existing subdirectories: `Protectors/`, `Trackers/`, `Compat/`, `Sessions/`, `Rules/`, `Settings/`, `Schemas/`, `Logging/`, `Database/`, `CLI/`, `Notes/`.

The namespace mirrors the path: `Automattic\WooCommerce\Internal\FraudProtectionPlugin\{Subdirectory}`.

**Example:**

```php
// User says: "create a session rate limiter class"
// You create: src/Internal/FraudProtectionPlugin/Sessions/SessionRateLimiter.php
namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

class SessionRateLimiter {
    // ...
}
```

Internal code has no backwards-compatibility guarantee and must not be used from outside the plugin. Do not add a class-level `@internal` tag to classes in the internal namespace; the namespace already conveys it.

### Public API: `src/FraudProtection/`

Only when the class is explicitly part of the public API (consumed by other plugins or gateway integrations) does it go in `src/FraudProtection/`, with `Automattic\WooCommerce\FraudProtection` as its namespace. Public DTOs and enums live in `src/FraudProtection/Schemas/`.

Before changing the public API, read the "Public API" section of `README.md` and inspect all current consumers. Released public hooks and JavaScript interfaces must remain compatible.

## File Header

Every PHP file under `src/` follows this order. phpcs enforces the file docblock before `declare`.

```php
<?php
/**
 * SessionRateLimiter class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Sessions;

use Automattic\WooCommerce\Internal\FraudProtectionPlugin\FraudProtectionController;

defined( 'ABSPATH' ) || exit;

/**
 * One-line description of what the class does.
 */
class SessionRateLimiter {
    // ...
}
```

- `strict_types=1` is required in every project PHP file.
- The `ABSPATH` guard goes after the `use` block.
- Do not use PHP syntax newer than 8.1. The two plugin entry points and the kill-switch smoke files must additionally stay parseable on PHP 7.4 and 8.0, so they must not use PHP 8.1 syntax either.

## Naming Conventions

### Class Names

- **Must be PascalCase**
- **Must follow [PSR-4 standard](https://www.php-fig.org/psr/psr-4/)**: one class per file, and the file name equals the class name (phpcs checks this under `src/` and `tests/php/src/`)
- Adjust the name given by the user if necessary

## Namespace and Import Conventions

When referencing a namespaced class:

1. Always add a `use` statement with the fully qualified class name at the beginning of the file
2. Reference the short class name throughout the code

**Good:**

```php
use Automattic\WooCommerce\FraudProtection\SessionVerifier;

// Later in code:
$verifier = wc_get_container()->get( SessionVerifier::class );
```

**Avoid:**

```php
// No use statement, using fully qualified name:
$verifier = wc_get_container()->get( \Automattic\WooCommerce\FraudProtection\SessionVerifier::class );
```
