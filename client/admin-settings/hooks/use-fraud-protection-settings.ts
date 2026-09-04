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
			performance: store.getPerformance(),
			settings,
		};
	}, [] );
	const { saveSettings, setAutomaticProtection } =
		useDispatch( settingsStore );
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

	return {
		...state,
		save,
		setAutomaticProtection,
	};
}
