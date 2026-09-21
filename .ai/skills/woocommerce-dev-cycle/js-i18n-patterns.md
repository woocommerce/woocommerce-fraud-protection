# JavaScript/TypeScript i18n Patterns

> **IMPORTANT:** Run `nvm use` before any `npm` or `npx` command, e.g. `nvm use && npx wp-scripts lint-js client/path/to/file.tsx`.

## Table of Contents

- [Overview](#overview)
- [Translation Functions](#translation-functions)
- [Placeholder Patterns](#placeholder-patterns)
- [Translator Comments](#translator-comments)
- [Complex String Patterns](#complex-string-patterns)
- [Common Pitfalls](#common-pitfalls)
- [Quick Command Reference](#quick-command-reference)

## Overview

The admin application in `client/` uses the WordPress i18n functions from `@wordpress/i18n`. Every user-facing string uses the `woocommerce-fraud-protection` text domain, written as a literal string so it can be extracted. `npm run makepot` builds `languages/woocommerce-fraud-protection.pot` from the compiled `build/` output, `assets/`, and `src/`, and `FraudProtectionSettingsPage` registers the script translations with `wp_set_script_translations()`.

```typescript
import { __, _n, sprintf } from '@wordpress/i18n';
```

The browser scripts in `assets/js/` contain no user-facing text. Customer-facing messages come from the server through `BlockedSessionMessage`; do not add them to the browser scripts.

## Translation Functions

### Basic Translation

```typescript
// Simple string
__( 'Delete rule', 'woocommerce-fraud-protection' )

// String with placeholder
sprintf(
	// translators: %s is a date.
	__( 'Allow rule created %s', 'woocommerce-fraud-protection' ),
	formattedDate
)
```

### Plural Forms with `_n`

```typescript
import { _n, sprintf } from '@wordpress/i18n';

sprintf(
	// translators: %d: Number of checkout attempts.
	_n(
		'%d checkout attempt',
		'%d checkout attempts',
		count,
		'woocommerce-fraud-protection'
	),
	count
)
```

### Interpolated Elements with `createInterpolateElement`

```typescript
import { createInterpolateElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

createInterpolateElement(
	__(
		'Automatic fraud prevention is off. <a>Learn more</a>',
		'woocommerce-fraud-protection'
	),
	{
		a: <a href={ documentationUrl } target="_blank" rel="noopener noreferrer" />,
	}
)
```

## Placeholder Patterns

### Single Placeholder

Use `%s` for a single placeholder:

```typescript
sprintf(
	// translators: %s: The email address or IP address the rule applies to.
	__( 'Block %s', 'woocommerce-fraud-protection' ),
	rule.value
)
```

### Multiple Same Placeholders

Use numbered placeholders `%1$s` when the same value appears multiple times:

```typescript
sprintf(
	// translators: 1: The rule value.
	__(
		'Delete the rule for %1$s? Checkout attempts from %1$s will no longer match it.',
		'woocommerce-fraud-protection'
	),
	rule.value
)
```

### Multiple Different Placeholders

Use numbered placeholders `%1$s`, `%2$s` for different values:

```typescript
sprintf(
	// translators: 1: Rule action (allow or block), 2: Rule type (email or IP address).
	__( '%1$s rule for %2$s', 'woocommerce-fraud-protection' ),
	actionLabel,
	typeLabel
)
```

## Translator Comments

### Comment Placement

The translator comment must be placed **immediately before the `__()` or `_n()` call**, not before `sprintf()`. The plugin's TypeScript files use line comments; block comments (`/* translators: ... */`) are also accepted.

```typescript
// ❌ WRONG - Comment before sprintf
// translators: %s is a date.
sprintf(
	__( 'Allow rule created %s', 'woocommerce-fraud-protection' ),
	formattedDate
)

// ✅ CORRECT - Comment inside sprintf, before __()
sprintf(
	// translators: %s is a date.
	__( 'Allow rule created %s', 'woocommerce-fraud-protection' ),
	formattedDate
)

// ✅ CORRECT - Comment before a __() call assigned to a variable
// translators: %s is a date.
const template = __( 'Allow rule updated %s', 'woocommerce-fraud-protection' );
```

### Comment Format for Numbered Placeholders

When using numbered placeholders like `%1$s`, use just the number in the comment:

```typescript
// ❌ WRONG - Using %1$s in comment
// translators: %1$s: Rule action, %2$s: Rule type.

// ✅ CORRECT - Using just numbers
// translators: 1: Rule action, 2: Rule type.
```

### Descriptive Comments

Always provide context for translators:

```typescript
// ❌ WRONG - No context
// translators: %s: value

// ✅ CORRECT - Clear context
// translators: %s: The email address or IP address the rule applies to.
```

## Complex String Patterns

### Combining `sprintf`, `_n`, and `createInterpolateElement`

```typescript
const summary = createInterpolateElement(
	sprintf(
		// translators: 1: Number of blocked attempts, 2: Number of days.
		_n(
			'<strong>%1$d</strong> attempt blocked in the last %2$d days.',
			'<strong>%1$d</strong> attempts blocked in the last %2$d days.',
			blockedCount,
			'woocommerce-fraud-protection'
		),
		blockedCount,
		days
	),
	{ strong: <strong /> }
);
```

### Strings with Links

```typescript
createInterpolateElement(
	__(
		'Rules apply to every checkout attempt. <a>Learn how rules work</a>.',
		'woocommerce-fraud-protection'
	),
	{
		a: (
			<a
				href={ documentationUrl }
				target="_blank"
				rel="noopener noreferrer"
			/>
		),
	}
)
```

## Common Pitfalls

### Curly Apostrophes

Existing strings may use curly apostrophes (`’` U+2019) instead of straight apostrophes (`'` U+0027). When editing, preserve the original character so the string keeps matching its translations:

```typescript
// Original uses curly apostrophe - preserve it
__( 'We couldn’t save the rule.', 'woocommerce-fraud-protection' )
//           ^ This is U+2019, not U+0027
```

### ESLint i18n Rules

The `@wordpress/i18n-translator-comments` rule requires the comment directly before the translation function, and `@wordpress/i18n-text-domain` requires the literal `woocommerce-fraud-protection` domain:

```typescript
// ❌ ESLint error - comment not adjacent to __()
const title = sprintf(
	// translators: %s is a date.

	__( 'Allow rule created %s', 'woocommerce-fraud-protection' ),
	formattedDate
);

// ❌ ESLint error - wrong or missing text domain
const title = __( 'Delete rule', 'woocommerce' );
const title = __( 'Delete rule' );

// ✅ Correct
const title = sprintf(
	// translators: %s is a date.
	__( 'Allow rule created %s', 'woocommerce-fraud-protection' ),
	formattedDate
);
```

### Third-Party Names

When a string mentions a third-party product (a payment gateway, for example), pass the name as a placeholder so translators get one string regardless of the gateway. Plugin and WordPress names that never change may stay literal.

### Private Behavior

Merchant-facing copy may explain what a rule or the automatic protection does. It must not describe how the fraud service scores or correlates attempts (see "Protect private behavior" in `AGENTS.md`).

## Quick Command Reference

```bash
# Lint specific file
npx wp-scripts lint-js client/path/to/file.tsx

# Fix specific file
npx wp-scripts lint-js --fix client/path/to/file.tsx

# Type check
npm run lint:types

# Regenerate the POT file (needs WP-CLI; the release build does this)
npm run makepot
```

## Summary

| Pattern                       | Example                                                                  |
|-------------------------------|--------------------------------------------------------------------------|
| **Single placeholder**        | `sprintf( __( 'Block %s', 'woocommerce-fraud-protection' ), value )`      |
| **Repeated placeholder**      | `sprintf( __( '%1$s ... %1$s', 'woocommerce-fraud-protection' ), value )` |
| **Multiple placeholders**     | `sprintf( __( '%1$s rule for %2$s', 'woocommerce-fraud-protection' ), a, b )` |
| **Comment format (simple)**   | `// translators: %s is a date.`                                           |
| **Comment format (numbered)** | `// translators: 1: Rule action, 2: Rule type.`                           |
