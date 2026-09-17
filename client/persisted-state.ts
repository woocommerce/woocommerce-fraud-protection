import type { View } from '@wordpress/dataviews';

export type DisplayPrefs = {
	fields?: string[];
	perPage?: number;
	layout?: View[ 'layout' ];
};

export function loadPrefs( storageKey: string, version: number ): DisplayPrefs {
	let raw: string | null = null;

	try {
		raw = window.localStorage.getItem( storageKey );
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
			parsed.version !== version ||
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

export function savePrefs(
	storageKey: string,
	version: number,
	prefs: DisplayPrefs
): void {
	try {
		window.localStorage.setItem(
			storageKey,
			JSON.stringify( { version, prefs } )
		);
	} catch {
		// Storage is optional. The page remains usable without it.
	}
}
