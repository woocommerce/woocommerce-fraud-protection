# Admin and operations

This reference covers the parts of the plugin that merchants and operators see: the settings app, its REST routes, Inbox notes, WP-CLI commands, logging, telemetry, and how the plugin is deployed and switched off.

## Contents

- [Feature gates](#feature-gates)
- [Settings app](#settings-app)
- [REST routes](#rest-routes)
- [Automatic protection](#automatic-protection)
- [Merchant rules](#merchant-rules)
- [Inbox notes](#inbox-notes)
- [WP-CLI commands](#wp-cli-commands)
- [Logging](#logging)
- [Telemetry](#telemetry)
- [Deployment and kill switches](#deployment-and-kill-switches)

## Feature gates

| Gate | Where | Controls |
|------|-------|----------|
| `FraudProtectionController::feature_is_enabled()` | Code, always `true` | Everything. Checked once, at the start of `register()`. |
| `MerchantListsFeature::is_enabled()` | Code, always `true` | Database tables, session recording, rule evaluation |
| `MerchantFacingFeaturesGate` | Option `woocommerce_fraud_protection_merchant_facing_features` (default on) | Settings tab, settings assets, per-user preferences, REST controllers. Recording and rules keep working when it's off. |

The code gates are switched by releasing a new version. The option gate can be set per site with WP-CLI.

## Settings app

`FraudProtectionSettingsPage` adds a **Fraud prevention** tab (ID `woocommerce_fraud_protection`) under WooCommerce > Settings. The tab renders a React app from `client/`, compiled into `build/`:

| Path | Content |
|------|---------|
| `client/admin-settings/` | App entry point, settings page, rules page, navigation |
| `client/admin-settings/components/`, `data/`, `hooks/` | The app's components, `@wordpress/data` stores, and React hooks |
| `client/admin-checkout-attempts/` | Checkout attempts list (DataViews), rule chips, the "enable protection" drawer |
| `client/ui-compat/` | Stand-ins for `@wordpress/ui` components missing from the version WooCommerce bundles, with the upstream names, so switching back is an import change |
| `client/list-state.ts`, `persisted-state.ts`, `rest-api.ts` | List state, preferences kept in the browser's `localStorage`, and REST helpers |

Things to know when working on it:

- Run `npm start` or `npm run build` after changing `client/`.
- The app is only loaded on the WooCommerce > Settings screen (`page=wc-settings&tab=woocommerce_fraud_protection`). It moves between its own views with WooCommerce admin's history object and a `path` query parameter; build URLs with the plugin's route helpers.
- Settings live in one data store. Every write goes through its actions, so all routes see the same state.
- Load the `woocommerce-frontend-dev` skill before changing `client/` or `tests/js/`.

`FraudProtectionController::add_user_data_fields()` allows the plugin's per-user preferences, such as a dismissed banner, through WooCommerce admin's user data.

## REST routes

All routes are under the `wc-admin` namespace and require the `manage_woocommerce` capability.

| Route | Methods | Controller | Purpose |
|-------|---------|------------|---------|
| `/wc-admin/fraud-protection/settings` | `GET`, `POST`/`PUT`/`PATCH` | `SettingsRestController` | Read and change settings, including automatic protection |
| `/wc-admin/fraud-protection/sessions` | `GET` | `SessionsRestController` | Page, sort, filter and search the checkout attempts list |
| `/wc-admin/fraud-protection/sessions/payment-methods` | `GET` | `SessionsRestController` | Payment methods for the list's filter |
| `/wc-admin/fraud-protection/rules` | `GET`, `POST` | `RulesRestController` | List and create rules |
| `/wc-admin/fraud-protection/rules/{id}` | `GET`, `POST`/`PUT`/`PATCH`, `DELETE` | `RulesRestController` | Read, update and delete a rule |

These routes are internal to the settings app. They aren't a public API.

## Automatic protection

Automatic protection decides whether the service's block decisions are enforced. Merchant block rules are enforced either way.

- The setting is stored in `woocommerce_fraud_protection_automatic_protection` (`yes` or `no`). Without the option, the code default applies: enabled.
- `AutomaticProtectionSetting::get_status()` returns `enabled`, `disabled`, `default_enabled` or `default_disabled`; `get_source()` returns `manual`, `auto_enroll` or `none`. Both are sent with each verification.
- `AutomaticProtectionSettingUpdater` changes it and records the change in telemetry. Turning it on explicitly sets `woocommerce_fraud_protection_automatic_protection_enabled_at`; turning it off deletes that option. The Inbox note follows the option through WordPress's option hooks.

With automatic protection off, an automated block is still recorded with `decision = block` and `final_status = allowed`, so the merchant can see what it would have stopped.

## Merchant rules

A rule has an action (`allow` or `block`), a status, a position, and one condition: the shopper's email or IP address equals a value.

- **Evaluation.** `RuleEvaluator` walks the active rules in position order and returns the first match. A malformed rule, an unknown operator or a missing field doesn't match. The whole evaluation fails open.
- **Normalization.** Emails and IP addresses are normalized when a rule is saved and when an attempt is evaluated, so textual variants compare equal (for example, the long and compressed forms of an IPv6 address).
- **One source of facts.** The IP address and email come from the same collected data that's recorded and sent to the service.
- **Ordering.** New allow rules go above block rules, so an allow rule can fix a false positive.
- **Uniqueness.** Two live rules can't have the same condition (`condition_hash` is unique). Deleting a rule clears its hash and keeps the row.

See [From verdict to decision](../tutorial/03-fraud-protection.md#from-verdict-to-decision) for where rules sit in the decision.

## Inbox notes

| Note | Class | Shown when |
|------|-------|------------|
| `wc-fraud-protection-automatic-protection-enabled` | `AutomaticProtectionEnabledNote` | Automatic protection was turned on by default. Leaves the Inbox when it's turned off. |
| `wc-fraud-protection-automatic-protection-early-access` | `AutomaticProtectionEarlyAccessNote` | Retired: the class removes an old invitation. |

Notes register outside the merchant-facing gate, so they're cleaned up even when the gate is off. Admin note reads can fail; keep retry state until a write is confirmed.

## WP-CLI commands

Registered from `PluginInitializer` when WP-CLI runs (added on WP-CLI's `after_wp_load`), outside the controller, so they're available whatever the gates say. They aren't available when the plugin bails out at startup.

| Command | Purpose |
|---------|---------|
| `wp wc fraud-protection status` | Local status: version, installation type, gates and settings, Jetpack blog ID, schema versions and install state, tables, database charset, next pruning action |
| `wp wc fraud-protection native-integrations list` | Native integrations declared by gateways and extensions (see the [API reference](../api-reference.md#wp-cli)) |
| `wp wc fraud-protection automatic-protection set` | Turn automatic blocking on or off for the site |
| `wp wc fraud-protection merchant-facing-features set` | Turn the merchant-facing features on or off for the site |
| `wp wc fraud-protection database install` | Install or repair the schema |
| `wp wc fraud-protection sessions prune` | Prune expired attempts log rows now |

Run `wp help wc fraud-protection <command>` for arguments. When you add a feature, consider whether operators need a command to inspect or repair it.

## Logging

Use `FraudProtectionController::log( $level, $message, $context, $forward )`.

- Entries go to the WooCommerce logger with source `woo-fraud-protection`: WooCommerce > Status > Logs. Don't put a `source` key in the context, because the logger uses it to pick the file; use `event_source`.
- When a WooCommerce session exists, the message is prefixed with `Identity: {identity ID} |`, to follow one browser across requests.
- Context values go through `EncodablePayload::for_log()`, which makes them safe to encode.
- Messages are in English and aren't translated.

### Forwarding to the platform log

With `$forward = true`, a copy goes to the PHP error log, for failures that need central attention: transport and parsing failures, plugin exception paths, third-party filter failures.

- The context is reduced by `LogContextSanitizer`: only allowlisted top-level keys with scalar values, strings cut to 200 characters. Adding a key needs a privacy review.
- The message is forwarded **as is**. Never put user data, payment data, form fields or third-party exception text in it.
- The line format, `PHP Warning: [woo-fraud-protection {level}] {message} {context} in {plugin file} on line {code}`, is a contract with the host's log parser. `FraudProtectionControllerTest` covers it.

The [AGENTS.md logging section](../../AGENTS.md#hooks-logging-and-errors) has the full rules.

## Telemetry

`SettingsTelemetry`:

- adds the plugin's settings state, 30-day attempt outcome counts and rule counts to WooCommerce's usage tracking (`woocommerce_tracker_data`);
- records its own Tracks events, `fraud_protection_automatic_protection_changed` and `fraud_protection_rule_changed`;
- adds a view-source property to the settings page's Tracks events.

All of it follows WooCommerce's usage-tracking opt-in.

## Deployment and kill switches

The plugin is distributed as a regular plugin ZIP, and on managed WooCommerce hosting it's also deployed as a must-use plugin through `woocommerce-fraud-protection-loader.php`. [Architecture](architecture.md#kill-switches-and-copies) describes how the two copies coexist.

To switch the plugin off on one site without uninstalling it:

```php
define( 'WC_FRAUD_PROTECTION_DISABLED', true );
```

When WooCommerce is older than 9.8 or `vendor/autoload.php` is missing, the plugin logs the reason once a day and registers no components or WP-CLI commands. It has still defined its constants and turned off WooCommerce Core's own fraud protection feature by then.

Without a Jetpack connection, the plugin loads but doesn't load its browser scripts or reach the service. Automated decisions fail open; merchant rules still apply, and attempts are still recorded with the `verify_error` trigger.

The [README](../../README.md#releases) describes the release process.
