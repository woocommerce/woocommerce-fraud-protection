# PHP Linting Patterns and Common Issues

> **IMPORTANT:** Run `nvm use` before any `npm` or `npx` command. phpcs itself runs from `vendor/bin/` after `composer install`.

## Table of Contents

- [Lint Scope](#lint-scope)
- [Common PHP Linting Issues & Fixes](#common-php-linting-issues--fixes)
- [Translators Comment Placement](#translators-comment-placement)
- [File Header Order](#file-header-order)
- [Inline phpcs Ignores](#inline-phpcs-ignores)
- [Stubs and Mock Classes with Intentional Violations](#stubs-and-mock-classes-with-intentional-violations)
- [Multi-line Condition Alignment](#multi-line-condition-alignment)
- [Unused Closure Parameters](#unused-closure-parameters)
- [Array and Operator Alignment](#array-and-operator-alignment)
- [Indentation Rules](#indentation-rules)
- [Workflow for Fixing PHP Linting Issues](#workflow-for-fixing-php-linting-issues)
- [Quick Command Reference](#quick-command-reference)

## Lint Scope

CI runs `composer phpcs` over the whole repository, so every file must pass. While iterating, lint only the files you touched to keep the output readable, then run the full command once before handoff.

```bash
# ✅ Quick: lint the files you changed
vendor/bin/phpcs src/Internal/FraudProtectionPlugin/Rules/RuleStore.php tests/php/src/Internal/FraudProtectionPlugin/Rules/RuleStoreTest.php

# ✅ Quick: lint every PHP file changed on the branch
git diff --name-only trunk... -- '*.php' | xargs vendor/bin/phpcs

# ✅ Before handoff: the full run CI performs
npm run lint:php
```

Do not fix unrelated files in the same pull request unless asked.

## Common PHP Linting Issues & Fixes

### Quick Reference Table

| Issue | Wrong | Correct |
|-------|-------|---------|
| **Translators comment** | Before return | Before the translation function call |
| **File docblock** | After `declare()` | Before `declare()` |
| **Text domain** | `'woocommerce'` | `'woocommerce-fraud-protection'` |
| **Indentation** | Spaces | Tabs only |
| **Array alignment** | Inconsistent | Align `=>` with context |
| **Equals alignment** | Inconsistent | Match surrounding style |
| **Missing `@throws`** | Method throws without a tag | Add `@throws` to the docblock (required under `src/`) |

## Translators Comment Placement

Translators comments must be placed **immediately before the translation function call**, not before the return statement.

### Wrong - Comment Before Return

```php
/* translators: %s: Payment method title. */
return sprintf(
    esc_html__( '%s is not supported.', 'woocommerce-fraud-protection' ),
    $title
);
```

### Correct - Comment Before Translation Function

```php
return sprintf(
    /* translators: %s: Payment method title. */
    esc_html__( '%s is not supported.', 'woocommerce-fraud-protection' ),
    $title
);
```

### Multiple Parameters

```php
return sprintf(
    /* translators: 1: Installed schema version, 2: Required schema version. */
    esc_html__( 'Schema version %1$s is installed; version %2$s is required.', 'woocommerce-fraud-protection' ),
    $installed_version,
    $required_version
);
```

## File Header Order

The file docblock comes **before** the `declare()` statement. Files under `src/` and `tests/php/` do not need a `@package` tag; the existing convention is a one-line `{ClassName} class file.` docblock.

### Wrong - Docblock After declare()

```php
<?php
declare( strict_types=1 );

/**
 * RuleStore class file.
 */
```

### Correct - Docblock Before declare()

```php
<?php
/**
 * RuleStore class file.
 */

declare( strict_types=1 );

namespace Automattic\WooCommerce\Internal\FraudProtectionPlugin\Rules;
```

## Inline phpcs Ignores

Use a targeted `phpcs:ignore` naming the sniff and giving a reason, as the existing code does:

```php
// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by WooCommerce form handler.
$request_data = $this->build_request_data( $_POST );
```

```php
protected function assertLogged( string $level, string $substring ): void { // phpcs:ignore WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid -- PHPUnit style.
```

Never disable a sniff for a whole file without a reason. `PluginInitializer` uses a targeted ignore for its pre-logger `error_log()` call; do not copy that into code that can use `FraudProtectionController::log()`.

## Stubs and Mock Classes with Intentional Violations

Stubs of third-party or global classes used by tests live in `tests/php/stubs/` (for example the Square gateway stub) and test doubles live in `tests/php/Support/`. PHPStan stubs for the same classes live in `stubs/` at the repository root. Follow the header of the existing files there.

When a class must carry a name that violates the naming sniffs (for example a stub for a global `WC_` class), guard it with `class_exists()` and disable the specific sniffs in its docblock:

```php
if ( ! class_exists( 'WC_Payments_Utils' ) ) {
    /**
     * Mock class for testing.
     *
     * phpcs:disable Squiz.Classes.ClassFileName.NoMatch
     * phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
     */
    class WC_Payments_Utils {
        /**
         * Mock implementation.
         */
        public static function supported_countries() {
            return array( 'US', 'GB' );
        }
    }
}
```

## Multi-line Condition Alignment

Use tabs for continuation lines in multi-line conditions:

```php
// Correct - tabs for continuation
if ( class_exists( '\WC_Payments_Utils' ) &&
    is_callable( '\WC_Payments_Utils::supported_countries' ) ) {
    // code
}

// Also correct - align with opening parenthesis
if ( class_exists( '\WC_Payments_Utils' ) &&
     is_callable( '\WC_Payments_Utils::supported_countries' ) ) {
    // code
}
```

## Unused Closure Parameters

When creating closures with parameters required by signature but unused, use `unset()` to avoid PHPCS errors:

### The Problem

```php
// ❌ WRONG - PHPCS error: Generic.CodeAnalysis.UnusedFunctionParameter
'callback' => function ( string $return_url ) {
    return array( 'success' => true );
},
```

### The Solution

```php
// ✅ CORRECT - unset unused parameters
'callback' => function ( string $return_url ) {
    unset( $return_url ); // Avoid parameter not used PHPCS errors.
    return array( 'success' => true );
},
```

### Multiple Unused Parameters

```php
'callback' => function ( $arg1, $arg2, $arg3 ) {
    unset( $arg1, $arg2 ); // Avoid parameter not used PHPCS errors.
    return $arg3;
},
```

### Common Scenarios

- Mock method callbacks in PHPUnit tests (`willReturnCallback()`)
- Hook callbacks that receive arguments they do not need; alternatively register the callback with `0` accepted arguments, as the plugin does for `before_woocommerce_pay_form`
- Interface implementations with unused parameters

## Array and Operator Alignment

### Array Arrow Alignment

Align `=>` arrows consistently within each array context:

```php
// Correct - aligned arrows
$context = array(
    'session_id'   => $session_id,
    'source'       => $source,
    'decision'     => $decision->value,
);

// Also correct - no alignment for short arrays
$small = array(
    'id' => 123,
    'name' => 'Test',
);
```

### Assignment Operator Alignment

Match the surrounding code style:

```php
// When surrounding code aligns, align:
$session_id   = 'abc';
$source       = 'blocks_checkout';
$order_id     = 42;

// When surrounding code doesn't align, don't align:
$session_id = 'abc';
$source = 'blocks_checkout';
$order_id = 42;
```

## Indentation Rules

**Always use tabs, never spaces, for indentation.**

```php
// ✅ Correct - tabs for indentation
public function verify_and_block( \WC_Order $order ): void {
→   $decision = $this->session_verifier->verify_session( $session_id, self::SOURCE, $order->get_id(), $request_data );
→
→   if ( FraudDecision::Block === $decision ) {
→   →   wc_add_notice( $message, 'error' );
→   }
}

// ❌ Wrong - spaces for indentation
public function verify_and_block( \WC_Order $order ): void {
    $decision = $this->session_verifier->verify_session( $session_id, self::SOURCE, $order->get_id(), $request_data );

    if ( FraudDecision::Block === $decision ) {
        wc_add_notice( $message, 'error' );
    }
}
```

## Workflow for Fixing PHP Linting Issues

1. **Lint the changed files:**

   ```bash
   vendor/bin/phpcs -s path/to/file.php
   ```

2. **Auto-fix what you can:**

   ```bash
   vendor/bin/phpcbf path/to/file.php
   ```

3. **Review remaining errors** - Common issues that require manual fixing:
   - Translators comment placement
   - File docblock order
   - Missing `@throws` tags
   - Unused closure parameters (add `unset()`)

4. **Address remaining issues manually**

5. **Verify the output is clean, then run the full lint before handoff:**

   ```bash
   vendor/bin/phpcs path/to/file.php
   npm run lint:php
   ```

## Quick Command Reference

```bash
# Check specific files
vendor/bin/phpcs src/Internal/FraudProtectionPlugin/Rules/RuleStore.php

# Check with sniff codes
vendor/bin/phpcs -s path/to/file.php

# Fix specific file
vendor/bin/phpcbf path/to/file.php

# Whole repository (CI)
npm run lint:php
npm run lint:php:autofix
```
