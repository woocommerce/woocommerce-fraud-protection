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

export type CreateRuleRequest = {
	action: Rule[ 'action' ];
	type: Rule[ 'type' ];
	value: string;
	recorded_attempt_id?: number;
	origin?: 'rules' | 'checkout_attempts' | 'api';
};

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

const INVALID_RESPONSE_MESSAGE = __(
	'Could not get a valid response from the server.',
	'woocommerce-fraud-protection'
);

const isRule = ( value: unknown ): value is Rule => {
	if ( typeof value !== 'object' || value === null ) {
		return false;
	}

	const rule = value as Record< string, unknown >;
	return (
		Number.isInteger( rule.id ) &&
		( rule.action === 'allow' || rule.action === 'block' ) &&
		typeof rule.value === 'string' &&
		( rule.type === 'email' || rule.type === 'ip' ) &&
		typeof rule.created_at === 'string'
	);
};

const isRulesResponse = (
	value: unknown
): value is { data: Rule[]; totalItems: number; totalPages: number } => {
	if ( typeof value !== 'object' || value === null ) {
		return false;
	}

	const response = value as Record< string, unknown >;
	return (
		Array.isArray( response.data ) &&
		response.data.every( isRule ) &&
		Number.isInteger( response.totalItems ) &&
		( response.totalItems as number ) >= 0 &&
		Number.isInteger( response.totalPages ) &&
		( response.totalPages as number ) >= 0
	);
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
	return INVALID_RESPONSE_MESSAGE;
};

const reducer = ( state = DEFAULT_STATE, action: Action ): State => {
	switch ( action.type ) {
		case 'START_REQUEST':
			return {
				...state,
				data: [],
				totalItems: 0,
				totalPages: 0,
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
				const response = await apiFetch< unknown >( {
					path: `/wc-fraud-protection/v1/rules?${ params.toString() }`,
				} );
				if ( ! isRulesResponse( response ) ) {
					throw new Error( INVALID_RESPONSE_MESSAGE );
				}
				dispatch.receiveRules( response, requestId );
				return response;
			} catch ( error ) {
				dispatch.setError( getErrorMessage( error ), requestId );
				return null;
			}
		},
	createRule:
		( request: CreateRuleRequest ) =>
		async ( {
			dispatch,
			select,
		}: {
			dispatch: typeof actions;
			select: { getQuery: () => RulesQuery };
		} ) => {
			const response = await apiFetch< Rule >( {
				path: '/wc-fraud-protection/v1/rules',
				method: 'POST',
				data: request,
			} );
			await dispatch.requestRules( select.getQuery() );
			return response;
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
