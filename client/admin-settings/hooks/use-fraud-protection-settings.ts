import { useDispatch, useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { store as noticesStore } from '@wordpress/notices';

import { settingsStore } from '../data/store';

export function useFraudProtectionSettings() {
	const state = useSelect( ( select ) => {
		const store = select( settingsStore );
		const settings = store.getSettings();

		return {
			error: store.getError(),
			isDirty: store.isDirty(),
			isLoading:
				store.isResolving( 'getSettings' ) ||
				! store.hasFinishedResolution( 'getSettings' ),
			isSaving: store.isSaving(),
			isOptingOut: store.isOptingOut(),
			performance: store.getPerformance(),
			settings,
		};
	}, [] );
	const {
		discardChanges,
		requestOptOut,
		saveSettings,
		setAutomaticProtection,
	} = useDispatch( settingsStore );
	const { createSuccessNotice } = useDispatch( noticesStore );

	const save = async () => {
		const didSave = await saveSettings();

		if ( didSave ) {
			createSuccessNotice(
				__( 'Settings saved.', 'woocommerce-fraud-protection' ),
				{ type: 'snackbar' }
			);
		}

		return didSave;
	};

	const optOut = async () => {
		const source =
			'inbox' ===
			new URLSearchParams( window.location.search ).get( 'source' )
				? 'inbox'
				: 'settings';
		const didOptOut = await requestOptOut( source );

		if ( didOptOut ) {
			createSuccessNotice(
				__(
					'Automatic blocking stays off.',
					'woocommerce-fraud-protection'
				),
				{ type: 'snackbar' }
			);
		}

		return didOptOut;
	};

	return {
		...state,
		discardChanges,
		optOut,
		save,
		setAutomaticProtection,
	};
}
