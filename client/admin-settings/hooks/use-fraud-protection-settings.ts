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
			savedSettings: store.getSavedSettings(),
			settings,
		};
	}, [] );
	const { discardChanges, saveSettings, setAutomaticProtection } =
		useDispatch( settingsStore );
	const { createSuccessNotice } = useDispatch( noticesStore );

	const save = async () => {
		const turnsOnAutomaticBlocking =
			state.settings?.automatic_protection === true &&
			state.savedSettings?.automatic_protection !== true;
		const didSave = await saveSettings();

		if ( didSave ) {
			createSuccessNotice(
				turnsOnAutomaticBlocking
					? __(
							'Automatic blocking is active. Flagged payment attempts will now be blocked.',
							'woocommerce-fraud-protection'
					  )
					: __( 'Settings saved.', 'woocommerce-fraud-protection' ),
				{ type: 'snackbar' }
			);
		}

		return didSave;
	};

	return {
		...state,
		discardChanges,
		save,
		setAutomaticProtection,
	};
}
