import apiFetch from '@wordpress/api-fetch';
import type { Option } from '@wordpress/dataviews';

import { SESSION_PAYMENT_METHODS_PATH } from '../rest-api';
import type { PaymentMethodOption } from './types';

export async function getPaymentMethodElements(): Promise< Option[] > {
	const methods = await apiFetch< PaymentMethodOption[] >( {
		path: SESSION_PAYMENT_METHODS_PATH,
	} );

	if ( ! Array.isArray( methods ) ) {
		return [];
	}

	return methods.map( ( method ) => ( {
		value: method.id,
		label: method.title || method.id,
	} ) );
}
