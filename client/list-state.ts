import type { View } from '@wordpress/dataviews';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { getHistory } from '@woocommerce/navigation';
import { useSearchParams } from 'react-router-dom';

import { getFraudProtectionRoute } from './admin-settings/navigation';
import type { DisplayPrefs, PreferenceStore } from './persisted-state';

type ViewFilter = NonNullable< View[ 'filters' ] >[ number ];
type FilterOption = { value: string; label: string };

type ScalarFilterDefinition = {
	kind: 'scalar';
	field: string;
	operator: ViewFilter[ 'operator' ];
	param: string;
	validate?: ( value: string ) => boolean;
};

type EnumFilterDefinition = {
	kind: 'enum';
	field: string;
	operator: ViewFilter[ 'operator' ];
	param: string;
	values: FilterOption[];
};

type ListFilterDefinition = {
	kind: 'list';
	field: string;
	operator: ViewFilter[ 'operator' ];
	param: string;
	values?: FilterOption[];
};

type RangeFilterDefinition = {
	kind: 'range';
	field: string;
	operator: ViewFilter[ 'operator' ];
	params: readonly [ string, string ];
	validate: ( value: string ) => boolean;
};

export type UrlFilterDefinition =
	| ScalarFilterDefinition
	| EnumFilterDefinition
	| ListFilterDefinition
	| RangeFilterDefinition;

export const defineScalarFilter = (
	definition: Omit< ScalarFilterDefinition, 'kind' >
): ScalarFilterDefinition => ( { kind: 'scalar', ...definition } );

export const defineEnumFilter = (
	definition: Omit< EnumFilterDefinition, 'kind' >
): EnumFilterDefinition => ( { kind: 'enum', ...definition } );

export const defineListFilter = (
	definition: Omit< ListFilterDefinition, 'kind' >
): ListFilterDefinition => ( { kind: 'list', ...definition } );

export const defineRangeFilter = (
	definition: Omit< RangeFilterDefinition, 'kind' >
): RangeFilterDefinition => ( { kind: 'range', ...definition } );

type QueryPart = 'search' | 'filters' | 'page' | 'sort' | 'state';

type EnumStateDefinition< State extends string > = {
	param: string;
	defaultValue: State;
	values: readonly State[];
};

type ListUrlCodecConfig< State extends string > = {
	path: string;
	defaultView: View;
	filters: readonly UrlFilterDefinition[];
	supportedSortFields?: readonly string[];
	search?: boolean;
	state?: EnumStateDefinition< State >;
	queryOrder: readonly QueryPart[];
};

export type DecodedListState< State extends string > = {
	view: View;
	state: State | undefined;
};

export type ListUrlCodec< State extends string > = {
	decode: (
		params: URLSearchParams,
		prefs: DisplayPrefs
	) => DecodedListState< State >;
	encode: ( view: View, state?: State ) => URLSearchParams;
	serialize: ( view: View, state?: State ) => string;
	serializeUrl: ( params: URLSearchParams ) => string;
	write: ( view: View, state?: State, replace?: boolean ) => void;
	normalizeState: ( value: string ) => State | undefined;
};

function getFilter( view: View, field: string ): ViewFilter | undefined {
	return ( view.filters ?? [] ).find( ( filter ) => filter.field === field );
}

function scalarValue( value: unknown ): string {
	return Array.isArray( value )
		? String( value[ 0 ] ?? '' )
		: String( value ?? '' );
}

function allowedValues( definition: {
	values?: readonly FilterOption[];
} ): string[] | undefined {
	return definition.values?.map( ( option ) => option.value );
}

function isAllowed( value: string, values?: readonly string[] ): boolean {
	return ! values || values.indexOf( value ) !== -1;
}

function readFilter(
	definition: UrlFilterDefinition,
	params: URLSearchParams
): ViewFilter | null {
	if ( definition.kind === 'range' ) {
		const from = params.get( definition.params[ 0 ] ) ?? '';
		const to = params.get( definition.params[ 1 ] ) ?? '';
		const validFrom = definition.validate( from );
		const validTo = definition.validate( to );
		return validFrom || validTo
			? {
					field: definition.field,
					operator: definition.operator,
					value: [ validFrom ? from : '', validTo ? to : '' ],
			  }
			: null;
	}

	const raw = params.get( definition.param ) ?? '';
	if ( definition.kind === 'list' ) {
		const values = raw
			.split( ',' )
			.filter( Boolean )
			.filter( ( value ) =>
				isAllowed( value, allowedValues( definition ) )
			);
		return values.length
			? {
					field: definition.field,
					operator: definition.operator,
					value: values,
			  }
			: null;
	}

	const valid =
		raw &&
		( definition.kind === 'enum'
			? isAllowed( raw, allowedValues( definition ) )
			: ! definition.validate || definition.validate( raw ) );
	return valid
		? {
				field: definition.field,
				operator: definition.operator,
				value: raw,
		  }
		: null;
}

