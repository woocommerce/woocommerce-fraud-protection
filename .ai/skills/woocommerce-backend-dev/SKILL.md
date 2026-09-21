---
name: woocommerce-backend-dev
description: Add or modify WooCommerce Fraud Protection backend PHP code following project conventions. Use when creating new classes, methods, hooks, or modifying existing backend code. **MUST be invoked before writing any PHP unit tests.**
---

# WooCommerce Fraud Protection Backend Development

This skill provides guidance for developing the plugin's backend PHP code. The plugin follows WooCommerce Core conventions because it is expected to be merged back into Core; the differences that matter for this repository are called out in each file.

`AGENTS.md` at the repository root is the source of truth for the plugin's architecture, safety rules, logging contract, and pull-request process. This skill does not repeat those rules. Read the relevant `AGENTS.md` section before starting.

## When to Use This Skill

**ALWAYS invoke this skill before:**

- Writing new PHP unit tests (`*Test.php` files)
- Creating new PHP classes
- Modifying existing backend PHP code
- Adding hooks or filters

## Instructions

Follow these conventions when adding or modifying backend PHP code:

1. **Creating new code structures**: See [file-entities.md](file-entities.md) for file locations, namespaces, and the required file header (but for new unit test files see [unit-tests.md](unit-tests.md)).
2. **Naming conventions**: See [code-entities.md](code-entities.md) for naming methods, variables, and parameters, and for docblock requirements.
3. **Coding style**: See [coding-conventions.md](coding-conventions.md) for general coding standards and best practices.
4. **Type annotations**: See [type-annotations.md](type-annotations.md) for PHPStan-aware PHPDoc annotations.
5. **Working with hooks**: See [hooks.md](hooks.md) for hook callback conventions and documentation.
6. **Dependency injection**: See [dependency-injection.md](dependency-injection.md) for the `init()` / `register()` component pattern and container usage.
7. **Data integrity**: See [data-integrity.md](data-integrity.md) for ensuring data integrity when performing CRUD operations.
8. **Writing tests**: See [unit-tests.md](unit-tests.md) for unit testing conventions.

## Key Principles

- Always follow WordPress Coding Standards. `phpcs.xml` applies the `WooCommerce-Core` ruleset to the whole repository.
- Every project PHP file declares `strict_types=1`. The WP-CLI `eval-file` scripts under `bin/` and `tests/bootstrap.php` are the only exceptions.
- Production code must not use PHP features added after PHP 8.1, even though static analysis runs on a newer PHP.
- Use class methods instead of standalone functions.
- Place new internal classes in `src/Internal/FraudProtectionPlugin/` by default. Only the public API goes in `src/FraudProtection/`.
- Apply the safety rules in `AGENTS.md`: fail open, use `FraudDecision`, preserve each attempt's decision, trust plugin evidence only, protect private behavior.
- Write unit tests for new functionality.
- Run linting, static analysis, and tests before handoff (see the `woocommerce-dev-cycle` skill).

## Version Information

The plugin version lives in three places that the `release` skill keeps in sync: the `Version` header in `woocommerce-fraud-protection.php`, the `WC_FRAUD_PROTECTION_VERSION` constant in `src/Internal/FraudProtectionPlugin/PluginInitializer.php`, and `package.json`.

To determine the version for a new `@since` annotation:

- Use the version in the `YYYY-xx-xx` placeholder release block at the top of `changelog.txt`.
- If no placeholder block exists yet, use the next patch version after the version in `package.json`. The first merchant-facing or developer-facing pull request after a release adds the placeholder block (see "Issues and pull requests" in `AGENTS.md`).
