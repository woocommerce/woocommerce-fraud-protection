# Components and Accessibility

## Table of Contents

- [Component Library](#component-library)
- [Semantics Through `render`](#semantics-through-render)
- [Accessibility Requirements](#accessibility-requirements)
- [Popover Versus Tooltip](#popover-versus-tooltip)
- [Drawers](#drawers)
- [Dialogs](#dialogs)
- [Notices and Snackbars](#notices-and-snackbars)
- [Icons and State Indicators](#icons-and-state-indicators)
- [Portals and z-index](#portals-and-z-index)
- [Links](#links)
- [Loading Indicators](#loading-indicators)

## Component Library

The UI is built with `@wordpress/ui` (the WordPress design-system components) and `@wordpress/dataviews`. `@wordpress/components` is only imported for its stylesheet; do not add components from it, and do not write a custom control when `@wordpress/ui` has one. Components in use and how the app uses them:

| Component | Use |
| --------- | --- |
| `Stack` | Layout: `direction`, `gap` (`xs` ... `xl`), `align`, `justify`, `render` for a semantic element |
| `Card.Root`, `Card.Header`, `Card.Title`, `Card.Content` | Settings cards; `Card.Root render={ <section /> }`, `Card.Title render={ <h2 /> }` |
| `Text` | Typography: `variant="body-md"`, `body-lg`, `heading-sm`, `heading-md`, `render={ <p /> }` |
| `Button` | `variant="solid"` (primary), `minimal`, `outline`, `unstyled`; `size="compact"`; `loading`; `type="button"` inside the settings form |
| `LinkButton` | A button-styled router link: `render={ <Link to={ href } /> }` |
| `Notice.Root` and children | Inline messages: `intent="error"`, `warning`, `info`; `Notice.Description`, `Notice.Actions`, `Notice.ActionButton`, `Notice.ActionLink`, `Notice.CloseIcon` |
| `Badge` | Outcome and rule badges: `intent="stable"`, `medium`, `high`, ... |
| `Checkbox` | `onCheckedChange`; label with `htmlFor` pointing at the checkbox `id` |
| `Tabs.Root`, `Tabs.List`, `Tabs.Tab`, `Tabs.Panel` | Status tabs; `Tabs.List variant="minimal"` |
| `EmptyState.Root`, `EmptyState.Title`, `EmptyState.Description` | List empty states |
| `Drawer.*`, `Dialog.*`, `Popover.*`, `Tooltip.*` | See the sections below |
| `InputControl`, `ValidatedInputControl`, `ValidityIndicator` | Form inputs and validation messages inside `DataForm` |
| `Spinner`, `Skeleton`, `VisuallyHidden`, `Icon` | Loading and assistive helpers |

Check the `@wordpress/ui` version in `package.json` before relying on a prop; the package is pre-1.0 and its API moves.

## Semantics Through `render`

Components accept a `render` prop to swap the underlying element. Use it to give landmarks and headings their real elements: `Card.Root render={ <section /> }`, `Card.Title render={ <h2 /> }`, `Text render={ <p /> }` or `<dt />` and `<dd />`, `Stack render={ <span /> }` inside a table cell. Breadcrumbs are a `<nav aria-label="Breadcrumb">` with a `<Link>` for the parent and `aria-current="page"` on the current segment.

## Accessibility Requirements

These were review findings on the first list page and are now baseline:

- **Real triggers.** Anything that opens a popover, tooltip, or menu is a `<button type="button">` (or an `@wordpress/ui` button), never a `<span>`, so it is focusable and operable by keyboard.
- **Accessible names.** An icon-only button carries `aria-label`; its icon is `aria-hidden="true"`. When a visible label is hidden in a compact layout, keep the text in the accessibility tree (`VisuallyHidden`, or the `.is-icon-only` pattern in the rule chip).
- **Tabs contain their panels.** Each `Tabs.Tab` has a `Tabs.Panel` with the content it controls (see dataviews.md).
- **Loading is announced.** `aria-busy` on the region plus a `role="status"` or `VisuallyHidden` text such as "Loading rules".
- **Meaning is not colour alone.** State is conveyed by an icon shape and a label (a check for allow, a prohibition circle for block) in addition to colour.
- **Popovers, not tooltips, for content that matters** (see the next section).
- **Forms.** Inputs have labels (`label` prop or `htmlFor`), invalid inputs have `aria-invalid` and `aria-describedby` pointing at the message, and validity is reflected in the primary button's `disabled` state.
- Verify with tests that use roles and accessible names, `userEvent.tab()`, and `userEvent.keyboard( '{Enter}' )` (see testing.md).

## Popover Versus Tooltip

- **`Popover`** for an explanation, especially one that contains a link: its content is reachable by keyboard, touch, and screen readers. Pattern from `outcome-info.tsx`:

  ```tsx
  <Popover.Root>
  	<Popover.Trigger openOnHover render={ <button type="button" aria-label={ ariaLabel } /> } />
  	<Popover.Popup positioner={ <Popover.Positioner side="bottom" sideOffset={ 8 } /> }>
  		<Popover.Title className="screen-reader-text">{ title }</Popover.Title>
  		{ children }
  	</Popover.Popup>
  </Popover.Root>
  ```

  `openOnHover` keeps the hover affordance sighted users expect for an info icon.

- **`Tooltip`** only for short supplementary text with no interaction inside, and only on a focusable trigger. Tooltip content is not reliably exposed to assistive technology, so the trigger's `aria-label` must carry the same information (the rule chip's label names the rule type and its date; the tooltip repeats the date).

A `Tooltip` on a `<span>`, or a tooltip holding a link, is a review blocker.

## Drawers

`Drawer` is the side panel used for the rule form and the enable-protection flow. Pattern from `rule-form-drawer.tsx` and `enable-fraud-prevention-drawer.tsx`:

```tsx
<Drawer.Root
	open={ open }
	onOpenChange={ ( nextOpen, eventDetails ) => {
		if ( ! nextOpen && isSaving ) {
			eventDetails.cancel(); // Escape and backdrop clicks while saving.
			return;
		}
		if ( ! nextOpen ) {
			onClose();
		}
	} }
	swipeDirection="right"
>
	<Drawer.Popup portal={ <Drawer.Portal className="wc-fraud-protection-rule-form__drawer-portal" /> }>
		<Drawer.Header>
			<Drawer.Title>{ title }</Drawer.Title>
			<Drawer.CloseIcon label={ __( 'Close', 'woocommerce-fraud-protection' ) } disabled={ isSaving } />
		</Drawer.Header>
		<Drawer.Content>
			<Stack direction="column" gap="lg">
				<Drawer.Description>...</Drawer.Description>
				...form and inline error Notice...
			</Stack>
		</Drawer.Content>
		<Drawer.Footer>
			<Button variant="solid" type="button" loading={ isSaving } disabled={ ! canSave || isSaving } onClick={ save }>...</Button>
		</Drawer.Footer>
	</Drawer.Popup>
</Drawer.Root>
```

Rules the reviews established:

- **Cancel dismissal while saving** in `onOpenChange` with `eventDetails.cancel()`. `disablePointerDismissal` only covers pointer input; Escape would still close the drawer and make a save that later succeeds look cancelled. Also disable the close icon.
- **Reset on open.** Start from the default form state and clear any previous error each time the drawer opens (an effect on `open`, or a `key` that changes with the drawer's identity).
- **Primary button** shows `loading` while saving and is disabled until there is something valid to save (mirroring the settings page's dirty check).
- **Success** shows a snackbar, closes the drawer, and may navigate (`onSuccess`). **Failure** shows an inline `Notice` inside the drawer and keeps it open.
- **Shared controls.** The enable drawer reuses `AutomaticProtectionControl` and the settings store rather than duplicating the checkbox and the POST.

## Dialogs

`Dialog` is used for confirmations (rule deletion). Pattern from `rule-delete-dialog.tsx`:

- `Dialog.Root open onOpenChange` (refuse to close while deleting), `Dialog.Popup size="small" portal={ <Dialog.Portal className="..." /> }`, `Dialog.Header` with `Dialog.Title` and `Dialog.CloseIcon`, `Dialog.Content` with `Dialog.Description`, `Dialog.Footer` with a `variant="minimal"` Cancel and a `variant="solid"` destructive button styled through the `--wp-ui-button-*` error tokens.
- One `close()` function resets the error and calls `onClose`, and every close path (Cancel, close icon, backdrop) goes through it, so an error cannot leak into the next opening.
- Show what will be affected (the read-only `DataForm` of the rule) and state the consequence in the description.

## Notices and Snackbars

- Success feedback: `createSuccessNotice( message, { type: 'snackbar' } )` from the `@wordpress/notices` store.
- Errors and warnings: an inline `Notice.Root intent="error" | "warning" | "info"` next to the control or above the list, with `Notice.Actions` for a follow-up (`Notice.ActionButton` for an action, `Notice.ActionLink` for a link) and `Notice.CloseIcon` when it is dismissible. A dismissible banner remembers its dismissal through user preferences (see data-and-state.md).
- Give a notice a `key` when its content switches between variants so it re-mounts and is re-announced.

## Icons and State Indicators

- Use `@wordpress/icons` (`info`, `notAllowed`, `published`, `caution`, ...) through `<Icon icon={ published } size={ 16 } aria-hidden="true" />`. Local SVGs were replaced in review; do not add new ones.
- A state chip is an icon plus a text label plus a colour class (`is-allow`, `is-block`), never colour alone.
- `Badge intent` carries outcome severity (`stable` for allowed, `medium` for flagged, `high` for blocked).

## Portals and z-index

`@wordpress/ui` renders drawers, dialogs, popovers, and tooltips into a portal on `document.body` and leaves their z-index at `auto`, expecting the host to set a variable. Without it the WordPress admin bar and the positioned content column paint over the layer and the drawer looks unstyled and clipped. Set the variable on the portal that holds the component:

- Preferred: pass `portal={ <Drawer.Portal className="wc-fraud-protection-rule-form__drawer-portal" /> }` (or `Dialog.Portal`) and set `--wp-ui-drawer-z-index` / `--wp-ui-dialog-z-index` on that class, using `z-index( '.components-modal__screen-overlay' )` from `@wordpress/base-styles`.
- When the component gives no portal prop (the popover), scope through the portal element: `[data-base-ui-portal]:has( .your-popup-class ) { --wp-ui-popover-z-index: 100001; }`. 100001 clears the admin bar and its submenus.

Styles for portaled content cannot be nested under the page's root class, because the content is not inside it.

## Links

- External documentation links: `<a href target="_blank" rel="noopener noreferrer">`. Every "Learn more" opens in a new tab.
- Links inside a translated sentence use `createInterpolateElement( __( '... <a>text</a> ...' ), { a: <a ... /> } )`; give the anchor a `<span />` child or a documented `eslint-disable-next-line jsx-a11y/anchor-has-content` when the text is injected.
- In-app links use `<Link to={ getFraudProtectionRoute( '/rules' ) }>` or `LinkButton render={ <Link /> }`, never a raw `<a href>` to an admin URL.

## Loading Indicators

- A control that is not ready renders a `Spinner` with a `role="status"` text instead of a disabled control.
- Metrics render a `Skeleton` of the value's height while loading and `aria-busy` on the container.
- Do not show a control whose safe state is unknown (see "Unknown State Is the Safe State" in data-and-state.md).
