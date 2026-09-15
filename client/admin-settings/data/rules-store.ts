import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

export type Rule = {
	id: number;
	action: 'allow' | 'block';
	value: string;
	type: 'email' | 'ip';
	created_at: string;
	updated_at: string | null;
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

type RulesResponse = {
	data: Rule[];
	totalItems: number;
	totalPages: number;
};

type State = {
	lists: Record< string, RulesResponse >;
	rules: Record< number, Rule >;
};

type Action =
	| {
			type: 'RECEIVE_RULES';
			query: RulesQuery;
			response: RulesResponse;
	  }
	| { type: 'RECEIVE_RULE'; rule: Rule }
	| { type: 'REMOVE_RULE'; id: number };

export type CreateRuleRequest = {
	action: Rule[ 'action' ];
	type: Rule[ 'type' ];
	value: string;
	recorded_attempt_id?: number;
	origin?: 'rules' | 'checkout_attempts' | 'api';
};

export type UpdateRuleRequest = Pick<
	CreateRuleRequest,
	'action' | 'type' | 'value' | 'origin'
>;

const DEFAULT_STATE: State = {
	lists: {},
	rules: {},
};

const EMPTY_RULES_RESPONSE: RulesResponse = {
	data: [],
	totalItems: 0,
	totalPages: 0,
};

const INVALID_RESPONSE_MESSAGE = __(
	'Could not get a valid response from the server.',
	'woocommerce-fraud-protection'
);

const OPTIONAL_QUERY_KEYS = [
	'action',
	'type',
	'value',
	'from',
	'to',
	'orderby',
	'order',
] as const;

export const normalizeRulesQuery = (
	query: Partial< RulesQuery > = {}
): RulesQuery => {
	const normalized: RulesQuery = {
		page:
			Number.isInteger( query.page ) && Number( query.page ) > 0
				? Number( query.page )
				: 1,
		perPage:
			Number.isInteger( query.perPage ) && Number( query.perPage ) > 0
				? Number( query.perPage )
				: 20,
	};

	OPTIONAL_QUERY_KEYS.forEach( ( key ) => {
		const value = query[ key ];
		if ( value !== undefined && value !== '' ) {
			Object.assign( normalized, { [ key ]: value } );
		}
	} );

	return normalized;
};

const getQueryKey = ( query: RulesQuery ): string =>
	JSON.stringify( normalizeRulesQuery( query ) );

const getRulesPath = ( query: RulesQuery ): string => {
	const normalized = normalizeRulesQuery( query );
	const params = new URLSearchParams();
	params.set( 'page', String( normalized.page ) );
	params.set( 'per_page', String( normalized.perPage ) );
	OPTIONAL_QUERY_KEYS.forEach( ( key ) => {
		const value = normalized[ key ];
		if ( value !== undefined ) {
			params.set( key, value );
		}
	} );

	return `/wc-fraud-protection/v1/rules?${ params.toString() }`;
};

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
		typeof rule.created_at === 'string' &&
		( rule.updated_at === null || typeof rule.updated_at === 'string' )
	);
};

const parseTotalHeader = ( value: string | null ): number => {
	if ( value === null || ! /^(0|[1-9]\d*)$/.test( value ) ) {
		throw new Error( INVALID_RESPONSE_MESSAGE );
	}

	const total = Number( value );
	if ( ! Number.isSafeInteger( total ) ) {
		throw new Error( INVALID_RESPONSE_MESSAGE );
	}

	return total;
};

const parseRulesResponse = async (
	response: Response
): Promise< RulesResponse > => {
	let data: unknown;
	try {
		data = await response.json();
	} catch {
		throw new Error( INVALID_RESPONSE_MESSAGE );
	}
	if ( ! Array.isArray( data ) || ! data.every( isRule ) ) {
		throw new Error( INVALID_RESPONSE_MESSAGE );
	}

	return {
		data,
		totalItems: parseTotalHeader( response.headers.get( 'X-WP-Total' ) ),
		totalPages: parseTotalHeader(
			response.headers.get( 'X-WP-TotalPages' )
		),
	};
};

export const getRulesErrorMessage = ( error: unknown ): string => {
	if (
		typeof error === 'object' &&
		error !== null &&
		'message' in error &&
		typeof error.message === 'string' &&
		error.message.trim() !== ''
	) {
		return error.message;
	}
	return INVALID_RESPONSE_MESSAGE;
};

