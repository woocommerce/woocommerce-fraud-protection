import { useEffect } from '@wordpress/element';

const warnAboutUnsavedChanges = ( event: BeforeUnloadEvent ) => {
	event.preventDefault();
	event.returnValue = '';
};

export function useUnsavedChangesWarning( shouldWarn: boolean ) {
	// TODO: Block WooCommerce client-side navigation when this page adds client-side routes.
	useEffect( () => {
		if ( shouldWarn ) {
			window.addEventListener( 'beforeunload', warnAboutUnsavedChanges );
		}

		return () => {
			window.removeEventListener(
				'beforeunload',
				warnAboutUnsavedChanges
			);
		};
	}, [ shouldWarn ] );
}
