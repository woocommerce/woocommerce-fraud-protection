import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';

// Remembers, per admin user, that a notice was dismissed — the way WooCommerce
// Admin stores per-user preferences: in the `woocommerce_meta` REST field on the
// current user, which WordPress persists to the `woocommerce_admin_<preference>`
// user meta. The preference key must be allow-listed on the server through the
// `woocommerce_admin_get_user_data_fields` filter, or the write is dropped.
//
// The initial value is injected at page load (so a previous dismissal is known
// without an extra request and the notice does not flash), and `dismiss()` both
// hides the notice and persists the choice. A failed write is ignored: the
// dismissal is a convenience.

const UPDATE_USER_PATH = '/wp/v2/users/me';

export function useNoticeDismissed(
	preference: string,
	initialDismissed: boolean
) {
	const [ isDismissed, setIsDismissed ] = useState( initialDismissed );

	const dismiss = () => {
		setIsDismissed( true );
		apiFetch( {
			path: UPDATE_USER_PATH,
			method: 'PUT',
			data: { woocommerce_meta: { [ preference ]: 'yes' } },
		} ).catch( () => {} );
	};

	return { isDismissed, dismiss };
}
