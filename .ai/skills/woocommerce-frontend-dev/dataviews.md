# Lists and Forms with DataViews

## Table of Contents

- [Imports](#imports)
- [Fields](#fields)
- [The View](#the-view)
- [Composition: Tabs, Toolbar, Layout](#composition-tabs-toolbar-layout)
- [Navigation State in the URL](#navigation-state-in-the-url)
- [Display Preferences](#display-preferences)
- [Row Actions](#row-actions)
- [Loading and Empty States](#loading-and-empty-states)
- [Sorting and Filtering Server-Side](#sorting-and-filtering-server-side)
- [Forms with DataForm](#forms-with-dataform)

## Imports

Runtime components come from the `/wp` entry point, which is what the DataViews package guide requires for plugin scripts built with `@wordpress/scripts`; types come from the package root:

```typescript
import { DataViews, DataForm, useFormValidity } from '@wordpress/dataviews/wp';
import type { Action, Field, Form, View } from '@wordpress/dataviews';
```

`client/dataviews-wp.d.ts` provides the types for the `/wp` subpath. Tests that replace DataViews mock `@wordpress/dataviews/wp`.

## Fields

A `Field< Item >` describes a column, a filter, or both:

- `id`, `label`, and `type` (`'text'`, `'date'`, `'email'`, ...). `header` when the column header should differ from the label.
- `getValue( { item } )` returns the sortable and searchable value; `render( { item } )` returns the cell. Return the em dash `—` for an empty value rather than an empty cell.
- `elements: [ { value, label } ]` for a fixed option list, or `getElements: async () => Option[]` to load options from REST lazily (`payment-method-elements.ts`).
- `filterBy: { operators: [ 'is' ] }`, `[ 'isAny' ]`, `[ 'between' ]`, or `filterBy: false`. A single-select operator renders a radio-style filter.
- `enableSorting`, `enableHiding`, `enableGlobalSearch` to switch behaviours off. A filter-only field (not a column) is a field with `enableSorting: false` and `enableGlobalSearch: false` that is left out of `view.fields` (the `rules` field on the checkout attempts list).
- Keep fields in a `getFields( config, isCompact )` function or a module constant so tests can inspect them, and render cell components (`OutcomeBadge`, `RuleChip`) from them.

## The View

The `View` object is the list's whole state: `{ type: 'table', page, perPage, sort: { field, direction }, search, filters, fields, layout, titleField }`. The page owns it in `useState` and passes `view` and `onChangeView` to DataViews together with:

- `data`, `getItemId={ ( item ) => String( item.id ) }`, `defaultLayouts={ { table: {} } }`
- `paginationInfo={ { totalItems, totalPages } }` from the response headers
- `isLoading`, `empty`, `actions`, and optionally `config={ { perPageSizes: [ 20, 50, 100 ] } }` or `search={ false }`
- Column widths through `view.layout.styles` (rules page) and density through `view.layout.density`

Derive the REST query from the view in one exported function (`getQueryFromView( view )` for rules, `buildListPath( view, finalStatus )` for checkout attempts) so it is unit-tested and the page never builds paths by hand.

## Composition: Tabs, Toolbar, Layout

Both list pages compose DataViews from its parts so the status tabs share a row with the search box, the filters toggle, and the view options, with the filters bar and the table below:

```tsx
<DataViews data={ rows } fields={ fields } view={ view } onChangeView={ onChangeView } ...>
	<Tabs.Root value={ tab } onValueChange={ onTabChange }>
		<Stack className="wc-fraud-protection-rules__toolbar" direction="row" align="center" justify="space-between" gap="sm">
			<Tabs.List variant="minimal">
				<Tabs.Tab value="all">{ __( 'All', 'woocommerce-fraud-protection' ) }</Tabs.Tab>
				...
			</Tabs.List>
			<Stack className="wc-fraud-protection-rules__view-controls" direction="row" align="center" gap="xs">
				<DataViews.Search label={ __( 'Search by email or IP', 'woocommerce-fraud-protection' ) } />
				<DataViews.FiltersToggle />
				<DataViews.ViewConfig />
			</Stack>
		</Stack>
		<DataViews.FiltersToggled className="dataviews-filters__container" />
		<Tabs.Panel value="all">{ tab === 'all' && <DataViews.Layout /> }</Tabs.Panel>
		<Tabs.Panel value="allow">{ tab === 'allow' && <DataViews.Layout /> }</Tabs.Panel>
		<DataViews.Footer />
	</Tabs.Root>
</DataViews>
```

- **Every tab controls a panel that contains the content.** The list is the same across tabs, so it is rendered only inside the active panel; tabs that point at empty panels while the list sits outside them fail the accessibility contract and were a review finding.
- A tab maps either to a filter on an existing field (rules: the `action` filter) or to a separate query parameter (checkout attempts: `status`). Switching tabs resets `page` to 1.
- Pass `className="dataviews-filters__container"` to `DataViews.FiltersToggled` so the filters bar gets the spacing the shared styles give that class.
- The `wc-fraud-protection-<page>__toolbar` and `__view-controls` classes use the `toolbar` mixin from `_settings-list-page.scss`; see styling.md.

## Navigation State in the URL

Merchants link to lists and use Back and Forward, so the navigation part of the view (search, filters, tab, sort, page) is mirrored to the URL. The checkout attempts page is the reference implementation; follow it for a new list:

- `viewFromParams( searchParams, prefs )` builds the initial view from `useSearchParams()` plus the stored display preferences; `getTab( params )` reads the tab.
- `navParams( view, tab )` serializes only the navigation state, omitting defaults (`paged=1`, `order=desc`) so URLs stay short; `serializeNav()` sorts the parameters to compare states.
- `listAdminPath( view, tab )` builds the full admin URL with `getNewPath( { page: 'wc-settings', tab: 'woocommerce_fraud_protection', ...params }, '/checkout-attempts', {} )`; the browser path stays `/wp-admin/admin.php` with the route in the `path` argument.
- `commitToUrl( view, tab, replace )` writes it through `getHistory()`: `push()` for user navigation so Back restores the previous list state, `replace()` for automatic corrections and for search typing so each keystroke does not add a history entry.
- An effect reconciles local state from the URL on external navigation (Back, Forward, a deep link) and skips the list's own writes, which also preserves a filter that has been added but has no value yet, since that is not representable in the URL.
- A page past the last one falls back to the last existing page, or page 1 when there are no results, with `replace()`. The correction is skipped while loading and when the request failed.

The rules page currently keeps its view in component state only. Prefer the URL-mirrored pattern for any list a merchant may link to or return to.

## Display Preferences

Visible columns (`view.fields`), density (`view.layout`), and page size (`view.perPage`) are personal to the browser and are saved with `savePrefs()` on every `onChangeView`, restored with `loadPrefs()` at mount. They are never part of the URL. See data-and-state.md for the storage rules.

## Row Actions

`Action< Item >[]` entries have `id`, `label`, `isEligible( item )`, `callback( items )`, and `supportsBulk: false` for single-row actions:

- Build them in a `buildActions( config, callbacks )` function so eligibility is unit-tested per outcome and rule state. Each callback guards against a missing item or value before calling out.
- Eligibility follows the data, not the UI: a value with no matching rule offers the opposite of the enforced result (block an allowed attempt, allow a blocked one); a value with a rule offers edit and delete.
- Always pass the actions, including while a new query loads; dropping them makes the Actions column disappear from rows that are still on screen.
- An action that opens a drawer keeps the list behind it; the drawer's `onSuccess` calls the list's `refresh()` so rows update without changing the view or URL.
- Do not ship an action that does nothing. A placeholder `noop` action was accepted once, explicitly, to validate action visibility before the rule UI existed; it needs the same explicit agreement again.
- The public `label` type is a string, but DataViews renders a function label through its menu item, so one item can carry styling (`actions.tsx` documents the cast). Use this sparingly and keep the rest of the menu native.

## Loading and Empty States

- Put `aria-busy={ isLoading }` on the list container and a `VisuallyHidden` "Loading ..." text on the initial load. Keep rows visible while a new query loads.
- The empty message depends on why the list is empty. A load error, a search or filter with no matches, and a store with no records are three different sentences; do not tell a merchant there were no attempts in the last 30 days when a filter excluded them. The rules page uses `EmptyState.Root`, `EmptyState.Title`, and `EmptyState.Description`; the checkout attempts page passes a paragraph.
- Pass `empty={ null }` while an error is shown above the list.

## Sorting and Filtering Server-Side

The lists are paginated on the server, so sorting and filtering are server-side and the UI only expresses them:

- Sort by what the merchant sees. Sorting the Provider column by gateway id put "Direct bank transfer" after "Cash on delivery"; the REST endpoint now sorts by the resolved title across all pages. When the displayed value is not stored and cannot be sorted server-side, disable sorting for that column (`enableSorting: false`) instead.
- A filter must compare the same normalized value the row display uses (an IPv6 rule chip showed while the "with rules" filter excluded the row because the two paths normalized differently). Add the filter to the REST endpoint and the store; test both sides.
- Date filters send UTC bounds (`getUtcDateFilterBound`); the PHP side validates them strictly.

## Forms with DataForm

The rule form is a `DataForm` driven by field definitions:

- `Form` = `{ layout: { type: 'regular' }, fields: [ 'action', 'type', 'value' ] }`.
- Select fields use `Edit: 'select'` with `elements` and `isValid: { elements: true }`. A custom control is a component receiving `DataFormControlProps< Data >` (`RuleValueEditControl`), reading `field.getValue( { item: data } )` and calling `onChange( field.setValue( { item: data, value } ) )`.
- Validation rules live in `isValid`: `required`, `maxLength`, and `custom: ( item ) => message | null` (the complete-IP check). `useFormValidity( data, fields, form )` returns `validity` (passed back to `DataForm`) and `isValid` (gates the primary button). `ValidatedInputControl` takes `customValidity` and `required`; `markWhenOptional` shows the optional marker.
- A server-side validation error (the duplicate rule) is rendered as field validity: `InputControl` with `aria-invalid="true"` and `aria-describedby` pointing at a `ValidityIndicator type="invalid"`, plus an "Edit existing rule" button when the response carries the existing id. Clear it on the next change.
- Disable the fields while saving (`isDisabled`) and ignore `onChange` while saving.
- Fields that came from the launching context (the attempt's email or IP when creating a rule from the list) are locked with `matchFieldsDisabled`.
- The delete dialog reuses `getRuleFormFields( { type, disabled: true } )` and a read-only `DataForm` (`onChange={ () => undefined }`) to show what is being deleted.
- Keep `getRuleFormFields`, `getInitialRuleFormData`, and the validators exported so they are unit-tested without rendering the drawer.
