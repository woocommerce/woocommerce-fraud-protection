import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';

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
};

type State = {
	data: Rule[];
	totalItems: number;
	totalPages: number;
	isLoading: boolean;
	error: string | null;
	query: RulesQuery;
};

type Action =
	| { type: 'SET_QUERY'; query: RulesQuery }
	| { type: 'SET_LOADING'; isLoading: boolean }
	| {
			type: 'RECEIVE_RULES';
			response: { data: Rule[]; totalItems: number; totalPages: number };
	  }
	| { type: 'SET_ERROR'; error: string | null };

const DEFAULT_QUERY: RulesQuery = { page: 1, perPage: 20 };
const DEFAULT_STATE: State = {
	data: [],
	totalItems: 0,
	totalPages: 0,
	isLoading: false,
	error: null,
	query: DEFAULT_QUERY,
};

const QUERY_KEYS: Array< keyof RulesQuery > = [
	'page',
	'perPage',
	'action',
	'type',
	'value',
	'from',
	'to',
];

const areQueriesEqual = ( first: RulesQuery, second: RulesQuery ): boolean =>
	QUERY_KEYS.every( ( key ) => first[ key ] === second[ key ] );

const getErrorMessage = ( error: unknown ): string | null => {
	if (
		typeof error === 'object' &&
		error !== null &&
		'message' in error &&
		typeof error.message === 'string'
	) {
		return error.message;
	}
	return null;
};

const reducer = ( state = DEFAULT_STATE, action: Action ): State => {
	switch ( action.type ) {
		case 'SET_QUERY':
			return { ...state, query: action.query, error: null };
		case 'SET_LOADING':
			return { ...state, isLoading: action.isLoading };
		case 'RECEIVE_RULES':
			return {
				...state,
				...action.response,
				isLoading: false,
				error: null,
			};
		case 'SET_ERROR':
			return { ...state, isLoading: false, error: action.error };
		default:
			return state;
	}
};

const actions = {
	setQuery( query: RulesQuery ): Action {
		return { type: 'SET_QUERY', query };
	},
	setLoading( isLoading: boolean ): Action {
		return { type: 'SET_LOADING', isLoading };
	},
	receiveRules( response: {
		data: Rule[];
		totalItems: number;
		totalPages: number;
	} ): Action {
		return { type: 'RECEIVE_RULES', response };
	},
	setError( error: string | null ): Action {
		return { type: 'SET_ERROR', error };
	},
	requestRules:
		( query: RulesQuery ) =>
		async ( {
			dispatch,
			select,
		}: {
			dispatch: typeof actions;
			select: { getQuery: () => RulesQuery };
		} ) => {
			dispatch.setQuery( query );
			dispatch.setLoading( true );
			const params = new URLSearchParams();
			params.set( 'page', String( query.page ) );
			params.set( 'per_page', String( query.perPage ) );
			( [ 'action', 'type', 'value', 'from', 'to' ] as const ).forEach(
				( key ) => {
					if ( query[ key ] ) {
						params.set( key, query[ key ] as string );
					}
				}
			);
			try {
				const response = await apiFetch< {
					data: Rule[];
					totalItems: number;
					totalPages: number;
				} >( {
					path: `/wc-fraud-protection/v1/rules?${ params.toString() }`,
				} );
				if ( ! areQueriesEqual( select.getQuery(), query ) ) {
					return null;
				}
				dispatch.receiveRules( response );
				return response;
			} catch ( error ) {
				if ( ! areQueriesEqual( select.getQuery(), query ) ) {
					return null;
				}
				dispatch.setError( getErrorMessage( error ) );
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
