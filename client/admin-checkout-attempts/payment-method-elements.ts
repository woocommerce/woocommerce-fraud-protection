import apiFetch from '@wordpress/api-fetch';
import type { Option } from '@wordpress/dataviews';

import type { PaymentMethodOption } from './types';

const PATH = '/wc-fraud-protection/v1/sessions/payment-methods';

export async function getPaymentMethodElements(): Promise< Option[] > {
	const methods = await apiFetch< PaymentMethodOption[] >( { path: PATH } );

	if ( ! Array.isArray( methods ) ) {
		return [];
	}

	return methods.map( ( method ) => ( {
		value: method.id,
		label: method.title || method.id,
	} ) );
}
