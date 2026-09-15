import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

import type { PaymentMethodOption } from './types';

// Loads the provider (payment method) options for the list's provider filter.
// These are fetched on demand when the list route mounts, rather than injected
// on every settings-page load, so the query that scans retained checkout
// attempts only runs when the list actually needs the options. A failure simply
// leaves the filter with no options; the list still loads.

const PATH = '/wc-fraud-protection/v1/sessions/payment-methods';

export function usePaymentMethodOptions(): PaymentMethodOption[] {
	const [ options, setOptions ] = useState< PaymentMethodOption[] >( [] );

	useEffect( () => {
		let active = true;

		( apiFetch( { path: PATH } ) as Promise< PaymentMethodOption[] > )
			.then( ( result ) => {
				if ( active && Array.isArray( result ) ) {
					setOptions( result );
				}
			} )
			.catch( () => {
				// Leave the filter with no options on failure.
			} );

		return () => {
			active = false;
		};
	}, [] );

	return options;
}
