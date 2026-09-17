import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	EmptyState,
	Icon,
	Notice,
	Stack,
	Tabs,
	Text,
	VisuallyHidden,
} from '@wordpress/ui';
import { notAllowed, published } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';
import type { Action, Field, View } from '@wordpress/dataviews';
import { getHistory } from '@woocommerce/navigation';
import { Link, useSearchParams } from 'react-router-dom';

import type { Rule, RulesQuery } from './data/rules-store';
import { useRules } from './hooks/use-rules';
import { useRuleFormDrawer } from './hooks/use-rule-form-drawer';
import { getFraudProtectionRoute } from './navigation';
import { formatRuleDate, getUtcDateFilterBound } from './rule-date';
import { RuleFormDrawer } from './components/rule-form-drawer';
import { RuleDeleteDialog } from './components/rule-delete-dialog';
import {
	loadPrefs as loadStoredPrefs,
	savePrefs as saveStoredPrefs,
	type DisplayPrefs,
} from '../persisted-state';

const rootSettingsHref = getFraudProtectionRoute( '/' );
const ruleActions = [
	{ value: 'allow', label: __( 'Allow', 'woocommerce-fraud-protection' ) },
	{ value: 'block', label: __( 'Block', 'woocommerce-fraud-protection' ) },
];
const ruleTypes = [
	{ value: 'email', label: __( 'Email', 'woocommerce-fraud-protection' ) },
	{ value: 'ip', label: __( 'IP', 'woocommerce-fraud-protection' ) },
];
const DEFAULT_SORT_FIELD = 'created_at';
const DEFAULT_SORT_DIRECTION = 'desc';
const DEFAULT_PER_PAGE = 20;
const DEFAULT_FIELDS = [ 'action', 'value', 'type', 'created_at' ];
const SUPPORTED_SORT_FIELDS = [ 'action', 'value', 'type', 'created_at' ];
const SUPPORTED_PER_PAGE = [ 20, 50, 100 ];
const SUPPORTED_DENSITIES = [ 'compact', 'balanced', 'comfortable' ];
const STORAGE_KEY = 'wc-fraud-protection-rules-prefs';
const STORAGE_VERSION = 1;
const COLUMN_STYLES = {
	action: { width: '25%' },
	value: { width: '25%' },
	type: { width: '25%' },
	created_at: { width: '25%' },
};

const fields: Field< Rule >[] = [
	{
		id: 'action',
		label: __( 'Action', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: ruleActions,
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) => (
			<Stack
				className={ `wc-fraud-protection-rules__action wc-fraud-protection-rules__action--${ item.action }` }
				direction="row"
				align="center"
				gap="xs"
				render={ <span /> }
			>
				<Icon
					className="wc-fraud-protection-rules__action-icon"
					icon={ item.action === 'allow' ? published : notAllowed }
					aria-hidden="true"
					size={ 18 }
				/>
				{ item.action === 'allow'
					? __( 'Allow', 'woocommerce-fraud-protection' )
					: __( 'Block', 'woocommerce-fraud-protection' ) }
			</Stack>
		),
	},
	{
		id: 'value',
		label: __( 'Value', 'woocommerce-fraud-protection' ),
		type: 'text',
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) => (
			<Text
				className="wc-fraud-protection-rules__value"
				variant="body-md"
			>
				{ item.value }
			</Text>
		),
	},
	{
		id: 'type',
		label: __( 'Rule type', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: ruleTypes,
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) =>
			item.type === 'email'
				? __( 'Email', 'woocommerce-fraud-protection' )
				: __( 'IP', 'woocommerce-fraud-protection' ),
	},
	{
		id: 'created_at',
		label: __( 'Created', 'woocommerce-fraud-protection' ),
		header: __( 'Created', 'woocommerce-fraud-protection' ),
		type: 'date',
		filterBy: { operators: [ 'between' ] },
		render: ( { item } ) => formatRuleDate( item.created_at ),
	},
];

