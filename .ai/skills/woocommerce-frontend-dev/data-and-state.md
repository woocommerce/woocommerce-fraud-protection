# Data and State

## Table of Contents

- [Two Kinds of State](#two-kinds-of-state)
- [Store Shape](#store-shape)
- [Reading a Store](#reading-a-store)
- [Caching, Invalidation, and Retry](#caching-invalidation-and-retry)
- [Mutations](#mutations)
- [Fetching and Validating Responses](#fetching-and-validating-responses)
- [Errors](#errors)
- [Stale and Concurrent Requests](#stale-and-concurrent-requests)
- [Unknown State Is the Safe State](#unknown-state-is-the-safe-state)
- [Preloading](#preloading)
- [Per-User Preferences and localStorage](#per-user-preferences-and-localstorage)
- [Dates and Time Zones](#dates-and-time-zones)

## Two Kinds of State

- **Shared server state** lives in registered `@wordpress/data` stores: `wc-fraud-protection/settings` (`client/admin-settings/data/store.ts`) and `wc-fraud-protection/rules` (`client/admin-settings/data/rules-store.ts`). Anything that more than one view reads or writes belongs here, so a change made on one surface (the enable drawer on the checkout attempts list) is visible on another (the settings page) without a reload.
- **Page-local list state** lives in a hook when one page owns the data and keys it by its DataViews view: `useCheckoutAttempts( view, finalStatus )` keeps `sessions`, totals, `isLoading`, `error`, and a `refresh()` in component state.
- **UI state** (open drawers, the item being deleted, form data) is component state, often behind a small hook with an explicit state machine such as `useRuleFormDrawer()` (`closed` | `create` | `edit { ruleId }`).

## Store Shape

Stores are created with `createReduxStore( name, { reducer, actions, selectors, resolvers } )` and registered at module load with `register()`. The entry point imports the store module (`import './data/store'`) so it is registered before any page renders.

- **Reducer**: a typed `State` and a discriminated `Action` union; every case returns a new object.
- **Selectors**: plain read functions. A selector that takes a query normalizes it (`__unstableNormalizeArgs`) so equivalent queries share one cache entry and one resolution.
- **Resolvers**: fetch on first select and dispatch a `RECEIVE_*` action. A resolver that fails either dispatches an error into state (settings) or throws so `getResolutionError()` reports it (rules).
- **Thunk actions**: `( args ) => async ( { dispatch, select } ) => { ... }` own the request, dispatch the loading flag and the error, and return `true`/`false` or the entity. The types `StoreCallback`, `StoreSelectors`, and `StoreActions` in each store document what a thunk may call.

## Reading a Store

Wrap `useSelect` in a hook that returns everything a page needs, including loading and error state derived from the resolution metadata:

```typescript
const store = select( settingsStore );
return {
	settings: store.getSettings(),
	error: store.getError(),
	isLoading:
		store.isResolving( 'getSettings' ) ||
		! store.hasFinishedResolution( 'getSettings' ),
};
```

For a parameterized selector pass the normalized arguments to `isResolving`, `hasFinishedResolution`, and `getResolutionError` (`useRules()` and `useRule()` in `client/admin-settings/hooks/use-rules.ts`). Memoize the normalized query so the dependency array is stable.

## Caching, Invalidation, and Retry

- Collection responses are cached per normalized query key (`JSON.stringify( normalizeRulesQuery( query ) )`), single entities by id.
- After a mutation invalidate what it affected: `invalidateResolutionForStoreSelector( 'getRules' )` after create, update, and delete; `invalidateResolution( 'getRule', [ id ] )` after delete. The UI keeps the previous rows on screen while the new query resolves; do not blank the table or drop its actions during that time.
- A retry is an invalidation: `useRule().retry()` calls `invalidateResolution( 'getRule', [ id ] )`, and the drawer offers it in the error notice.
- Page-local lists re-run their effect through a version counter (`refresh()` increments `requestVersion`), which keeps the view, tab, and URL untouched.

## Mutations

- Shared actions accept overrides so any surface can write the same setting: `saveSettings()` saves the settings page's dirty edit, `saveSettings( { automatic_protection: true } )` saves a given value. Both update the store from the response. Add a parameter to the existing action instead of a second `apiFetch` POST in a component.
- An action refuses to start while another is in flight (`if ( select.isSaving() || select.isOptingOut() ) return false;`).
- Hooks add user feedback around the action: `createSuccessNotice( message, { type: 'snackbar' } )` from `@wordpress/notices` on success (`useFraudProtectionSettings().save()`, `useRuleMutation().saveRule()`), an inline `Notice` near the control on failure.
- A mutation that returns the entity validates it with the same type guard as the resolver and dispatches it into the cache (`receiveRule`) before invalidating the lists.
- Send the `origin` of a rule mutation (`rules`, `checkout_attempts`, or `api`) so server-side telemetry knows which surface produced it.

## Fetching and Validating Responses

- `apiFetch( { path } )` for a JSON object. `apiFetch( { path, parse: false } )` for a collection, then read `response.json()` and the `X-WP-Total` and `X-WP-TotalPages` headers.
- Build paths with `addQueryArgs()` from `@wordpress/url` or `URLSearchParams`. Collection parameters are `page`, `per_page`, `orderby`, `order`, plus the endpoint's filters; omit empty optional values.
- Validate every response before it enters state. `rules-store.ts` uses an `isRule()` type guard for items and `parseTotalHeader()` for the totals, and throws a generic, translatable "Could not get a valid response from the server." on anything unexpected. Do not cast `response.json()` to the expected type and hope.
- Options for a filter can be fetched lazily by DataViews through a field's `getElements` (`payment-method-elements.ts`), which keeps the request off routes that do not render the list.

## Errors

- Store one error object with an `operation` (`load`, `save`, `opt_out`) and a `message` that is the server message when it is a non-empty string and `null` otherwise. Pages map the operation to a translatable sentence and append the server detail only when it differs.
- Keep messages generic and free of internals. Server-side, REST errors are already generic (a 500 or 503 without database details).
- Show a load error in a `Notice` above the list and pass `empty={ null }` to DataViews so the empty state does not also claim there is no data. Show a mutation error inline inside the drawer or dialog and keep it open; reset the error when the drawer opens (`setError( null )`) and on every close path.
- A duplicate-rule error (`woocommerce_fraud_protection_duplicate_rule`, with `data.rule_id`) is rendered as field validity with an "Edit existing rule" affordance rather than as a generic notice (see dataviews.md).

## Stale and Concurrent Requests

- In an effect that fetches, keep an `active` flag and ignore the result after cleanup (`use-checkout-attempts.ts`).
- Key a form by its identity so a late detail response cannot repopulate a different form: the rule drawer renders `<RuleForm key={ formKey } />` where the key encodes open state and `create` | `edit-<id>` | `context-<attemptId>`. `tests/js/rules-page-transitions.test.tsx` shows the three cases that must hold.
- Do not derive corrections from a failed request. The page-correction effect on the checkout attempts list returns early when `error` is set, because a failed request reports zero pages and would otherwise reset the page and refetch.
- Disable the primary button and the close icon while saving, and cancel Escape and backdrop dismissal in `onOpenChange` (see ui-components.md), so a save in flight cannot look cancelled.

## Unknown State Is the Safe State

Settings can be loading or can have failed to load, in which case `getSettings()` is `null`. Treat that as "automatic protection is on":

```typescript
protectionOn: settings ? settings.automatic_protection === true : true,
```

This hides the protection-off banner and the "Turn on automatic fraud prevention" row action until the real state is known, and it stops an enable request from starting before the initial GET finishes. Apply the same rule to any control whose safe default is "do nothing".

## Preloading

The settings GET is preloaded by PHP with `rest_preload_api_request()` and `createPreloadingMiddleware` on the routes that read it, so the first `getSettings()` resolves from the preloaded payload. Preloaded requests still go through `apiFetch`, so tests that mock it are unaffected. Extend `FraudProtectionSettingsPage::maybe_preload_settings_data()` for a new route instead of injecting a global.

## Per-User Preferences and localStorage

- **Per-user, cross-device** choices (a dismissed banner) use `useUserPreferences()` from `@woocommerce/data`: wait while `isRequesting`, read the key, write with `updateUserPreferences( { key: 'yes' } )`. The key must be allow-listed on the server (see architecture.md).
- **Per-browser display preferences** (visible columns, density, page size) go to `localStorage` through `persisted-state.ts`: one namespaced key, a versioned envelope (`{ version, prefs }`) whose version is bumped when the shape changes, every field validated on read, and every read and write wrapped in `try`/`catch` because storage can be unavailable.
- Navigation state (search, filters, tab, sort, page) never goes to storage. It belongs in the URL so links reproduce the view and Back and Forward restore it, and because a storage key is shared between subdirectory sites on one origin.

## Dates and Time Zones

- The REST API returns GMT datetimes without an offset (`2026-04-22T09:23:00`). Append `Z` before formatting so the value is parsed as UTC.
- Merchants read the lists in their browser, so dates render in the browser's time zone: `dateI18n( format, value, browserTimeZone )` with `browserTimeZone` from `client/admin-settings/rule-date.ts`. Use the site's date formats from `getSettings().formats` in `@wordpress/date` (`dates.ts` derives a date-only variant of the abbreviated datetime so tooltips match the column).
- Date-range filter values are local calendar days; convert them to UTC bounds with `getUtcDateFilterBound( value, endOfDay )` before sending them.
- Tests run with the process time zone pinned to `America/New_York` so these conversions are asserted, not assumed.
