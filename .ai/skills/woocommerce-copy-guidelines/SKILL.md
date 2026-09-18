---
name: woocommerce-copy-guidelines
description: Guidelines for UI text and copy in WooCommerce Fraud Protection. Use when writing user-facing text, labels, buttons, notices, or customer-facing messages.
---

# WooCommerce Fraud Protection Copy Guidelines

This skill provides guidelines for writing user-facing copy in the plugin: merchant-facing admin UI (settings page, checkout attempts list, rules, inbox notes, WP-CLI output) and customer-facing checkout messages.

## Instructions

Follow these guidelines when writing any user-facing text:

1. **Sentence case**: See [sentence-case.md](sentence-case.md) for rules on using sentence case for all UI text.
2. **Translatable**: every user-facing string uses the WordPress i18n functions with the `woocommerce-fraud-protection` text domain, in PHP and in JavaScript/TypeScript. Log messages stay in English and are not translated. See js-i18n-patterns.md in the `woocommerce-dev-cycle` skill for the JavaScript patterns and php-linting-patterns.md for translators comments in PHP.
3. **Protect private behavior**: customer-facing messages must be generic, such as "We are unable to process this request online." Do not reveal fraud detection, aggregation or correlation logic, risk scoring, or rule thresholds anywhere a customer can see, including error messages, notices, and API responses (see "Protect private behavior" in `AGENTS.md`). Use `BlockedSessionMessage` with the right `MessageContext` instead of writing new customer-facing text.
4. **Merchant-facing vocabulary**: the settings page is "Fraud prevention" under WooCommerce > Settings. Merchant rules are "allow rules" and "block rules", each with a type (email or IP address) and a value; do not call them positive/negative lists or whitelists/blacklists in merchant-facing text or new code identifiers. The feature is "automatic fraud prevention" (or "automatic protection" in shorter labels). The list of attempts is "Checkout attempts"; an attempt is allowed, blocked, or flagged.
5. **Changelog wording**: `changelog.txt` entries describe observable plugin behavior for merchants or developers in one sentence; follow the existing entries and "Issues and pull requests" in `AGENTS.md`.

## Key Principles

- Always use sentence case for UI text, not title case
- Keep copy concise and action-oriented
- Use clear, simple language
- Be consistent with existing plugin copy: search `client/` and `src/` for the current wording before introducing a new term
- Follow WooCommerce Core copy patterns where the plugin has no precedent
