# Front-End Review Checklist

These are the checks reviewers applied to the checkout attempts list (#142), the rules pages (#145 to #148, #153), and the polish pass (#155). Go through them before opening a pull request that touches `client/` or `tests/js/`; the `woocommerce-code-review` skill points reviewers here.

## Accessibility

- [ ] Explanations, and anything containing a link, use `Popover` (with `openOnHover` for info icons), not `Tooltip`. Tooltip content is hidden from touch and screen-reader users.
- [ ] Every tooltip trigger is a focusable `<button>` with an `aria-label` that conveys the same information as the tooltip.
- [ ] Each `Tabs.Tab` controls a `Tabs.Panel` that contains its content; a shared list is rendered inside the active panel.
- [ ] Icon-only buttons have accessible names; decorative icons are `aria-hidden`.
- [ ] Loading is announced (`aria-busy`, `role="status"` or `VisuallyHidden` text).
- [ ] State is conveyed by shape and label, not colour alone.
- [ ] Labels are bound to inputs; invalid inputs carry `aria-invalid` and `aria-describedby`.

## State and Data

- [ ] Shared server state is read from the `@wordpress/data` store, never from a PHP-injected global or a one-time value captured at page load.
- [ ] Data a route needs at first paint is preloaded for that route only; a route that does not need it does not trigger the query on every settings page load.
- [ ] Controls that depend on settings wait for resolution, and unknown or failed settings are treated as "protection on".
- [ ] Writes go through the shared store action (with overrides when another surface writes the same setting) so every view updates from the response.
- [ ] Responses are validated with type guards and header parsing; invalid responses surface a generic error.
- [ ] Failed requests do not trigger corrections (pagination) or extra requests.
- [ ] Late or out-of-order responses are ignored; a late detail response cannot repopulate a different form.
- [ ] Filters compare the same normalized value that the row display uses, on both the PHP and the JavaScript side.
- [ ] Options for filters load lazily (`getElements`) or from the store, not from an extra global.

## Navigation

- [ ] Navigation state (search, filters, tab, sort, page) is mirrored to the URL as a full admin URL built with `getNewPath()` and written through `getHistory()`; the browser path stays `/wp-admin/admin.php` with the route in `path`.
- [ ] `push()` for user navigation, `replace()` for automatic corrections and search typing.
- [ ] Display preferences (columns, density, page size) go to `localStorage` with a versioned, validated envelope; nothing else does.
- [ ] Per-user preferences use `useUserPreferences()` and the key is allow-listed in PHP.
- [ ] Programmatic navigation happens only after a successful action; cancelling or a failed save keeps the merchant on the current page.
- [ ] Enter inside the list does not submit the WooCommerce settings form.

## Lists

- [ ] `DataViews`, `DataForm`, and `useFormValidity` are imported from `@wordpress/dataviews/wp`.
- [ ] Sorting is by what the merchant sees, done server-side across pages, or disabled for that column.
- [ ] The empty message matches the cause: load error, no matches, or no data.
- [ ] Actions are always passed, including while a new query loads; rows stay visible during loading.
- [ ] No action does nothing. A placeholder needs explicit agreement in the pull request.
- [ ] Row-action callbacks guard against missing values and refresh the list on success without changing the view.

## Components and Styles

- [ ] `@wordpress/ui` components and `@wordpress/icons` glyphs; no local SVGs, no components from `@wordpress/components`.
- [ ] Component props and the component's default layout are used before custom CSS; required package styles and stylesheet dependencies are loaded.
- [ ] Drawers and dialogs cancel Escape and backdrop dismissal while saving (`eventDetails.cancel()`), disable the close icon, show errors inline, reset on open, and route every close path through one function.
- [ ] Portal z-index variables are set for every drawer, dialog, and popover.
- [ ] DataViews overrides are minimal, scoped under the page root, use logical properties, reuse the shared mixins, and say why in a comment.
- [ ] `--wpds-*` tokens for colour and spacing; component variables (`--wp-ui-button-*`) for component colours.
- [ ] New stylesheets live under `client/**/*.scss` so `npm run lint:css` covers them, and pass it.
- [ ] External links use `target="_blank" rel="noopener noreferrer"`; in-app links use `getFraudProtectionRoute()`.
- [ ] Dates render in the browser time zone with the site's formats.

## Copy and Internationalization

- [ ] All strings are translated with `__()` or `_n()` and the `woocommerce-fraud-protection` domain. `sprintf()` only formats an already translated string, as in `sprintf( __( '...', 'woocommerce-fraud-protection' ), value )`, and every placeholder has a translators comment.
- [ ] Sentence case; terminology matches the rest of the plugin, including the CLI and the tests ("automatic fraud prevention", "allow rule", "block rule", "checkout attempts").
- [ ] Every changed string is compared with the approved design, including labels, accessibility names, loading, empty and error states, zero/singular/plural forms, and punctuation; intentional differences are recorded.
- [ ] Nothing reveals how fraud detection scores or correlates attempts.

## Tests

- [ ] Row-action eligibility, query building, URL state, empty states, error paths, the safe default, drawer behaviour, and stale-response handling are covered (see testing.md).
- [ ] Integrated DataViews and form flows use the real components with accessible queries and `userEvent`; browser shims are added only after a test demonstrates that one is required.
- [ ] Fixtures for storage are built from the exported helpers, not from private keys.
- [ ] Request counts are asserted per endpoint.
- [ ] Assertions use the merchant-facing strings and were updated with the copy.

## Pull Request

- [ ] `npm run lint:js`, `npm run lint:types`, `npm run lint:css`, and `npm run test:js` pass; `npm run build` produced a working page in wp-env.
- [ ] `changelog.txt` has an entry under the placeholder release for merchant-facing changes.
- [ ] The description matches the final behaviour (update it after review changes) and its manual steps can be run on a generic test site.
- [ ] Screenshots from the production build match the approved design for components, copy, spacing, colour, alignment, responsive behavior, and all important states.
- [ ] Manual testing covers complete flows, useful filter combinations, loading, empty, error, cancellation, retry, duplicate, Back/Forward, reload, and deep-link behavior; browser and PHP logs were checked for hidden errors.
- [ ] The final branch was checked against current `trunk` for duplicated behavior and tested with the current supported WordPress and WooCommerce versions.
- [ ] Seed scripts under `bin/` produce data that satisfies the same invariants as production writes.
- [ ] No stray files: build output, lockfiles for other package managers, local configuration.
