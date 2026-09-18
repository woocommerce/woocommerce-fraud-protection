# Code Quality Commands

> **IMPORTANT:** Run `nvm use` before any `npm` or `npx` command, e.g. `nvm use && npm run phpstan`. The repository uses npm, not pnpm.

## Table of Contents

- [Overview](#overview)
- [PHP Linting (phpcs)](#php-linting-phpcs)
- [PHP Static Analysis (PHPStan)](#php-static-analysis-phpstan)
- [JavaScript, TypeScript, and CSS](#javascript-typescript-and-css)
- [Markdown](#markdown)
- [Important Linting Guidelines](#important-linting-guidelines)
- [Understanding Linting Output](#understanding-linting-output)
- [Pre-Handoff Checklist](#pre-handoff-checklist)
- [Troubleshooting](#troubleshooting)

## Overview

CI runs every check over the whole repository: `composer phpcs`, PHPStan, `npm run lint:js`, `npm run lint:css`, and `npm run lint:types`. The full tree must stay clean. Use file-scoped commands while iterating and the full commands before handoff.

For detailed PHP linting patterns and common issues, see [php-linting-patterns.md](php-linting-patterns.md).

## PHP Linting (phpcs)

```bash
# Whole repository (what CI runs)
npm run lint:php

# A single file or directory
vendor/bin/phpcs src/Internal/FraudProtectionPlugin/Rules/RuleStore.php
vendor/bin/phpcs tests/php/src/Internal/FraudProtectionPlugin/Rules/

# Show the sniff code for each message
vendor/bin/phpcs -s path/to/file.php

# Auto-fix
vendor/bin/phpcbf path/to/file.php
npm run lint:php:autofix      # whole repository
```

`phpcs.xml` applies the `WooCommerce-Core` standard plus `PHPCompatibility` for PHP 8.1+, the `woocommerce-fraud-protection` text domain, `manage_woocommerce` as the custom capability, the `init` injection-method sniff under `src/`, and `@throws` requirements outside tests. The `MissingSinceComment` hook sniff is excluded.

## PHP Static Analysis (PHPStan)

```bash
npm run phpstan
```

Level 8 over the main plugin file and `src/`, with third-party classes declared in `stubs/`. There is no baseline: new errors must be fixed or, for genuine false positives, ignored inline with an explanation (see type-annotations.md in the `woocommerce-backend-dev` skill). CI runs PHPStan on PHP 8.4 while `phpstan.neon` pins the analysed language level to 8.1; do not use newer syntax because the analysis happens to run on a newer interpreter.

## JavaScript, TypeScript, and CSS

```bash
# ESLint via wp-scripts over assets/js, client, and tests/js
npm run lint:js

# A single file, with or without auto-fix
npx wp-scripts lint-js client/admin-settings/rules-page.tsx
npx wp-scripts lint-js --fix client/admin-settings/rules-page.tsx

# TypeScript (tsc --noEmit over client/ and tests/js)
npm run lint:types

# Stylelint over assets/css and client/**/*.scss
npm run lint:css

# package.json format
npm run lint:pkg-json
```

Formatting follows the `@wordpress/scripts` ESLint and Prettier configuration (tabs, spaces inside parentheses and braces). Let `--fix` handle formatting instead of adjusting it by hand.

## Markdown

There is no markdown lint step in this repository. See [markdown-linting.md](markdown-linting.md).

## Important Linting Guidelines

### Fix What You Touch

Fix linting errors in the code you add or modify. Do not reformat unrelated files in the same pull request unless specifically asked.

**Why?**

- Keeps pull requests focused on the actual changes
- Avoids merge conflicts with other branches
- Makes code review easier

### Example Workflow

```bash
# 1. Make your code changes
# ... edit files ...

# 2. Check what you've changed
git status
git diff

# 3. Lint the changed PHP files
vendor/bin/phpcs src/path/to/Changed.php tests/php/src/path/to/ChangedTest.php

# 4. Fix issues automatically, then review the fixes
vendor/bin/phpcbf src/path/to/Changed.php
git diff

# 5. Static analysis
npm run phpstan

# 6. Front-end files, if you changed any
npm run lint:js && npm run lint:types && npm run lint:css

# 7. Full PHP lint before handoff (what CI runs)
npm run lint:php
```

## Understanding Linting Output

### PHP CodeSniffer Output

```text
FILE: src/Internal/FraudProtectionPlugin/Rules/RuleStore.php
----------------------------------------------------------------------
FOUND 2 ERRORS AFFECTING 2 LINES
----------------------------------------------------------------------
 12 | ERROR | [x] Expected 1 space after opening parenthesis;
    |       |     0 found
 25 | ERROR | [ ] Variable "$ruleID" is not in valid snake_case
    |       |     format
----------------------------------------------------------------------
```

**Legend:**

- `[x]` - Can be fixed automatically with phpcbf
- `[ ]` - Requires manual fixing

### Common PHP Issues

1. **Spacing issues** - Usually auto-fixable

   ```php
   // Wrong
   if($condition){

   // Right
   if ( $condition ) {
   ```

2. **Naming conventions** - Requires manual fix

   ```php
   // Wrong
   $ruleID

   // Right
   $rule_id
   ```

3. **Yoda conditions** - Requires manual fix

   ```php
   // Wrong
   if ( $value === 'active' )

   // Right
   if ( 'active' === $value )
   ```

## Pre-Handoff Checklist

- [ ] `vendor/bin/phpcs` on the changed PHP files, then `npm run lint:php`
- [ ] `npm run phpstan`
- [ ] `npm run lint:js`, `npm run lint:types`, and `npm run lint:css` if you changed front-end files
- [ ] Review automatic fixes with `git diff`
- [ ] Run the tests that match the change (see [running-tests.md](running-tests.md))
- [ ] Add the `changelog.txt` entry if the change is merchant-facing or developer-facing (see "Issues and pull requests" in `AGENTS.md`)

## Troubleshooting

### Command Not Found

- `vendor/bin/phpcs`, `vendor/bin/phpstan`: run `composer install`
- `wp-scripts`, `wp-env`, `tsc`: run `npm install`

### Issues Reported in Files You Did Not Change

The tree is expected to be clean. If `trunk` itself fails a check, report it instead of fixing unrelated files in your pull request.

### Conflicts After Auto-Fix

1. Review the automatic fixes: `git diff`
2. If a fix is incorrect, revert it: `git checkout -- path/to/file.php`
3. Address the issue manually instead