const reducer = ( state = DEFAULT_STATE, action: Action ): State => {
	switch ( action.type ) {
		case 'RECEIVE_RULES':
			return {
				...state,
				lists: {
					...state.lists,
					[ getQueryKey( action.query ) ]: action.response,
				},
			};
		case 'RECEIVE_RULE':
			return {
				...state,
				rules: { ...state.rules, [ action.rule.id ]: action.rule },
			};
		case 'REMOVE_RULE': {
			const rules = { ...state.rules };
			delete rules[ action.id ];
			return { ...state, rules };
		}
		default:
			return state;
	}
};

const actions = {
	receiveRules( query: RulesQuery, response: RulesResponse ): Action {
		return { type: 'RECEIVE_RULES', query, response };
	},
	receiveRule( rule: Rule ): Action {
		return { type: 'RECEIVE_RULE', rule };
	},
	removeRule( id: number ): Action {
		return { type: 'REMOVE_RULE', id };
	},
	createRule:
		( request: CreateRuleRequest ) =>
		async ( { dispatch }: StoreCallback ) => {
			const response = await apiFetch< unknown >( {
				path: '/wc-fraud-protection/v1/rules',
				method: 'POST',
				data: request,
			} );
			if ( ! isRule( response ) ) {
				throw new Error( INVALID_RESPONSE_MESSAGE );
			}

			dispatch.receiveRule( response );
			await dispatch.invalidateResolutionForStoreSelector( 'getRules' );
			return response;
		},
	updateRule:
		( id: number, request: UpdateRuleRequest ) =>
		async ( { dispatch }: StoreCallback ) => {
			const response = await apiFetch< unknown >( {
				path: `/wc-fraud-protection/v1/rules/${ id }`,
				method: 'PUT',
				data: request,
			} );
			if ( ! isRule( response ) ) {
				throw new Error( INVALID_RESPONSE_MESSAGE );
			}

			dispatch.receiveRule( response );
			await dispatch.invalidateResolutionForStoreSelector( 'getRules' );
			return response;
		},
	deleteRule:
		( id: number, origin: UpdateRuleRequest[ 'origin' ] = 'api' ) =>
		async ( { dispatch }: StoreCallback ) => {
			await apiFetch( {
				path: `/wc-fraud-protection/v1/rules/${ id }?origin=${ origin }`,
				method: 'DELETE',
			} );
			dispatch.removeRule( id );
			await dispatch.invalidateResolutionForStoreSelector( 'getRules' );
			await dispatch.invalidateResolution( 'getRule', [ id ] );
		},
};

type RulesSelector = {
	( state: State, query: RulesQuery ): Rule[];
	__unstableNormalizeArgs?: ( args: [ RulesQuery ] ) => [ RulesQuery ];
};

const getRules: RulesSelector = ( state, query ) =>
	state.lists[ getQueryKey( query ) ]?.data ?? EMPTY_RULES_RESPONSE.data;
getRules.__unstableNormalizeArgs = ( [ query ] ) => [
	normalizeRulesQuery( query ),
];

const selectors = {
	getRules,
	getTotalItems( state: State, query: RulesQuery ): number {
		return (
			state.lists[ getQueryKey( query ) ]?.totalItems ??
			EMPTY_RULES_RESPONSE.totalItems
		);
	},
	getTotalPages( state: State, query: RulesQuery ): number {
		return (
			state.lists[ getQueryKey( query ) ]?.totalPages ??
			EMPTY_RULES_RESPONSE.totalPages
		);
	},
	getRule( state: State, id: number ): Rule | undefined {
		return state.rules[ id ];
	},
};

type StoreActions = typeof actions & {
	invalidateResolution: (
		selectorName: 'getRules' | 'getRule',
		args: unknown[]
	) => Promise< void >;
	invalidateResolutionForStoreSelector: (
		selectorName: 'getRules' | 'getRule'
	) => Promise< void >;
};

type StoreCallback = {
	dispatch: StoreActions;
};

const resolvers = {
	getRules:
		( query: RulesQuery ) =>
		async ( { dispatch }: StoreCallback ) => {
			const response = await apiFetch( {
				path: getRulesPath( query ),
				parse: false,
			} );
			dispatch.receiveRules(
				query,
				await parseRulesResponse( response )
			);
		},
	getRule:
		( id: number ) =>
		async ( { dispatch }: StoreCallback ) => {
			const response = await apiFetch< unknown >( {
				path: `/wc-fraud-protection/v1/rules/${ id }`,
			} );
			if ( ! isRule( response ) ) {
				throw new Error( INVALID_RESPONSE_MESSAGE );
			}
			dispatch.receiveRule( response );
		},
};

export const rulesStore = createReduxStore( 'wc-fraud-protection/rules', {
	reducer,
	actions,
	selectors,
	resolvers,
} );

register( rulesStore );
