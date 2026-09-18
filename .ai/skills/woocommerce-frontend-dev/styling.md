# Styling

## Table of Contents

- [Files and Imports](#files-and-imports)
- [Class Names](#class-names)
- [Design Tokens](#design-tokens)
- [Writing Direction](#writing-direction)
- [Shared Mixins](#shared-mixins)
- [Overriding DataViews and WooCommerce Chrome](#overriding-dataviews-and-woocommerce-chrome)
- [Portaled Content](#portaled-content)
- [Responsive Layout](#responsive-layout)
- [Linting](#linting)

## Files and Imports

- One `style.scss` per feature directory, imported from the page component (`import './style.scss'`). Everything is bundled into `build/admin-settings.css`.
- `@use` for `@wordpress/base-styles` modules (`z-index`, `colors`) and for the shared mixins (`@use '../settings-list-page' as settings-list-page;`). `@import` only for the plain CSS of `@wordpress/components/build-style/style.css` and `@wordpress/dataviews/build-style/style.css`, which the settings entry pulls into the bundle.
- Keep a short comment above any rule whose reason is not obvious: which WooCommerce or DataViews behaviour it corrects and why. The existing files are the model.

## Class Names

- BEM-like, prefixed with the feature: `wc-fraud-protection-<feature>__<element>` (`wc-fraud-protection-rules__toolbar`, `wc-fraud-protection-checkout-attempts__rule-chip`). Modifiers use `--<variant>` on the block (`__action--allow`) or `is-<state>` classes (`is-allow`, `is-compact`, `is-icon-only`).
- Scope page rules under the page root class (`.wc-fraud-protection-checkout-attempts__page { ... }`) so they cannot leak into other settings tabs.
- Classes exist for styling. Tests query by role and accessible name, not by class.

## Design Tokens

- Colour, spacing, typography, radius, and border widths come from the `--wpds-*` custom properties: `var(--wpds-color-foreground-content-neutral-weak)`, `var(--wpds-dimension-gap-lg)`, `var(--wpds-dimension-padding-2xl)`, `var(--wpds-typography-line-height-md)`, `var(--wpds-border-width-xs)`. The PostCSS pipeline adds fallbacks for WordPress versions that do not define them.
- `$gray-700` and similar from `@wordpress/base-styles/colors` remain in the checkout attempts styles where a token did not exist; prefer a token when one fits.
- Component colours are overridden through the component's own variables, not by targeting its internals: the destructive delete button sets `--wp-ui-button-background-color`, `--wp-ui-button-foreground-color`, and their `-active` and `-disabled` variants to the error tokens.
- No hard-coded hex values in new code unless the design gives one and no token matches; comment it.

## Writing Direction

Use logical properties so layouts follow RTL: `margin-inline`, `margin-inline-start: auto`, `padding-inline`, `padding-block`, `margin-block-end`, `border-block-end`, `inset-inline-start`. Left and right paddings in a DataViews override were a review finding.

## Shared Mixins

`client/_settings-list-page.scss` holds what the rules and checkout attempts pages share, so a new list page starts from them:

| Mixin | Purpose |
| ----- | ------- |
| `page` | Pulls the page out of WooCommerce's 30px settings gutter and gives it the white surface and padding |
| `header`, `breadcrumb`, `description` | The breadcrumb-plus-description header; the parent breadcrumb segment renders as an underlined link |
| `toolbar` | The row holding the status tabs and the list controls, with the rule under it |
| `dataviews` | Removes DataViews' own inline padding so rows align with the page gutter, and spaces the filters bar and the empty state below the toolbar |

Add a mixin there when a second page needs the same rule; do not copy blocks between the two stylesheets.

## Overriding DataViews and WooCommerce Chrome

- Keep DataViews' defaults unless the design needs a specific adjustment. Its class names are internal, so every override is a maintenance risk; a set of spacing overrides that duplicated what DataViews already supplied was removed in review.
- When an override is needed: scope it under the page root, use logical properties, use the `dataviews` mixin for the shared part, and explain the cause in a comment. The header-alignment block in the checkout attempts styles is the model: it states which DataViews compensation it repeats and why each selector exists, including the compact-density variants.
- WooCommerce settings chrome that sits outside the app's mount (the tab bar's bottom margin, the breadcrumb `nav` margin) is adjusted with route-scoped selectors such as `#mainform:has( .wc-fraud-protection-rules ) nav.nav-tab-wrapper { margin-bottom: 0; }`, so the tweak applies only while that route is active.

## Portaled Content

Drawers, dialogs, popovers, menus, and snackbars render outside the page root. Their rules must not be nested under the page class, and their z-index variables are set on the portal (see "Portals and z-index" in ui-components.md). A row action's menu item styling is the one intentionally unscoped rule in the checkout attempts stylesheet, with a comment saying so.

## Responsive Layout

Cards use container queries on their own content (`container-type: inline-size` and `@container (max-width: ...)`) rather than viewport media queries, so they adapt to the settings column width. Cell content that must not wrap uses `white-space: nowrap` with `flex-shrink: 0`, and truncating text gets `min-width: 0; overflow: hidden; text-overflow: ellipsis`.

## Linting

- `npm run lint:css` runs stylelint over `assets/css` and `client/**/*.scss`, so any new stylesheet under `client/` is covered automatically. A stylesheet outside that glob is a review finding.
- `.stylelintrc.json` extends `@wordpress/stylelint-config/scss` and turns off `selector-class-pattern`. Let `npx wp-scripts lint-style --fix client/path/style.scss` handle formatting (for example the blank line before an `@include` inside a block).
