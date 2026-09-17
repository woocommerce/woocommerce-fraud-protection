import { useMemo, useState } from '@wordpress/element';
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
import { Link } from 'react-router-dom';

import type { Rule, RulesQuery } from './data/rules-store';
import { useRules } from './hooks/use-rules';
import { useRuleFormDrawer } from './hooks/use-rule-form-drawer';
import { getFraudProtectionRoute } from './navigation';
import { formatRuleDate, getUtcDateFilterBound } from './rule-date';
import { RuleFormDrawer } from './components/rule-form-drawer';
import { RuleDeleteDialog } from './components/rule-delete-dialog';
import { createPreferenceStore } from '../persisted-state';
import {
	createListUrlCodec,
	defineEnumFilter,
	defineRangeFilter,
	defineScalarFilter,
	useListState,
} from '../list-state';

const rootSettingsHref = getFraudProtectionRoute( '/' );
const ruleActions = [
	{ value: 'allow', label: __( 'Allow', 'woocommerce-fraud-protection' ) },
	{ value: 'block', label: __( 'Block', 'woocommerce-fraud-protection' ) },
];
const ruleTypes = [
	{ value: 'email', label: __( 'Email', 'woocommerce-fraud-protection' ) },
	{ value: 'ip', label: __( 'IP', 'woocommerce-fraud-protection' ) },
];
const actionFilter = defineEnumFilter( {
	field: 'action',
	operator: 'is',
	param: 'action',
	values: ruleActions,
} );
const typeFilter = defineEnumFilter( {
	field: 'type',
	operator: 'is',
	param: 'type',
	values: ruleTypes,
} );
const valueFilter = defineScalarFilter( {
	field: 'value',
	operator: 'is',
	param: 'value',
} );
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

function isValidLocalDate( value: string ): boolean {
	return Boolean(
		/^\d{4}-\d{2}-\d{2}$/.test( value ) &&
			getUtcDateFilterBound( value, false )
	);
}

const createdFilter = defineRangeFilter( {
	field: 'created_at',
	operator: 'between',
	params: [ 'created_from', 'created_to' ],
	validate: isValidLocalDate,
} );

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: DEFAULT_PER_PAGE,
	sort: {
		field: DEFAULT_SORT_FIELD,
		direction: DEFAULT_SORT_DIRECTION,
	},
	filters: [],
	fields: DEFAULT_FIELDS,
	layout: { styles: COLUMN_STYLES },
};

const preferenceStore = createPreferenceStore( {
	storageKey: STORAGE_KEY,
	version: STORAGE_VERSION,
	supportedFields: DEFAULT_FIELDS,
	supportedPerPage: SUPPORTED_PER_PAGE,
	supportedDensities: SUPPORTED_DENSITIES,
} );

const listCodec = createListUrlCodec( {
	path: '/rules',
	defaultView: DEFAULT_VIEW,
	filters: [ actionFilter, typeFilter, valueFilter, createdFilter ],
	supportedSortFields: SUPPORTED_SORT_FIELDS,
	queryOrder: [ 'filters', 'page', 'sort' ],
} );

const fields: Field< Rule >[] = [
	{
		id: actionFilter.field,
		label: __( 'Action', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: actionFilter.values,
		filterBy: { operators: [ actionFilter.operator ] },
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
		id: valueFilter.field,
		label: __( 'Value', 'woocommerce-fraud-protection' ),
		type: 'text',
		filterBy: { operators: [ valueFilter.operator ] },
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
		id: typeFilter.field,
		label: __( 'Rule type', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: typeFilter.values,
		filterBy: { operators: [ typeFilter.operator ] },
		render: ( { item } ) =>
			item.type === 'email'
				? __( 'Email', 'woocommerce-fraud-protection' )
				: __( 'IP', 'woocommerce-fraud-protection' ),
	},
	{
		id: createdFilter.field,
		label: __( 'Created', 'woocommerce-fraud-protection' ),
		header: __( 'Created', 'woocommerce-fraud-protection' ),
		type: 'date',
		filterBy: { operators: [ createdFilter.operator ] },
		render: ( { item } ) => formatRuleDate( item.created_at ),
	},
];

export const getQueryFromView = ( view: View ): RulesQuery => {
	const query: RulesQuery = {
		page: view.page ?? 1,
		perPage: view.perPage ?? 20,
		orderby: view.sort?.field ?? 'created_at',
		order: view.sort?.direction ?? 'desc',
	};
	( view.filters ?? [] ).forEach( ( filter ) => {
		if ( filter.field === actionFilter.field ) {
			query.action = Array.isArray( filter.value )
				? String( filter.value[ 0 ] ?? '' )
				: String( filter.value ?? '' );
		}
		if ( filter.field === typeFilter.field ) {
			query.type = Array.isArray( filter.value )
				? String( filter.value[ 0 ] ?? '' )
				: String( filter.value ?? '' );
		}
		if (
			filter.field === valueFilter.field &&
			typeof filter.value === 'string'
		) {
			query.value = filter.value;
		}
		if (
			filter.field === createdFilter.field &&
			Array.isArray( filter.value )
		) {
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
	const actionViewFilter = ( view.filters ?? [] ).find(
		( filter ) => filter.field === actionFilter.field
	);
	const value = Array.isArray( actionViewFilter?.value )
		? actionViewFilter?.value[ 0 ]
		: actionViewFilter?.value;
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

function useRulesList( view: View ) {
	const query = useMemo( () => getQueryFromView( view ), [ view ] );
	return useRules( query );
}

export function RulesPage() {
	const [ deletingRule, setDeletingRule ] = useState< Rule | undefined >();
	const {
		pageRef,
		view,
		onChangeView,
		result: { error, isLoading, rules, totalItems, totalPages },
	} = useListState( {
		codec: listCodec,
		preferenceStore,
		policies: { resetPageOnFilterChange: true },
		useData: useRulesList,
	} );
	const { closeRuleForm, isOpen, openCreateRule, openEditRule, ruleId } =
		useRuleFormDrawer();
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