function sanitizePrefs( prefs: DisplayPrefs ): DisplayPrefs {
	const storedFields = prefs.fields?.filter(
		( field, index, all ) =>
			DEFAULT_FIELDS.indexOf( field ) !== -1 &&
			all.indexOf( field ) === index
	);
	const density = prefs.layout?.density;

	return {
		...( storedFields?.length ? { fields: storedFields } : {} ),
		...( prefs.perPage && SUPPORTED_PER_PAGE.indexOf( prefs.perPage ) !== -1
			? { perPage: prefs.perPage }
			: {} ),
		...( density && SUPPORTED_DENSITIES.indexOf( density ) !== -1
			? { layout: { density } }
			: {} ),
	};
}

function loadPrefs(): DisplayPrefs {
	return sanitizePrefs( loadStoredPrefs( STORAGE_KEY, STORAGE_VERSION ) );
}

function savePrefs( prefs: DisplayPrefs ): void {
	saveStoredPrefs( STORAGE_KEY, STORAGE_VERSION, sanitizePrefs( prefs ) );
}

function getFilterValue( view: View, field: string ): unknown {
	return ( view.filters ?? [] ).find( ( filter ) => filter.field === field )
		?.value;
}

function getScalarFilterValue( view: View, field: string ): string {
	const value = getFilterValue( view, field );
	return Array.isArray( value )
		? String( value[ 0 ] ?? '' )
		: String( value ?? '' );
}

function isValidLocalDate( value: string | null ): value is string {
	return Boolean(
		value &&
			/^\d{4}-\d{2}-\d{2}$/.test( value ) &&
			getUtcDateFilterBound( value, false )
	);
}

function parsePage( value: string | null ): number {
	if ( ! value || ! /^[1-9]\d*$/.test( value ) ) {
		return 1;
	}
	const page = Number( value );
	return Number.isSafeInteger( page ) ? page : 1;
}

function viewFromParams( params: URLSearchParams, prefs: DisplayPrefs ): View {
	const filters: NonNullable< View[ 'filters' ] > = [];
	const action = params.get( 'action' );
	if ( action === 'allow' || action === 'block' ) {
		filters.push( { field: 'action', operator: 'is', value: action } );
	}
	const type = params.get( 'type' );
	if ( type === 'email' || type === 'ip' ) {
		filters.push( { field: 'type', operator: 'is', value: type } );
	}
	const value = params.get( 'value' );
	if ( value ) {
		filters.push( { field: 'value', operator: 'is', value } );
	}
	const from = params.get( 'created_from' );
	const to = params.get( 'created_to' );
	if ( isValidLocalDate( from ) || isValidLocalDate( to ) ) {
		filters.push( {
			field: 'created_at',
			operator: 'between',
			value: [
				isValidLocalDate( from ) ? from : '',
				isValidLocalDate( to ) ? to : '',
			],
		} );
	}

	const orderby = params.get( 'orderby' );
	const order = params.get( 'order' );
	const density = prefs.layout?.density;

	return {
		type: 'table',
		page: parsePage( params.get( 'paged' ) ),
		perPage: prefs.perPage ?? DEFAULT_PER_PAGE,
		sort: {
			field:
				orderby && SUPPORTED_SORT_FIELDS.indexOf( orderby ) !== -1
					? orderby
					: DEFAULT_SORT_FIELD,
			direction:
				order === 'asc' || order === 'desc'
					? order
					: DEFAULT_SORT_DIRECTION,
		},
		filters,
		fields: prefs.fields ?? DEFAULT_FIELDS,
		layout: {
			styles: COLUMN_STYLES,
			...( density ? { density } : {} ),
		},
	};
}

function setParam(
	params: URLSearchParams,
	key: string,
	value: string,
	defaultValue = ''
): void {
	if ( ! value || value === defaultValue ) {
		params.delete( key );
	} else {
		params.set( key, value );
	}
}

