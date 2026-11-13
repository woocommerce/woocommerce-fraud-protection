# Data and storage

Everything the plugin stores, where, for how long, and who reads it. Use it when you add state, debug a store, or answer a privacy question.

## Contents

- [Principles](#principles)
- [Database tables](#database-tables)
- [Options](#options)
- [WooCommerce session keys](#woocommerce-session-keys)
- [Order meta](#order-meta)
- [User meta](#user-meta)
- [Cookies and browser storage](#cookies-and-browser-storage)
- [Transients and cache](#transients-and-cache)
- [Scheduled actions](#scheduled-actions)
- [Request fields](#request-fields)
- [Schema management](#schema-management)

## Principles

- **No state that blocks a later attempt.** Nothing stored by the plugin makes a future attempt more likely to be blocked. The sessions table is write-only for decisions: it's read for the merchant's list, never for verification.
- **Merchant rules are configuration.** The rules table does affect decisions, because the merchant asked it to.
- **Per-attempt records are bounded.** Records that carry a decision between requests live in the WooCommerce session, are used once, and are tied to server-side evidence.
- **The tables hold personal data.** The sessions table and the rules table store emails, IP addresses and names. Keep that in mind for privacy exports, erasure requests, and logs.

## Database tables

Both tables are created only while `MerchantListsFeature` is enabled. That gate is hardcoded to `true`, so turning the feature off is a code change and a release, not a per-site setting. `{prefix}` is [`$wpdb->prefix`](https://developer.wordpress.org/reference/classes/wpdb/).

### `{prefix}wc_fraud_protection_sessions`

One row per recorded verification (the attempts log). Written by `SessionEventRecorder`, read by `SessionEventStore` for the checkout attempts list, pruned after 30 days.

| Column | Content |
|--------|---------|
| `id` | Auto-increment ID |
| `session_id` | Session ID the service returned, when there is one |
| `recorded_at` | When the verification happened (UTC) |
| `source` | Verification source, such as `blocks_checkout` |
| `decision` | The decision the service returned |
| `final_status` | `allowed` or `blocked`: what the plugin enforced |
| `trigger_type` | What decided: `blackbox`, `verify_error`, `request_rejected`, `allow_rule`, `block_rule` |
| `risk_score` | Informational score from the response, when present |
| `email`, `ip`, `ip_country` | Shopper email, IP address and its country |
| `billing_country`, `billing_state`, `billing_city`, `billing_postcode`, `billing_name` | Billing details shown in the list |
| `order_id` | Order ID, when the order existed |
| `payment_method` | Gateway ID |
| `matched_rule_id` | Merchant rule that decided, if any |
| `metadata`, `reported_at` | Reserved: nothing writes them yet |

`decision` and `final_status` differ when a rule, the decision filter, or the automatic-protection setting changed the outcome. A supplied decision (from the skip filter) makes no service call and records no row.

### `{prefix}wc_fraud_protection_rules`

Merchant allow and block rules. Managed by `RuleStore`.

| Column | Content |
|--------|---------|
| `id` | Auto-increment ID |
| `action` | `allow` or `block` |
| `status` | `active`, `disabled` or `deleted` (soft delete) |
| `position` | Evaluation order; allow rules are seeded above block rules |
| `conditions` | JSON condition document: field (`email` or `ip`), operator, value |
| `condition_hash` | Hash of the normalized conditions; unique among live rules, `NULL` when deleted |
| `source_meta` | JSON with details about how the rule was created |
| `action_meta` | Reserved: read, but nothing writes it yet |
| `created_at`, `created_by`, `updated_at`, `updated_by` | Audit fields |
| `source_session_id` | Session the rule was created from, when created from the attempts list |

The active ruleset is cached in the object cache (group `wc_fraud_protection`, key `active_rules`). Every write invalidates it, and a short TTL covers a refill that races a write.

## Options

| Option | Content |
|--------|---------|
| `woocommerce_fraud_protection_automatic_protection` | `yes` or `no`. Missing means the code default, which is enabled. |
| `woocommerce_fraud_protection_automatic_protection_enabled_at` | When automatic protection was last turned on explicitly. Deleted when it's turned off. |
| `woocommerce_fraud_protection_automatic_protection_opted_out_at` | Legacy: read (status, telemetry) and cleared on reset, but no longer written |
| `woocommerce_fraud_protection_merchant_facing_features` | `yes` or `no` override for the settings tab and its REST routes. Missing means enabled. Recording and rules don't depend on it. |
| `woocommerce_fraud_protection_db_version` | Installed schema version |
| `woocommerce_fraud_protection_db_install_state` | Install attempts, last attempt time, last error, while an install is pending or failing |
| `woocommerce_fraud_protection_enabled_note_added` | Inbox note state for "automatic protection enabled" |
| `woocommerce_fraud_protection_early_access_note_retired` | The early-access Inbox note was retired |

## WooCommerce session keys

The WooCommerce session ([`WC()->session`](https://woocommerce.github.io/code-reference/classes/WC-Session-Handler.html)) is server-side storage tied to the shopper's session cookie. It lasts across requests, which makes it the right place for per-attempt records.

| Key | Written by | Content | Lifetime |
|-----|------------|---------|----------|
| `_wc_fraud_protection_session_id` | `SessionVerifier` | Last session ID the service returned | Until the next verification; copied to the classic-checkout order |
| `fraud_protection_collected_data` | `SessionDataCollector` | Tracker events, at most 128 | Until an order moves from `checkout-draft`, `pending` or `failed` to `processing`, `completed` or `on-hold` |
| `fraud_protection_collected_events_truncated` | `SessionDataCollector` | `true` once older events were dropped | Same |
| `_fraud_protection_customer_identity_id` | `SessionIdentityManager` | Identity ID, mirrored from the `wfp_id` cookie | Session |
| `_wc_fraud_protection_reusable_decisions` | `GatewayRequestVerifier` | Kept decisions, keyed by reference-reader pattern: source, session ID, decision, reference, cart hash | One use, or replaced by the next request |
| `_fraud_protection_paypal_verification` | `PayPalDecisionReuse` | The latest PayPal request decision: create-order and vault-order decisions are bound to the PayPal order, setup-token decisions to the session ID and cart | Marked used on reuse, then retired |
| `_fraud_protection_stripe_setup_intent_verification` | `StripeSetupIntentCompat` | Setup-intent decision: session ID, decision, user, payment method | One use |
| `_fraud_protection_woopayments_setup_intent_verification` | `WooPaymentsSetupIntentCompat` | Setup-intent decision: session ID, decision, user, payment method | One use |
| `_wc_fraud_protection_kustom_sent_session_id` | `KustomCompat` | Session ID of the last Kustom order that reached verification on the Checkout block | Until the next Kustom order reaches verification |

[`WC()->session->set()`](https://woocommerce.github.io/code-reference/classes/WC-Session.html#method_set) marks the session as changed, and WooCommerce writes it to the database at shutdown. Write only when the value changes.

## Order meta

| Key | Written by | Content |
|-----|------------|---------|
| `_wc_fraud_protection_session_id` | `SessionVerifier`, `PayPalCompat` | Session ID of the verification that covered the order. `FraudProtectionReporter` reads it to report outcomes. Public constant `SessionVerifier::ORDER_BLACKBOX_SESSION_ID_KEY`. |

Order meta goes through the WooCommerce order API ([`update_meta_data()`](https://woocommerce.github.io/code-reference/classes/WC-Data.html#method_update_meta_data), [`save_meta_data()`](https://woocommerce.github.io/code-reference/classes/WC-Data.html#method_save_meta_data)), so it works with both the posts table and HPOS order tables.

## User meta

| Key | Content |
|-----|---------|
| `woocommerce_admin_fraud_protection_checkout_attempts_banner_dismissed` | Whether the admin dismissed the checkout attempts banner. Saved through WooCommerce admin's user data. |

## Cookies and browser storage

| Name | Set by | Content |
|------|--------|---------|
| `wfp_id` cookie | `blackbox-init.js` or `SessionIdentityManager` | Random 32-character hexadecimal identity. Session cookie, `SameSite=Lax`, readable by the script. |
| `localStorage` entries | Settings app (`client/persisted-state.ts`) | Display preferences in the admin, per browser |

The server and the script generate and accept the same `wfp_id` format. The value isn't rendered into the page, so pages stay cacheable.

## Transients and cache

| Name | Purpose |
|------|---------|
| `wc_fraud_protection_missing_blog_id_log` | Limits the "no Jetpack blog ID" log to once an hour |
| `wcfp_init_bail_notice_{md5}` | Limits each startup bail-out message to once a day |
| `wc_fraud_protection_performance_counts` | Cached attempt counts for the settings page |
| `wc_fraud_protection_tracker_counts` | Cached counts for WooCommerce usage tracking |
| Object cache group `wc_fraud_protection` | Active merchant rules |

## Scheduled actions

| Hook | Scheduler | Schedule | Purpose |
|------|-----------|----------|---------|
| `wc_fraud_protection_prune_sessions` | Action Scheduler, group `woocommerce-fraud-protection` | Daily | Delete sessions rows older than 30 days |
| `woocommerce_fraud_protection_managed_translation_update` | WP-Cron | Periodic | Update translations on managed installations |

## Request fields

Fields the plugin reads from requests. Public ones are in the [API reference](../api-reference.md#where-the-server-reads-the-session-id).

| Field | Where | Public |
|-------|-------|--------|
| `wc_fraud_protection_session_id` | Form fields, top-level JSON properties | Yes |
| `extensions['woocommerce/fraud-protection']['blackbox_session_id']` | Store API checkout | Yes |
| `wc_fraud_protection_session_id_error` | Form fields, top-level JSON properties | No |
| `extensions['woocommerce/fraud-protection']['blackbox_session_id_error']` | Store API checkout | No |

## Schema management

`SchemaManager` installs and upgrades the tables. The plugin can run as a must-use plugin, which has no activation hook, so:

- the install is checked on [`init`](https://developer.wordpress.org/reference/hooks/init/) and runs when the stored version is older than the code's (not under WP-CLI, where `wp wc fraud-protection database install` does it);
- it runs only while `MerchantListsFeature` is enabled;
- [`dbDelta()`](https://developer.wordpress.org/reference/functions/dbdelta/) doesn't report failures reliably, so after running it the manager checks that every expected column and index exists (by name; types aren't compared), and only then stamps the version;
- errors are captured from `$EZSQL_ERROR`, because later queries clear [`$wpdb->last_error`](https://developer.wordpress.org/reference/classes/wpdb/);
- a failed install is retried at most once an hour and abandoned after 24 failures; the state is kept in `woocommerce_fraud_protection_db_install_state`;
- the version check uses "installed version is at least the code's version", so rolling back to an older plugin doesn't re-run migrations.

Rules for migrations:

- **Additive only.** A newer schema must keep working with an older plugin version.
- **Index text columns at 191 characters or fewer** (`KEY email (email(191))`). Older MySQL versions limit utf8mb4 index keys to 767 bytes.
- **Truncate with `mb_substr()`.** Splitting a multibyte character makes the insert fail in strict SQL mode.

`wp wc fraud-protection database install` runs a pending install or upgrade by hand, including one that was abandoned after too many failures. It can't repair a schema whose stored version is already current: if a table is missing while the version option says the schema is installed, the command fails with "installation did not complete", because the installer only compares versions. Components that read the tables check `SchemaManager::is_schema_installed()` first and skip their work while it isn't.
