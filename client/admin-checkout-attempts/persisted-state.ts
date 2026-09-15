import type { View } from '@wordpress/dataviews';

import type { FinalStatus } from './types';

// Display preferences for the checkout attempts list: the choices that are
// personal to the browser rather than part of what the list is showing — which
// columns are visible, the table density, and the page size. They live in
// localStorage so they persist across visits.
//
// Navigation state (search, filters, status tab, sort, page) is NOT stored here:
// it lives in the URL, so a link reproduces the view and Back/Forward restore it.
//
// Storage is a best-effort convenience: it may be unavailable (private mode,
// quota, disabled) and a stored payload may be stale from an older build, so
// every read and write is defensive.

const STORAGE_KEY = 'wc-fraud-protection-checkout-attempts-prefs';

// Bump when the shape below changes so stale payloads are dropped, not merged.
const VERSION = 2;

export type StatusTab = 'all' | FinalStatus;

export type DisplayPrefs = {
	fields?: string[];
	perPage?: number;
	layout?: View[ 'layout' ];
};

/**
 * Load the stored display preferences, ignoring anything missing or invalid.
 *
 * @return The restored preferences, empty when nothing valid is stored.
 */
export function loadPrefs(): DisplayPrefs {
	let raw: string | null = null;

	try {
		raw = window.localStorage.getItem( STORAGE_KEY );
	} catch {
		return {};
	}

	if ( ! raw ) {
		return {};
	}

	try {
		const parsed = JSON.parse( raw );

		if (
			! parsed ||
			parsed.version !== VERSION ||
			typeof parsed.prefs !== 'object' ||
			parsed.prefs === null
		) {
			return {};
		}

		const prefs = parsed.prefs;
		const result: DisplayPrefs = {};

		if (
			Array.isArray( prefs.fields ) &&
			prefs.fields.every(
				( field: unknown ) => typeof field === 'string'
			)
		) {
			result.fields = prefs.fields;
		}
		if (
			typeof prefs.perPage === 'number' &&
			Number.isFinite( prefs.perPage ) &&
			prefs.perPage > 0
		) {
			result.perPage = prefs.perPage;
		}
		if ( prefs.layout && typeof prefs.layout === 'object' ) {
			result.layout = prefs.layout;
		}

		return result;
	} catch {
		return {};
	}
}

/**
 * Persist the display preferences. Storage failures are ignored on purpose: the
 * list stays usable, it just will not remember the change.
 *
 * @param prefs The preferences to persist.
 */
export function savePrefs( prefs: DisplayPrefs ): void {
	try {
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { version: VERSION, prefs } )
		);
	} catch {
		// Ignore storage failures (private mode, quota, disabled).
	}
}
