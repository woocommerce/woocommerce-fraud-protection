import type { View } from '@wordpress/dataviews';

export type DisplayPrefs = {
	fields?: string[];
	perPage?: number;
	layout?: View[ 'layout' ];
};

type PreferenceStoreConfig = {
	storageKey: string;
	version: number;
	supportedFields: readonly string[];
	supportedPerPage: readonly number[];
	supportedDensities: readonly string[];
};

export type PreferenceStore = {
	load: () => DisplayPrefs;
	save: ( prefs: DisplayPrefs ) => void;
	fromView: ( view: View ) => DisplayPrefs;
};

export function createPreferenceStore( {
	storageKey,
	version,
	supportedFields,
	supportedPerPage,
	supportedDensities,
}: PreferenceStoreConfig ): PreferenceStore {
	const sanitize = ( prefs: DisplayPrefs ): DisplayPrefs => {
		const fields = prefs.fields?.filter(
			( field, index, all ) =>
				supportedFields.indexOf( field ) !== -1 &&
				all.indexOf( field ) === index
		);
		const density = prefs.layout?.density;

		return {
			...( fields?.length ? { fields } : {} ),
			...( prefs.perPage &&
			supportedPerPage.indexOf( prefs.perPage ) !== -1
				? { perPage: prefs.perPage }
				: {} ),
			...( density && supportedDensities.indexOf( density ) !== -1
				? { layout: { density } }
				: {} ),
		};
	};

	const load = (): DisplayPrefs => {
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

			const prefs: DisplayPrefs = {};
			if (
				Array.isArray( parsed.prefs.fields ) &&
				parsed.prefs.fields.every(
					( field: unknown ) => typeof field === 'string'
				)
			) {
				prefs.fields = parsed.prefs.fields;
			}
			if ( typeof parsed.prefs.perPage === 'number' ) {
				prefs.perPage = parsed.prefs.perPage;
			}
			if (
				parsed.prefs.layout &&
				typeof parsed.prefs.layout === 'object'
			) {
				prefs.layout = parsed.prefs.layout;
			}

			return sanitize( prefs );
		} catch {
			return {};
		}
	};

	const save = ( prefs: DisplayPrefs ): void => {
		try {
			window.localStorage.setItem(
				storageKey,
				JSON.stringify( { version, prefs: sanitize( prefs ) } )
			);
		} catch {
			// Storage is optional. The page remains usable without it.
		}
	};

	return {
		load,
		save,
		fromView: ( view ) =>
			sanitize( {
				fields: view.fields,
				perPage: view.perPage,
				layout: view.layout,
			} ),
	};
}
