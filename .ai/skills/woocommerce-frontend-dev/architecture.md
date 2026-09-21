# Front-End Architecture

## Table of Contents

- [Layout](#layout)
- [Mounting and Routing](#mounting-and-routing)
- [Server Integration](#server-integration)
- [Build Pipeline](#build-pipeline)
- [Development Data](#development-data)
- [Adding a Page](#adding-a-page)

## Layout

```text
client/
├── admin-settings/                  The settings tab: entry point, router, settings and rules pages
│   ├── index.tsx                    Mounts the app and declares the routes
│   ├── navigation.ts                getFraudProtectionRoute(): admin URLs for the app's routes
│   ├── settings-page.tsx            Route "/": automatic protection card, rules card, performance card, Save
│   ├── rules-page.tsx               Route "/rules": the merchant rules DataViews list
│   ├── rule-date.ts                 Rule date formatting, browserTimeZone, UTC filter bounds
│   ├── components/                  Cards, the rule form drawer, the delete dialog, the protection control
│   ├── data/                        @wordpress/data stores: store.ts (settings), rules-store.ts (rules)
│   ├── hooks/                       useFraudProtectionSettings, useRules/useRule, useRuleMutation,
│   │                                useRuleFormDrawer, useUnsavedChangesGuard
│   └── style.scss                   Settings and rules pages, drawer and dialog portals
├── admin-checkout-attempts/         Route "/checkout-attempts": the recorded sessions list
│   ├── checkout-attempts-page.tsx   Page: URL state, tabs, DataViews composition, drawers and dialog
│   ├── use-checkout-attempts.ts     Fetches the list for a view (page-local state, not a store)
│   ├── fields.tsx, actions.tsx      DataViews fields and row actions
│   ├── outcomes.tsx, flagged-chip.tsx, rule-chip.tsx, outcome-info.tsx   Cell components
│   ├── persisted-state.ts           Display preferences in localStorage
│   ├── protection-off-banner.tsx, enable-fraud-prevention-drawer.tsx
│   ├── dates.ts, types.ts, payment-method-elements.ts
│   └── style.scss
├── _settings-list-page.scss         Mixins shared by the rules and checkout attempts pages
└── dataviews-wp.d.ts                Type declaration for the `@wordpress/dataviews/wp` entry point
```

There is one webpack entry, `admin-settings`, and one bundle. A new feature area gets its own directory next to `admin-checkout-attempts/`, imports the shared stores, hooks, and components from `admin-settings/`, and registers its route in `client/admin-settings/index.tsx`.

## Mounting and Routing

**PHP side.** `FraudProtectionSettingsPage` (a `WC_Settings_Page` under `src/Internal/FraudProtectionPlugin/Settings/`) enqueues `build/admin-settings.js` and `build/admin-settings.css` with the dependencies and version from `build/admin-settings.asset.php`, registers the script translations, preloads the settings request, and renders the mount:

```html
<div id="wc-fraud-protection-settings" class="wc-settings-prevent-change-event"></div>
```

It sets `$GLOBALS['hide_save_button']` because the app renders its own Save button, and the `wc-settings-prevent-change-event` class keeps WooCommerce's own unsaved-changes prompt away from the app's inputs. The asset file is validated before use; a missing or invalid build logs an error through the plugin logger and renders a generic notice instead of a broken page.

**Router.** The app uses `react-router-dom` with `unstable_HistoryRouter` over `getHistory()` from `@woocommerce/navigation`, so it shares the WooCommerce admin history object. Routes are `/`, `/rules`, and `/checkout-attempts`; anything else redirects to `/`.

**URLs.** The browser URL is always `admin.php?page=wc-settings&tab=woocommerce_fraud_protection&path=<route>[&...state]`. Never push the bare router pathname (for example `/checkout-attempts?status=blocked`) into the history: a reload of that URL is a 404. Instead:

- Build hrefs for `<Link>`, `LinkButton`, and redirects with `getFraudProtectionRoute( '/rules' )`.
- Build full admin URLs that carry list state with `getNewPath( { page: 'wc-settings', tab: 'woocommerce_fraud_protection', ...params }, '/checkout-attempts', {} )` and write them with `getHistory().push()` or `.replace()` (see dataviews.md).
- Move programmatically only after a successful action, with `useNavigate()`; the settings card navigates to the rules page from the rule drawer's `onSuccess`. Cancelling or a failed save keeps the merchant where they are.

**Settings form.** WooCommerce wraps the mount in a `<form>`. A page with an input (the search box) prevents that form's `submit` so pressing Enter does not reload the page to a broken URL; see the effect in `CheckoutAttemptsPage`. Unsaved settings edits block in-app navigation with `useUnsavedChangesGuard()`, which uses `history.block()` and `window.confirm()`.

## Server Integration

- REST namespace `wc-fraud-protection/v1` with `settings`, `settings/opt-out`, `rules`, `rules/<id>`, `sessions`, and `sessions/payment-methods`. Merchant-facing routes require `manage_woocommerce`. Collections return the items as the body and the totals in the `X-WP-Total` and `X-WP-TotalPages` headers; the stores parse those headers, so a new list endpoint must send them.
- The initial settings GET is preloaded with `rest_preload_api_request()` and `wp.apiFetch.createPreloadingMiddleware()` on the routes that read it (`/` and `/checkout-attempts`). A new route that needs data at first paint extends `maybe_preload_settings_data()` in `FraudProtectionSettingsPage`. Do not run a query on every settings page load for a route that does not need it: the provider filter options, for example, are fetched lazily by DataViews through `getElements`.
- Per-user UI preferences (a dismissed banner) use `useUserPreferences()` from `@woocommerce/data`. The preference key must be allow-listed on the server through the `woocommerce_admin_get_user_data_fields` filter (see `FraudProtectionController::add_user_data_fields()`), or WooCommerce silently drops it.
- The app does not read a plugin-specific configuration object from `window`. One existed for the checkout attempts route and was removed in review because the shared settings store already held the data. The only WordPress-provided globals it uses are the date settings through `@wordpress/date` and the admin history through `@woocommerce/navigation`.

## Build Pipeline

- `npm start` (watch) and `npm run build` run `wp-scripts`. `webpack.config.js` extends the default configuration with a single `admin-settings` entry, a CSS chunk of the same name, and `@woocommerce/dependency-extraction-webpack-plugin` for externals. `@wordpress/private-apis` and `@wordpress/theme` are bundled on purpose: `@wordpress/ui` needs them and supported WordPress versions expose no globals for them.
- `postcss.config.js` re-applies the `@wordpress/postcss-plugins-preset` that `wp-scripts` uses, adds the `@wordpress/theme` design-token fallback plugin so `--wpds-*` variables resolve on WordPress versions that do not define them, and adds cssnano in production. Reassess this custom pipeline when `wp-scripts` is upgraded.
- `babel.config.js` uses `@wordpress/babel-preset-default`. `tsconfig.json` is `strict` with `jsx: react-jsx` and `moduleResolution: node`; because classic resolution cannot read a package `exports` map, `client/dataviews-wp.d.ts` re-exports the `@wordpress/dataviews` types for the `@wordpress/dataviews/wp` runtime entry point.
- The SCSS entry imports the `@wordpress/components` and `@wordpress/dataviews` build stylesheets into the bundle; PHP enqueues the CSS with `wp-components` and `wc-admin-style` as dependencies.
- `build/` is gitignored. Run `npm run build` before loading the settings page in the wp-env store, or keep `npm start` running.
- The repository uses npm. `package-lock.json` is the lockfile CI installs from (`npm ci`); do not add lockfiles for other package managers.

## Development Data

The list pages need data to look at. Two WP-CLI `eval-file` scripts under `bin/` seed it into the wp-env store:

```bash
npm run env -- run cli wp eval-file "wp-content/plugins/$(basename "$PWD")/bin/seed-fake-sessions.php" 60 reset
npm run env -- run cli wp eval-file "wp-content/plugins/$(basename "$PWD")/bin/seed-fake-rules.php" 4 reset
```

Seeded rows must satisfy the same invariants as production writes (for example a session's `matched_rule_id` references the rule that decided it, or is null); a seeder that invents inconsistent data was a review finding.

## Adding a Page

1. Create `client/<feature>/` with the page component and add its `<Route>` in `client/admin-settings/index.tsx`.
2. Link to it with `getFraudProtectionRoute( '/<feature>' )` everywhere, including the breadcrumb back to the settings page.
3. Put its styles in `client/<feature>/style.scss`, import them from the page component, and reuse the `_settings-list-page.scss` mixins when it is a list page.
4. Read shared server state from the stores in `client/admin-settings/data/`; add a store when the data is shared between views, or a page-local hook when it is not.
5. If the page needs settings at first paint, extend the PHP preload to its route.
6. Add its tests under `tests/js/` (see testing.md) and a `changelog.txt` entry (see `AGENTS.md`).