function navParams( view: View ): URLSearchParams {
	const params = new URLSearchParams();
	const action = getScalarFilterValue( view, 'action' );
	const type = getScalarFilterValue( view, 'type' );
	const value = getScalarFilterValue( view, 'value' );
	const created = getFilterValue( view, 'created_at' );
	const from = Array.isArray( created ) ? String( created[ 0 ] ?? '' ) : '';
	const to = Array.isArray( created ) ? String( created[ 1 ] ?? '' ) : '';

	setParam( params, 'action', action );
	setParam( params, 'type', type );
	setParam( params, 'value', value );
	setParam( params, 'created_from', isValidLocalDate( from ) ? from : '' );
	setParam( params, 'created_to', isValidLocalDate( to ) ? to : '' );
	setParam( params, 'paged', String( view.page ?? 1 ), '1' );
	setParam(
		params,
		'orderby',
		view.sort?.field ?? DEFAULT_SORT_FIELD,
		DEFAULT_SORT_FIELD
	);
	setParam(
		params,
		'order',
		view.sort?.direction ?? DEFAULT_SORT_DIRECTION,
		DEFAULT_SORT_DIRECTION
	);

	return params;
}

function serializeNav( view: View ): string {
	const params = navParams( view );
	params.sort();
	return params.toString();
}

function urlNav( params: URLSearchParams ): string {
	return serializeNav( viewFromParams( params, {} ) );
}

function listAdminPath( view: View ): string {
	const query: Record< string, string > = {};
	navParams( view ).forEach( ( value, key ) => {
		query[ key ] = value;
	} );
	return getFraudProtectionRoute( '/rules', query );
}

function prefsFromView( view: View ): DisplayPrefs {
	return sanitizePrefs( {
		fields: view.fields,
		perPage: view.perPage,
		layout: view.layout,
	} );
}

export const getQueryFromView = ( view: View ): RulesQuery => {
	const query: RulesQuery = {
		page: view.page ?? 1,
		perPage: view.perPage ?? 20,
		orderby: view.sort?.field ?? 'created_at',
		order: view.sort?.direction ?? 'desc',
	};
	( view.filters ?? [] ).forEach( ( filter ) => {
		if ( filter.field === 'action' ) {
			query.action = Array.isArray( filter.value )
				? String( filter.value[ 0 ] ?? '' )
				: String( filter.value ?? '' );
		}
		if ( filter.field === 'type' ) {
			query.type = Array.isArray( filter.value )
				? String( filter.value[ 0 ] ?? '' )
				: String( filter.value ?? '' );
		}
		if ( filter.field === 'value' && typeof filter.value === 'string' ) {
			query.value = filter.value;
		}
		if ( filter.field === 'created_at' && Array.isArray( filter.value ) ) {
			query.from =
				typeof filter.value[ 0 ] === 'string'
					? getUtcDateFilterBound( filter.value[ 0 ], false )
					: undefined;
			query.to =
				typeof filter.value[ 1 ] === 'string'
					? getUtcDateFilterBound( filter.value[ 1 ], true )
					: undefined;
		}
	} );
	return query;
};

const getActionTab = ( view: View ): 'all' | 'allow' | 'block' => {
	const actionFilter = ( view.filters ?? [] ).find(
		( filter ) => filter.field === 'action'
	);
	const value = Array.isArray( actionFilter?.value )
		? actionFilter?.value[ 0 ]
		: actionFilter?.value;
	return value === 'allow' || value === 'block' ? value : 'all';
};

const getLoadErrorMessage = ( error: string | null ): string | null => {
	if ( ! error ) {
		return null;
	}
	const prefix = __(
		'The fraud prevention rules could not be loaded.',
		'woocommerce-fraud-protection'
	);
	if ( error.startsWith( prefix ) ) {
		return error;
	}
	return sprintf(
		// translators: %s: Error returned by the server.
		__(
			'The fraud prevention rules could not be loaded. %s',
			'woocommerce-fraud-protection'
		),
		error
	);
};