function setNonDefaultParam(
	params: URLSearchParams,
	key: string,
	value: string,
	defaultValue = ''
): void {
	if ( value && value !== defaultValue ) {
		params.set( key, value );
	}
}

function writeFilter(
	definition: UrlFilterDefinition,
	view: View,
	params: URLSearchParams
): void {
	const filter = getFilter( view, definition.field );
	if ( ! filter ) {
		return;
	}

	if ( definition.kind === 'range' ) {
		const values = Array.isArray( filter.value ) ? filter.value : [];
		values.slice( 0, 2 ).forEach( ( value, index ) => {
			const scalar = String( value ?? '' );
			if ( definition.validate( scalar ) ) {
				params.set( definition.params[ index ], scalar );
			}
		} );
		return;
	}

	if ( definition.kind === 'list' ) {
		const values = (
			Array.isArray( filter.value ) ? filter.value : [ filter.value ]
		)
			.filter(
				( value ) =>
					value !== undefined && value !== null && value !== ''
			)
			.map( String )
			.filter( ( value ) =>
				isAllowed( value, allowedValues( definition ) )
			);
		setNonDefaultParam( params, definition.param, values.join( ',' ) );
		return;
	}

	const value = scalarValue( filter.value );
	const valid =
		definition.kind === 'enum'
			? isAllowed( value, allowedValues( definition ) )
			: ! definition.validate || definition.validate( value );
	setNonDefaultParam( params, definition.param, valid ? value : '' );
}

export function parsePositivePage( value: string | null ): number {
	if ( ! value || ! /^[1-9]\d*$/.test( value ) ) {
		return 1;
	}
	const page = Number( value );
	return Number.isSafeInteger( page ) ? page : 1;
}

export function getCorrectedPage(
	currentPage: number,
	totalPages: number
): number | null {
	const target = totalPages >= 1 ? Math.min( currentPage, totalPages ) : 1;
	return target === currentPage ? null : target;
}

export function createListUrlCodec< State extends string = string >( {
	path,
	defaultView,
	filters,
	supportedSortFields,
	search = false,
	state: stateDefinition,
	queryOrder,
}: ListUrlCodecConfig< State > ): ListUrlCodec< State > {
	const normalizeState = ( value: string ): State | undefined => {
		if ( ! stateDefinition ) {
			return undefined;
		}
		return stateDefinition.values.indexOf( value as State ) !== -1
			? ( value as State )
			: stateDefinition.defaultValue;
	};

	const decode = (
		params: URLSearchParams,
		prefs: DisplayPrefs
	): DecodedListState< State > => {
		const orderby = params.get( 'orderby' );
		const order = params.get( 'order' );
		const layout =
			defaultView.layout || prefs.layout
				? { ...defaultView.layout, ...prefs.layout }
				: undefined;

		return {
			view: {
				...defaultView,
				page: parsePositivePage( params.get( 'paged' ) ),
				perPage: prefs.perPage ?? defaultView.perPage,
				sort: {
					field:
						orderby && isAllowed( orderby, supportedSortFields )
							? orderby
							: defaultView.sort?.field ?? '',
					direction:
						order === 'asc' || order === 'desc'
							? order
							: defaultView.sort?.direction ?? 'desc',
				},
				...( search ? { search: params.get( 'search' ) ?? '' } : {} ),
				filters: filters
					.map( ( definition ) => readFilter( definition, params ) )
					.filter( ( filter ): filter is ViewFilter =>
						Boolean( filter )
					),
				fields: prefs.fields ?? defaultView.fields,
				...( layout ? { layout } : {} ),
			},
			state: stateDefinition
				? normalizeState( params.get( stateDefinition.param ) ?? '' )
				: undefined,
		};
	};

	const encode = ( view: View, currentState?: State ): URLSearchParams => {
		const params = new URLSearchParams();
		queryOrder.forEach( ( part ) => {
			if ( part === 'search' && search ) {
				setNonDefaultParam( params, 'search', view.search ?? '' );
			}
			if ( part === 'filters' ) {
				filters.forEach( ( definition ) =>
					writeFilter( definition, view, params )
				);
			}
			if ( part === 'page' ) {
				setNonDefaultParam(
					params,
					'paged',
					String( view.page ?? 1 ),
					'1'
				);
			}
			if ( part === 'sort' ) {
				setNonDefaultParam(
					params,
					'orderby',
					view.sort?.field ?? defaultView.sort?.field ?? '',
					defaultView.sort?.field
				);
				setNonDefaultParam(
					params,
					'order',
					view.sort?.direction ?? defaultView.sort?.direction ?? '',
					defaultView.sort?.direction
				);
			}
			if ( part === 'state' && stateDefinition ) {
				setNonDefaultParam(
					params,
					stateDefinition.param,
					currentState ?? stateDefinition.defaultValue,
					stateDefinition.defaultValue
				);
			}
		} );
		return params;
	};

	const serialize = ( view: View, currentState?: State ): string => {
		const params = encode( view, currentState );
		params.sort();
		return params.toString();
	};

	return {
		decode,
		encode,
		serialize,
		serializeUrl: ( params ) => {
			const decoded = decode( params, {} );
			return serialize( decoded.view, decoded.state );
		},
		write: ( view, currentState, replace = false ) => {
			const query: Record< string, string > = {};
			encode( view, currentState ).forEach( ( value, key ) => {
				query[ key ] = value;
			} );
			const adminPath = getFraudProtectionRoute( path, query );
			const history = getHistory();
			if ( replace ) {
				history.replace( adminPath );
			} else {
				history.push( adminPath );
			}
		},
		normalizeState,
	};
}

