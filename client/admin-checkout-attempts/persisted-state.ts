import type { View } from '@wordpress/dataviews';

import type { FinalStatus } from './types';

// The list remembers how the merchant left it — the tab and the DataViews view
// (columns, density, sort, per-page, search, page and the outcome/provider/rules
// filters) — in localStorage, so it survives a reload. It is a per-browser
// convenience, not shared state, so every read and write is defensive: storage
// may be unavailable (private mode, quota, disabled) and the stored payload may
// be stale from an older build. A restored page past the last one is clamped by
// the list itself.

const STORAGE_KEY = 'wc-fraud-protection-checkout-attempts-state';

// Bump when the shape below changes so stale payloads are dropped, not merged.
const VERSION = 1;

export type StatusTab = 'all' | FinalStatus;

export type PersistedState = {
	view: View;
	tab: StatusTab;
};

const TABS: StatusTab[] = [ 'all', 'allowed', 'blocked' ];

/**
 * Load the persisted state, falling back to defaults for anything missing or
 * invalid.
 *
 * @param fallback The defaults to use for missing or invalid values.
 * @return The restored state.
 */
export function loadState( fallback: PersistedState ): PersistedState {
	let raw: string | null = null;

	try {
		raw = window.localStorage.getItem( STORAGE_KEY );
	} catch {
		// Storage is unavailable; use defaults.
		return fallback;
	}

	if ( ! raw ) {
		return fallback;
	}

	try {
		const parsed = JSON.parse( raw );

		if (
			! parsed ||
			parsed.version !== VERSION ||
			typeof parsed.state !== 'object'
		) {
			return fallback;
		}

		const state = parsed.state;
		const view =
			state.view && typeof state.view === 'object' ? state.view : {};

		return {
			view: { ...fallback.view, ...view },
			tab: TABS.indexOf( state.tab ) !== -1 ? state.tab : fallback.tab,
		};
	} catch {
		// Corrupt payload; use defaults.
		return fallback;
	}
}

/**
 * Persist the current state. Storage failures are ignored on purpose: the list
 * stays usable, it just will not remember this change.
 *
 * @param state The state to persist.
 */
export function saveState( state: PersistedState ): void {
	try {
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { version: VERSION, state } )
		);
	} catch {
		// Ignore storage failures (private mode, quota, disabled).
	}
}
