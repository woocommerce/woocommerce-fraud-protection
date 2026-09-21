# Creating Code Entities (Methods, Variables, Parameters)

## Table of Contents

- [Naming Conventions](#naming-conventions)
- [Method Visibility](#method-visibility)
- [Static Methods](#static-methods)
- [Docblock Requirements](#docblock-requirements)
    - [`@since` Annotations](#since-annotations)
    - [`@throws` Annotations](#throws-annotations)
    - [Private Methods and Internal Callbacks](#private-methods-and-internal-callbacks)
    - [@internal Annotation Placement](#internal-annotation-placement)
- [Hook Docblocks](#hook-docblocks)

## Naming Conventions

Use snake_case for methods, variables, and hooks (not camelCase or PascalCase).

**Examples:**

```php
// Correct
public function verify_session() { }
private $session_verifier;
```

## Method Visibility

New class methods should be `private` by default.

**Use `protected`** only if it's clear the method will be used in derived classes.

**Use `public`** only if the method will be called from outside the class. Hook callbacks and the `init()` dependency method must be public; mark them `@internal` (see below) so they are not treated as public API.

**Examples:**

```php
class RuleEvaluator {
    // Default: private for internal helpers
    private function normalize_value( string $value ): string { }

    // Protected: for use in child classes
    protected function get_operator( string $name ): ConditionOperator { }

    // Public: called from other classes
    public function evaluate( array $session_data ): ?Rule { }
}
```

## Static Methods

Pure methods (output depends only on inputs, no external dependencies) must be `static`.

**Examples of pure methods (should be static):**

```php
// String manipulations
public static function normalize_email( string $email ): string {
    return strtolower( trim( $email ) );
}

// Data transformations
public static function normalize_address( array $address ): array {
    return array_map( 'trim', $address );
}
```

**Examples of non-pure methods (should NOT be static):**

```php
// Depends on database
public function find_rule( string $value ): ?Rule { }

// Depends on system time
public function is_recent( int $timestamp ): bool { }

// Uses object state
public function verify_session( string $session_id ): FraudDecision {
    return $this->api_client->verify( $session_id );
}
```

**Exception:** Non-pure methods should not be `static` unless there's a specific architectural reason. The plugin's own example is the `FraudProtectionController::log()` facade.

## Docblock Requirements

Add concise docblocks to all hooks and methods. One line is ideal.

### `@since` Annotations

WooCommerce Core requires `@since` on every public method. This plugin applies it only to its public contract:

- Public hooks (actions and filters fired by the plugin)
- The public API in `src/FraudProtection/` (classes, public methods, constructors, enum cases)

Internal classes and their methods do not carry `@since`, and phpcs does not require it here (the `MissingSinceComment` sniff is excluded in `phpcs.xml`).

When present on a method, the `@since` annotation must be:

- The last line in the docblock
- Preceded by a blank comment line
- The plugin version from the placeholder release block in `changelog.txt` (see "Version Information" in [SKILL.md](SKILL.md))

Hook docblocks in this plugin place the `@since` lines before the `@param` list instead; see [hooks.md](hooks.md).

**Good - Concise:**

```php
/**
 * The effective session ID of the last completed verification.
 *
 * @return string The response session ID, or an empty string.
 *
 * @since 0.2.6
 */
public function last_verified_session_id(): string { }
```

**Avoid - Over-explained:**

```php
/**
 * This method returns the session ID that was resolved by the last verification,
 * which is the identifier that the verification persisted and to which the
 * outcome of that verification is attached, so callers can use it as a key.
 *
 * @return string Returns the session identifier string resolved by the last verification, or an empty string if there was none.
 *
 * @since 0.2.6
 */
```

### `@throws` Annotations

phpcs requires a `@throws` tag on every method under `src/` that throws (the sniff is relaxed only for tests). Name the exception class and say when it is thrown.

```php
/**
 * Store a rule.
 *
 * @param Rule $rule The rule to store.
 * @return int The stored rule ID.
 *
 * @throws DuplicateRuleException When an active rule with the same value already exists.
 */
public function save( Rule $rule ): int { }
```

### Private Methods and Internal Callbacks

Do NOT require a `@since` annotation if they are:

- Private methods
- Hook callbacks and `init()` methods (marked with `@internal`)

**Example:**

```php
/**
 * Internal helper to validate rule values.
 *
 * @param string $value The value to validate.
 * @return bool
 */
private function is_valid_value( string $value ): bool { }
```

### @internal Annotation Placement

When an `@internal` annotation is added, it must be:

- Placed after the method description
- Placed before the arguments list
- Have a blank comment line before and after

**Example:**

```php
/**
 * Verify the session and block the payment on a Block decision.
 *
 * @internal
 *
 * @param \WC_Order $order The order being paid for.
 * @return void
 */
public function verify_and_block( \WC_Order $order ): void { }
```

## Hook Docblocks

For information about documenting hooks (including adding docblocks to existing hooks), see [hooks.md](hooks.md).
