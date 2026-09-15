import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

export type Rule = {
	id: number;
	action: 'allow' | 'block';
	value: string;
	type: 'email' | 'ip';
	created_at: string;
};

export type RulesQuery = {
	page: number;
	perPage: number;
	action?: string;
	type?: string;
	value?: string;
	from?: string;
	to?: string;
	orderby?: string;
	order?: 'asc' | 'desc';
};

type State = {
	data: Rule[];
	totalItems: number;
	totalPages: number;
	isLoading: boolean;
	error: string | null;
	query: RulesQuery;
	requestId: number;
};

type Action =
	| { type: 'START_REQUEST'; query: RulesQuery; requestId: number }
	| {
			type: 'RECEIVE_RULES';
			response: { data: Rule[]; totalItems: number; totalPages: number };
			requestId: number;
	  }
	| { type: 'SET_ERROR'; error: string | null; requestId: number };

const DEFAULT_QUERY: RulesQuery = { page: 1, perPage: 20 };
const DEFAULT_STATE: State = {
	data: [],
	totalItems: 0,
	totalPages: 0,
	isLoading: true,
	error: null,
	query: DEFAULT_QUERY,
	requestId: 0,
};

const getErrorMessage = ( error: unknown ): string => {
	if (
		typeof error === 'object' &&
		error !== null &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error.message;
	}
	return __(
		'Could not get a valid response from the server.',
		'woocommerce-fraud-protection'
	);
};

const reducer = ( state = DEFAULT_STATE, action: Action ): State => {
	switch ( action.type ) {
		case 'START_REQUEST':
			return {
				...state,
				query: action.query,
				requestId: action.requestId,
				isLoading: true,
				error: null,
			};
		case 'RECEIVE_RULES':
			if ( action.requestId !== state.requestId ) {
				return state;
			}
			return {
				...state,
				...action.response,
				isLoading: false,
				error: null,
			};
		case 'SET_ERROR':
			if ( action.requestId !== state.requestId ) {
				return state;
			}
			return { ...state, isLoading: false, error: action.error };
		default:
			return state;
	}
};

const actions = {
	startRequest( query: RulesQuery, requestId: number ): Action {
		return { type: 'START_REQUEST', query, requestId };
	},
	receiveRules(
		response: {
			data: Rule[];
			totalItems: number;
			totalPages: number;
		},
		requestId: number
	): Action {
		return { type: 'RECEIVE_RULES', response, requestId };
	},
	setError( error: string | null, requestId: number ): Action {
		return { type: 'SET_ERROR', error, requestId };
	},
	requestRules:
		( query: RulesQuery ) =>
		async ( {
			dispatch,
			select,
		}: {
			dispatch: typeof actions;
			select: { getRequestId: () => number };
		} ) => {
			const requestId = select.getRequestId() + 1;
			dispatch.startRequest( query, requestId );
			const params = new URLSearchParams();
			const requestKeys = [
				'action',
				'type',
				'value',
				'from',
				'to',
				'orderby',
				'order',
			] as const;
			params.set( 'page', String( query.page ) );
			params.set( 'per_page', String( query.perPage ) );
			requestKeys.forEach( ( key ) => {
				if ( query[ key ] ) {
					params.set( key, query[ key ] as string );
				}
			} );
			try {
				const response = await apiFetch< {
					data: Rule[];
					totalItems: number;
					totalPages: number;
				} >( {
					path: `/wc-fraud-protection/v1/rules?${ params.toString() }`,
				} );
				dispatch.receiveRules( response, requestId );
				return response;
			} catch ( error ) {
				dispatch.setError( getErrorMessage( error ), requestId );
				return null;
			}
		},
};

const selectors = {
	getRules( state: State ): Rule[] {
		return state.data;
	},
	getTotalItems( state: State ): number {
		return state.totalItems;
	},
	getTotalPages( state: State ): number {
		return state.totalPages;
	},
	getQuery( state: State ): RulesQuery {
		return state.query;
	},
	getRequestId( state: State ): number {
		return state.requestId;
	},
	isLoading( state: State ): boolean {
		return state.isLoading;
	},
	getError( state: State ): string | null {
		return state.error;
	},
};

export const rulesStore = createReduxStore( 'wc-fraud-protection/rules', {
	reducer,
	actions,
	selectors,
} );

register( rulesStore );