type ListPageResult = {
	totalPages: number;
	isLoading: boolean;
	error: unknown;
};

type ListStatePolicies = {
	resetPageOnFilterChange?: boolean;
	replaceOnSearchOnly?: boolean;
	resetPageOnStateChange?: boolean;
};

type UseListStateOptions<
	State extends string,
	Result extends ListPageResult,
> = {
	codec: ListUrlCodec< State >;
	preferenceStore: PreferenceStore;
	policies?: ListStatePolicies;
	useData: ( view: View, state: State | undefined ) => Result;
};

export function useListState<
	State extends string,
	Result extends ListPageResult,
>( {
	codec,
	preferenceStore,
	policies = {},
	useData,
}: UseListStateOptions< State, Result > ) {
	const pageRef = useRef< HTMLDivElement >( null );
	const [ searchParams ] = useSearchParams();
	const initial = useRef< DecodedListState< State > | null >( null );
	if ( ! initial.current ) {
		initial.current = codec.decode( searchParams, preferenceStore.load() );
	}
	const [ view, setView ] = useState< View >( initial.current.view );
	const [ state, setState ] = useState< State | undefined >(
		initial.current.state
	);
	const result = useData( view, state );

	const onChangeView = useCallback(
		( changedView: View ) => {
			const filtersChanged =
				JSON.stringify( changedView.filters ?? [] ) !==
				JSON.stringify( view.filters ?? [] );
			const nextView =
				policies.resetPageOnFilterChange && filtersChanged
					? { ...changedView, page: 1 }
					: changedView;
			const searchOnly =
				Boolean( policies.replaceOnSearchOnly ) &&
				codec.serialize( { ...nextView, search: '' }, state ) ===
					codec.serialize( { ...view, search: '' }, state );

			setView( nextView );
			preferenceStore.save( preferenceStore.fromView( nextView ) );
			if (
				codec.serialize( nextView, state ) !==
				codec.serialize( view, state )
			) {
				codec.write( nextView, state, searchOnly );
			}
		},
		[ codec, policies, preferenceStore, state, view ]
	);

	const onChangeState = useCallback(
		( value: string ) => {
			const nextState = codec.normalizeState( value );
			const nextView = policies.resetPageOnStateChange
				? { ...view, page: 1 }
				: view;
			setState( nextState );
			setView( nextView );
			codec.write( nextView, nextState );
		},
		[ codec, policies.resetPageOnStateChange, view ]
	);

	useEffect( () => {
		if (
			codec.serializeUrl( searchParams ) ===
			codec.serialize( view, state )
		) {
			return;
		}
		const decoded = codec.decode(
			searchParams,
			preferenceStore.fromView( view )
		);
		setView( decoded.view );
		setState( decoded.state );
	}, [ codec, preferenceStore, searchParams, state, view ] );

	useEffect( () => {
		const form = pageRef.current?.closest( 'form' );
		if ( ! form ) {
			return;
		}
		const preventSubmit = ( event: Event ) => event.preventDefault();
		form.addEventListener( 'submit', preventSubmit );
		return () => form.removeEventListener( 'submit', preventSubmit );
	}, [] );

	useEffect( () => {
		if ( result.isLoading || result.error ) {
			return;
		}
		const target = getCorrectedPage( view.page ?? 1, result.totalPages );
		if ( target !== null ) {
			const nextView = { ...view, page: target };
			setView( nextView );
			codec.write( nextView, state, true );
		}
	}, [
		codec,
		result.error,
		result.isLoading,
		result.totalPages,
		state,
		view,
	] );

	return { pageRef, view, state, onChangeView, onChangeState, result };
}
