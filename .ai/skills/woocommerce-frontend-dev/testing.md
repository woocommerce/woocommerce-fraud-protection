# Front-End Testing

## Table of Contents

- [Runner and Files](#runner-and-files)
- [Test Pure Helpers Directly](#test-pure-helpers-directly)
- [Rendering Pages and Components](#rendering-pages-and-components)
- [Mocks the App Needs](#mocks-the-app-needs)
- [Queries and Interactions](#queries-and-interactions)
- [Requests, Ordering, and Loading](#requests-ordering-and-loading)
- [Fixtures](#fixtures)
- [What to Cover](#what-to-cover)
- [Browser Scripts](#browser-scripts)

## Runner and Files

- `npm run test:js` runs Jest through `wp-scripts test-unit-js` with the `@wordpress/jest-preset-default` preset; the configuration is the `jest` block in `package.json`. See running-tests.md in the `woocommerce-dev-cycle` skill for filters and watch mode.
- Tests live in `tests/js/` as `<area>.test.tsx` (React) or `.test.ts` (stores and helpers). Shared mocks live in `tests/js/mocks/`.
- `tests/js/setup.js` adds the JSDOM shims the UI needs (`Request`, `PointerEvent`, `ResizeObserver`, the `:modal` selector, `HTMLFormElement.submit`) and silences the CSS-parsing errors `@wordpress/ui` triggers in JSDOM. `tests/js/jest-global-setup.js` pins the process time zone to `America/New_York`, so date assertions must expect that zone.
- Test files follow the same ESLint, Prettier, and `tsc --noEmit` checks as the app (`npm run lint:js`, `npm run lint:types`).

## Test Pure Helpers Directly

Export the pieces that do not need a render and test them as functions: `buildListPath`, `normalizeRulesQuery`, `getQueryFromView`, `getUtcDateFilterBound`, `formatDate`, `formatDateTime`, `loadPrefs`, `savePrefs`, `buildActions`, `getFields`, `getOutcomeLabel`, `getRuleFormFields`, `isCompleteIp`. Eligibility of every row action for every outcome and rule state is a table of such assertions, not a rendered menu.

A field's `render` can be tested in isolation by rendering it as a component: `const Render = field.render as ComponentType< { item } >; render( <Render item={ aSession() } /> )`.

Hooks with their own state machine are tested with `renderHook` and `act` (`use-rule-form-drawer.test.tsx`).

## Rendering Pages and Components

- Render inside a `RegistryProvider` with a fresh `createRegistry()` and register every store the page reads into it (`settingsStore`, `rulesStore`, and a `core/notices` store), so tests do not share cache state. The module-level `register()` calls in the store files only affect the default registry, which a page rendered under a `RegistryProvider` does not use.
- Wrap in `MemoryRouter` when the page only needs `<Link>`, or in `unstable_HistoryRouter history={ mockHistory }` with a `createMemoryHistory()` when the test asserts URL state, so `getHistory()` and the router share one history (see the next section).
- Render the whole app (`FraudProtectionAdminApp`) for routing tests; render a page (`RulesPage`, `CheckoutAttemptsPage`) for behaviour tests; render a component (`OutcomeBadge`) for cell tests.

## Mocks the App Needs

Declare these at the top of the file with `jest.mock`; they are the boundaries the app talks through.

Use the real DataViews, DataForm, and `@wordpress/ui` components for integrated merchant flows. Mock a component only when the test covers consumer wiring and the component contract is outside its scope. Do not keep a mock only to avoid following the real menu, validation, focus, or event behavior. Add a JSDOM shim only after a failing test shows which browser API is missing.

| Module | Mock | Notes |
| ------ | ---- | ----- |
| `@wordpress/api-fetch` | `{ __esModule: true, default: jest.fn() }` | `mockResolvedValue( object )` for JSON requests; a `Response`-like `{ json: async () => items, headers: { get: ( name ) => ... } }` for `parse: false` collections, returning `X-WP-Total` and `X-WP-TotalPages`. Implement by `options.path` (and `method`) when a page issues several requests. `mockReset()` in `beforeEach`. |
| `@woocommerce/navigation` | `getHistory: () => mockHistory`, `getNewPath: ( query, path ) => '/wp-admin/admin.php?...&path=' + path` | Mirrors WooCommerce's URL builder so assertions can check the browser path stays `/wp-admin/admin.php`. Create `mockHistory` per test with `createMemoryHistory( { initialEntries: [ route ] } )`. |
| `@wordpress/notices` | `{ __esModule: true, store: { name: 'core/notices' } }` plus a registered `core/notices` store whose `createSuccessNotice` calls a spy | Lets tests assert the snackbar text and options. |
| `@woocommerce/data` | `{ useUserPreferences: () => ( { isRequesting: false, updateUserPreferences: jest.fn() } ) }` | The real package drags its dependencies into the test; the routing test passes `{ virtual: true }` so the mock stands in without resolving it. Back the hook with a `jest.fn()` when a test changes the preference. |
| `@wordpress/dataviews/wp` | `{ ...jest.requireActual( '@wordpress/dataviews/wp' ), DataViews: <replacement> }` | Keep `DataForm` and `useFormValidity` real so drawers work. Replace `DataViews` with `tests/js/mocks/rules-dataviews.tsx` (a plain table that renders rows and action buttons, plus no-op `Search`, `FiltersToggle`, `FiltersToggled`, `ViewConfig`, `Layout`, `Footer`) when the list internals are not under test, or with a no-op that still exposes those parts for routing tests. Tests of list behaviour itself use the real DataViews. |

`window.PointerEvent` and `window.ResizeObserver` shims are already in `setup.js`; a file may repeat them when it runs in isolation.

## Queries and Interactions

- Query by role and accessible name: `screen.getByRole( 'button', { name: 'Why was this blocked?' } )`, `screen.findByRole( 'dialog', { name: 'Create rule' } )`, `getByRole( 'tab', { name: 'Block' } )`, `getByRole( 'navigation', { name: 'Breadcrumb' } )`, `getByLabelText( 'Value' )`. Scope to a row with `within( row )` where `row = ( await screen.findByText( value ) ).closest( 'tr' )`.
- Interact with `userEvent`: `click`, `type`, `tab()`, `keyboard( '{Enter}' )`. Assert keyboard reachability of popover and tooltip triggers (`expect( trigger ).toHaveFocus()` after `userEvent.tab()`).
- Wait with `findBy*` or `waitFor`; wrap manual promise resolution in `act( async () => resolve( value ) )`.
- Assert what the merchant sees (`toBeInTheDocument`, `toBeVisible`, `toHaveValue`, `toHaveFocus`), not implementation details.

## Requests, Ordering, and Loading

- Inspect requests through the mock: `mockedApiFetch.mock.calls.map( ( [ options ] ) => options.path )`, `toHaveBeenLastCalledWith( expect.objectContaining( { path: expect.stringContaining( 'action=block' ), method: 'POST' } ) )`. Count requests per endpoint (`settingsFetchCount()`) rather than in total, because a route can issue several.
- Deferred promises (`let resolve; new Promise( ( r ) => { resolve = r; } )`) test ordering: resolve the older request after the newer one started and assert the newer state wins (`rules-page-transitions.test.tsx`).
- A never-settling promise (`new Promise( () => undefined )`) holds a loading state so the test can assert what stays on screen (rows and their Actions column).
- URL assertions read `mockHistory.location.pathname` and `.search`; a list test verifies that changing a tab or filter keeps the browser path on `/wp-admin/admin.php` with the route in the `path` argument, and that Back restores the previous state.
- Storage tests use the exported `savePrefs()` to build fixtures and read what it wrote, instead of hard-coding the private key or version.

## Fixtures

- Factories with overrides: `aSession( { outcome: 'blocked_by_rules' } )`, `aRule( { action: 'allow' } )`, `collectionResponse( items, total, pages )`.
- Datetimes in fixtures are GMT without offset (`'2026-04-22T09:23:00'`) like the REST API; expected strings account for the `America/New_York` test zone.
- Response objects for `parse: false` only need `json()` and `headers.get()`; cast `as unknown as Response`.

## What to Cover

For a new list, drawer, or setting, tests exist for:

- each row action's eligibility per outcome and rule state, and that its callback opens the right drawer or dialog with the right context;
- the query or path built from the view, including defaults omitted and date bounds converted to UTC;
- URL mirroring, Back and Forward reconciliation, deep links, and the page correction that must not run on error;
- the three empty-state messages (error, no matches, no data) and the load-error notice;
- the safe default while settings are unknown or failed to load;
- a drawer that refuses to close while saving, resets on reopen, shows the inline error on failure, and shows the snackbar and closes on success;
- the store: resolver caching by normalized query, invalidation after each mutation, response validation failures surfacing as errors;
- stale-response handling for detail requests;
- date rendering in the browser time zone;
- keyboard reachability and accessible names of popover and tooltip triggers, dialogs, and tabs.

Update the existing tests when copy changes; assertions use the merchant-facing strings on purpose.

## Manual Verification

- Build the production bundle and test it with the current supported WordPress and WooCommerce versions.
- Exercise the complete changed flow, not only each control alone. Cover useful filter combinations, loading, empty, error, cancellation, retry, duplicate, Back/Forward, reload, deep links, and the feature gate on and off when relevant.
- Check the browser console, PHP logs, and database errors while testing. Investigate slow tests and unexpected page delays instead of increasing timeouts without finding the cause.
- Compare screenshots with the approved design for component choice, copy, spacing, colour, alignment, responsive behavior, and every important state.

## Browser Scripts

The scripts in `assets/js/` are IIFEs tested in `tests/js/*.test.js` with `/** @jest-environment jsdom */`. A test sets the globals the script reads (`window.wcFraudProtection`, `window.wp.data`, `window.wc`, jQuery), `require()`s the script, and asserts on the callbacks it registered and the requests it shaped. `blackbox-init.test.js` covers `acquireSessionId()` and `reset()`; consumer tests mock `window.wcFraudProtection` directly. Their contract is the public JavaScript API in `README.md`; changing it needs the compatibility review `AGENTS.md` requires.