function RulesEmptyState( {
	hasActiveFilters,
}: {
	hasActiveFilters: boolean;
} ) {
	return (
		<EmptyState.Root>
			<EmptyState.Title>
				{ hasActiveFilters
					? __( 'No matching rules', 'woocommerce-fraud-protection' )
					: __( 'No rules', 'woocommerce-fraud-protection' ) }
			</EmptyState.Title>
			<EmptyState.Description>
				{ hasActiveFilters
					? __(
							'Try changing or removing your filters.',
							'woocommerce-fraud-protection'
					  )
					: __(
							'Any rules you create will appear here.',
							'woocommerce-fraud-protection'
					  ) }
			</EmptyState.Description>
		</EmptyState.Root>
	);
}

export function RulesPage() {
	const pageRef = useRef< HTMLDivElement >( null );
	const [ searchParams ] = useSearchParams();
	const [ deletingRule, setDeletingRule ] = useState< Rule | undefined >();
	const [ view, setView ] = useState< View >( () =>
		viewFromParams( searchParams, loadPrefs() )
	);
	const { closeRuleForm, isOpen, openCreateRule, openEditRule, ruleId } =
		useRuleFormDrawer();
	const query = useMemo( () => getQueryFromView( view ), [ view ] );
	const { error, isLoading, rules, totalItems, totalPages } =
		useRules( query );
	const actionTab = getActionTab( view );
	const hasActiveFilters = Boolean( view.filters?.length );
	const isInitialLoading = isLoading && rules.length === 0;
	const actions = useMemo< Action< Rule >[] >(
		() => [
			{
				id: 'edit',
				label: __( 'Edit', 'woocommerce-fraud-protection' ),
				supportsBulk: false,
				callback: ( items ) => {
					if ( items[ 0 ] ) {
						openEditRule( items[ 0 ].id );
					}
				},
			},
			{
				id: 'delete',
				label: __( 'Delete', 'woocommerce-fraud-protection' ),
				supportsBulk: false,
				callback: ( items ) => {
					closeRuleForm();
					setDeletingRule( items[ 0 ] );
				},
			},
		],
		[ closeRuleForm, openEditRule ]
	);
	const loadErrorMessage = getLoadErrorMessage( error );
	const commitToUrl = useCallback( ( nextView: View, replace = false ) => {
		const history = getHistory();
		const path = listAdminPath( nextView );
		if ( replace ) {
			history.replace( path );
		} else {
			history.push( path );
		}
	}, [] );
	const onChangeView = useCallback(
		( changedView: View ) => {
			const filtersChanged =
				JSON.stringify( changedView.filters ?? [] ) !==
				JSON.stringify( view.filters ?? [] );
			const nextView = filtersChanged
				? { ...changedView, page: 1 }
				: changedView;
			setView( nextView );
			savePrefs( prefsFromView( nextView ) );
			if ( serializeNav( nextView ) !== serializeNav( view ) ) {
				commitToUrl( nextView );
			}
		},
		[ commitToUrl, view ]
	);

	useEffect( () => {
		if ( urlNav( searchParams ) === serializeNav( view ) ) {
			return;
		}
		setView( ( previous ) =>
			viewFromParams( searchParams, prefsFromView( previous ) )
		);
	}, [ searchParams, view ] );

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
		if ( isLoading || error ) {
			return;
		}
		const current = view.page ?? 1;
		const target = totalPages >= 1 ? Math.min( current, totalPages ) : 1;
		if ( target !== current ) {
			const nextView = { ...view, page: target };
			setView( nextView );
			commitToUrl( nextView, true );
		}
	}, [ isLoading, error, totalPages, view, commitToUrl ] );

	const paginationInfo = {
		totalItems,
		totalPages: Math.max( totalPages, view.page ?? 1 ),
	};
	return (
		<Stack
			ref={ pageRef }
			className="wc-fraud-protection-rules"
			direction="column"
			aria-busy={ isLoading }
		>
			{ isInitialLoading && (
				<VisuallyHidden>
					{ __( 'Loading rules', 'woocommerce-fraud-protection' ) }
				</VisuallyHidden>
			) }
			<header className="wc-fraud-protection-rules__header">
				<Stack direction="row" justify="space-between" align="start">
					<nav
						className="wc-fraud-protection-rules__breadcrumb"
						aria-label={ __(
							'Breadcrumb',
							'woocommerce-fraud-protection'
						) }
					>
						<Link to={ rootSettingsHref }>
							{ __(
								'Fraud prevention',
								'woocommerce-fraud-protection'
							) }
						</Link>
						<span aria-hidden="true">/</span>
						<span aria-current="page">
							{ __( 'Rules', 'woocommerce-fraud-protection' ) }
						</span>
					</nav>
					<Button
						variant="solid"
						size="compact"
						onClick={ openCreateRule }
					>
						{ __( 'Create rule', 'woocommerce-fraud-protection' ) }
					</Button>
				</Stack>
				<p className="wc-fraud-protection-rules__description">
					{ __(
						'Rules that override any automatic fraud prevention decision. Allow rules take priority over block rules.',
						'woocommerce-fraud-protection'
					) }
				</p>
			</header>
			{ loadErrorMessage && (
				<div className="wc-fraud-protection-rules__error">
					<Notice.Root intent="error">
						<Notice.Description>
							{ loadErrorMessage }
						</Notice.Description>
					</Notice.Root>
				</div>
			) }
			<DataViews
				data={ rules }
				actions={ actions }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				isLoading={ isLoading }
				paginationInfo={ paginationInfo }
				getItemId={ ( item ) => String( item.id ) }
				defaultLayouts={ { table: {} } }
				empty={
					error ? null : (
						<RulesEmptyState
							hasActiveFilters={ hasActiveFilters }
						/>
					)
				}
				search={ false }
				config={ { perPageSizes: [ 20, 50, 100 ] } }
			>
				<Tabs.Root
					value={ actionTab }
					onValueChange={ ( value ) => {
						const filters = ( view.filters ?? [] ).filter(
							( filter ) => filter.field !== 'action'
						);
						if ( value !== 'all' ) {
							filters.push( {
								field: 'action',
								operator: 'is',
								value,
							} );
						}
						onChangeView( { ...view, page: 1, filters } );
					} }
				>
					<Stack
						className="wc-fraud-protection-rules__toolbar"
						direction="row"
						align="center"
						justify="space-between"
						gap="sm"
					>
						<Tabs.List variant="minimal">
							<Tabs.Tab value="all">
								{ __( 'All', 'woocommerce-fraud-protection' ) }
							</Tabs.Tab>
							<Tabs.Tab value="allow">
								{ __(
									'Allow',
									'woocommerce-fraud-protection'
								) }
							</Tabs.Tab>
							<Tabs.Tab value="block">
								{ __(
									'Block',
									'woocommerce-fraud-protection'
								) }
							</Tabs.Tab>
						</Tabs.List>
						<Stack
							className="wc-fraud-protection-rules__view-controls"
							direction="row"
							align="center"
							gap="xs"
						>
							<DataViews.FiltersToggle />
							<DataViews.ViewConfig />
						</Stack>
					</Stack>
					<DataViews.FiltersToggled className="dataviews-filters__container" />
					<Tabs.Panel value="all">
						{ actionTab === 'all' && <DataViews.Layout /> }
					</Tabs.Panel>
					<Tabs.Panel value="allow">
						{ actionTab === 'allow' && <DataViews.Layout /> }
					</Tabs.Panel>
					<Tabs.Panel value="block">
						{ actionTab === 'block' && <DataViews.Layout /> }
					</Tabs.Panel>
					<DataViews.Footer />
				</Tabs.Root>
			</DataViews>
			<RuleFormDrawer
				open={ isOpen }
				ruleId={ ruleId }
				onClose={ closeRuleForm }
				onViewRule={ openEditRule }
			/>
			<RuleDeleteDialog
				rule={ deletingRule }
				onClose={ () => setDeletingRule( undefined ) }
			/>
		</Stack>
	);
}
