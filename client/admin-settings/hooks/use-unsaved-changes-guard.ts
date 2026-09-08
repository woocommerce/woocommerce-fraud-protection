import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getHistory } from '@woocommerce/navigation';

const confirmMessage = __(
	'Changes you made may not be saved.',
	'woocommerce-fraud-protection'
);

export function useUnsavedChangesGuard(
	hasUnsavedChanges: boolean,
	discardChanges: () => void
) {
	const history = getHistory();

	useEffect( () => {
		if ( ! hasUnsavedChanges ) {
			return;
		}

		const unblock = history.block( ( transition ) => {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( confirmMessage ) ) {
				return;
			}

			discardChanges();
			unblock();
			transition.retry();
		} );

		return unblock;
	}, [ discardChanges, hasUnsavedChanges, history ] );
}
