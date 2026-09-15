import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import type { View } from '@wordpress/dataviews';

import type { FinalStatus, Session } from './types';

const BASE_PATH = '/wc-fraud-protection/v1/sessions';

export type CheckoutAttemptsState = {
	sessions: Session[];
	totalItems: number;
	totalPages: number;
	isLoading: boolean;
	error: string | null;
};

function filterValues( view: View, field: string ): string[] {
	const filter = ( view.filters ?? [] ).find(
		( candidate ) => candidate.field === field
	);

	if ( ! filter || filter.value === undefined || filter.value === null ) {
		return [];
	}

	return Array.isArray( filter.value )
		? filter.value.map( String )
		: [ String( filter.value ) ];
}

export function buildListPath(
	view: View,
	finalStatus: FinalStatus | null
): string {
	const rules = filterValues( view, 'rules' )[ 0 ];

	return addQueryArgs( BASE_PATH, {
		page: view.page ?? 1,
		per_page: view.perPage ?? 20,
		orderby: view.sort?.field ?? 'recorded_at',
		order: view.sort?.direction ?? 'desc',
		search: view.search ?? '',
		outcome: filterValues( view, 'outcome' ),
		payment_method: filterValues( view, 'payment_method' ),
		...( finalStatus ? { final_status: finalStatus } : {} ),
		...( rules ? { rules } : {} ),
	} );
}

export function useCheckoutAttempts(
	view: View,
	finalStatus: FinalStatus | null
): CheckoutAttemptsState {
	const [ state, setState ] = useState< CheckoutAttemptsState >( {
		sessions: [],
		totalItems: 0,
		totalPages: 0,
		isLoading: true,
		error: null,
	} );

	const path = buildListPath( view, finalStatus );

	useEffect( () => {
		let active = true;

		setState( ( previous ) => ( {
			...previous,
			isLoading: true,
			error: null,
		} ) );

		( apiFetch( { path, parse: false } ) as Promise< Response > )
			.then( async ( response ) => {
				const sessions = ( await response.json() ) as Session[];

				if ( ! active ) {
					return;
				}

				setState( {
					sessions,
					totalItems: parseInt(
						response.headers?.get( 'X-WP-Total' ) ?? '0',
						10
					),
					totalPages: parseInt(
						response.headers?.get( 'X-WP-TotalPages' ) ?? '0',
						10
					),
					isLoading: false,
					error: null,
				} );
			} )
			.catch( ( error: unknown ) => {
				if ( ! active ) {
					return;
				}

				setState( {
					sessions: [],
					totalItems: 0,
					totalPages: 0,
					isLoading: false,
					error:
						error instanceof Error && error.message
							? error.message
							: __(
									'The checkout attempts could not be loaded.',
									'woocommerce-fraud-protection'
							  ),
				} );
			} );

		return () => {
			active = false;
		};
	}, [ path ] );

	return state;
}
