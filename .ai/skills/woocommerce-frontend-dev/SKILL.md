---
name: woocommerce-frontend-dev
description: Build or modify the WooCommerce Fraud Protection admin UI in client/ (React, TypeScript, @wordpress/ui, DataViews, @wordpress/data) and its Jest tests. Use when adding or changing settings pages, lists, drawers, dialogs, stores, hooks, styles, or front-end tests. **MUST be invoked before writing any code under client/ or tests/js/.**
---

# WooCommerce Fraud Protection Front-End Development

The merchant-facing admin UI lives in `client/` and renders as one React application inside the WooCommerce settings page. This skill captures the architecture and the conventions that the reviews of the checkout attempts list (#142), the merchant rules pages (#145 to #148 and #153), and the polish pass (#155) converged on. Following them is what makes new UI pass review the first time.

The browser scripts under `assets/js/` (checkout, pay-for-order, add-payment-method, PayPal, Blackbox init) are plain, unbuilt JavaScript. They are outside this skill except for their tests; the public API rules in `AGENTS.md` and `README.md` govern them.

## When to Use This Skill

**ALWAYS invoke this skill before:**

- Adding or changing anything under `client/` or `tests/js/`
- Adding or changing a REST endpoint that the admin UI consumes (the stores expect the contract described in data-and-state.md)
- Reviewing a pull request that touches the admin UI (use review-checklist.md)

## Instructions

1. **Architecture**: See [architecture.md](architecture.md) for where things live, routing and navigation, how PHP mounts the app, the build pipeline, and the checklist for adding a page.
2. **Data and state**: See [data-and-state.md](data-and-state.md) for the `@wordpress/data` stores, resolvers and invalidation, `apiFetch` patterns, error and stale-request handling, preloading, dates, and per-user preferences.
3. **Lists and forms**: See [dataviews.md](dataviews.md) for DataViews fields, actions, view state in the URL, tabs, empty states, and DataForm validation.
4. **Components and accessibility**: See [ui-components.md](ui-components.md) for `@wordpress/ui` usage, drawers, dialogs, popovers versus tooltips, icons, z-index, and the keyboard and screen-reader requirements.
5. **Styling**: See [styling.md](styling.md) for class naming, design tokens, RTL, scoped overrides, and the shared mixins.
6. **Testing**: See [testing.md](testing.md) for the Jest and Testing Library patterns, the mocks the app needs, and what to cover.
7. **Self-review**: See [review-checklist.md](review-checklist.md) for the checks reviewers applied to the existing pages. Go through it before opening a pull request.

## Non-Negotiables

- Every user-facing string goes through `@wordpress/i18n` with the `woocommerce-fraud-protection` text domain. Copy follows the `woocommerce-copy-guidelines` skill and never reveals how fraud detection works.
- Use `@wordpress/ui` components, `@wordpress/icons` glyphs, and `--wpds-*` design tokens. Do not hand-roll a control, an SVG, or a colour that the design system provides.
- Everything interactive is reachable by keyboard and announced by screen readers: real `<button>` triggers with accessible names, tabs that contain their panels, popovers rather than tooltips for content that holds a link.
- Navigation state (search, filters, tab, sort, page) lives in the URL through the WooCommerce history. Only display preferences go to `localStorage` or user preferences.
- Server state lives in the shared `@wordpress/data` stores. Do not read a value from a PHP-injected global or a one-time page load when a store can provide it and keep every view in sync.
- Unknown state is the safe state: while settings are loading, or when the load failed, do not show controls that assume automatic protection is off.
- Requests fail, arrive late, and arrive out of order. Handle the error path, ignore stale responses, and never "correct" UI state from a failed request.
- Before handoff run `npm run lint:js`, `npm run lint:types`, `npm run lint:css`, and `npm run test:js` (see the `woocommerce-dev-cycle` skill), then check the UI in the wp-env store after `npm run build`.
