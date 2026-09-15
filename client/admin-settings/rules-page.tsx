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
import type { Field, View } from '@wordpress/dataviews';
import { Link } from 'react-router-dom';

import type { Rule, RulesQuery } from './data/rules-store';
import { useRules } from './hooks/use-rules';
import { getFraudProtectionRoute } from './navigation';
import { formatRuleDate, getUtcDateFilterBound } from './rule-date';
import { RuleFormDrawer } from './components/rule-form-drawer';

const rootSettingsHref = getFraudProtectionRoute( '/' );
const ruleActions = [
	{ value: 'allow', label: __( 'Allow', 'woocommerce-fraud-protection' ) },
	{ value: 'block', label: __( 'Block', 'woocommerce-fraud-protection' ) },
];
const ruleTypes = [
	{ value: 'email', label: __( 'Email', 'woocommerce-fraud-protection' ) },
	{ value: 'ip', label: __( 'IP', 'woocommerce-fraud-protection' ) },
];

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
		/* translators: %s: Error returned by the server. */
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
							'Any custom rules you create will appear here.',
							'woocommerce-fraud-protection'
					  ) }
			</EmptyState.Description>
		</EmptyState.Root>
	);
}

export function RulesPage() {
	const [ isCreateOpen, setIsCreateOpen ] = useState( false );
	const [ view, setView ] = useState< View >( {
		type: 'table',
		page: 1,
		perPage: 20,
		sort: { field: 'created_at', direction: 'desc' },
		filters: [],
		fields: [ 'action', 'value', 'type', 'created_at' ],
		layout: {
			styles: {
				action: { width: '25%' },
				value: { width: '25%' },
				type: { width: '25%' },
				created_at: { width: '25%' },
			},
		},
	} );
	const query = useMemo( () => getQueryFromView( view ), [ view ] );
	const { error, isLoading, rules, totalItems, totalPages } =
		useRules( query );
	const actionTab = getActionTab( view );
	const hasActiveFilters = Boolean( view.filters?.length );
	const isInitialLoading = isLoading && rules.length === 0;
	const loadErrorMessage = getLoadErrorMessage( error );
	return (
		<Stack
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
						onClick={ () => setIsCreateOpen( true ) }
					>
						{ __( 'Create rule', 'woocommerce-fraud-protection' ) }
					</Button>
				</Stack>
				<p className="wc-fraud-protection-rules__description">
					{ __(
						'Rules that always let checkout attempts through or always block them, no matter what our fraud detection decides.',
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
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				isLoading={ isLoading }
				paginationInfo={ { totalItems, totalPages } }
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
						setView( { ...view, page: 1, filters } );
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
					<DataViews.FiltersToggled />
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
				open={ isCreateOpen }
				onClose={ () => setIsCreateOpen( false ) }
			/>
		</Stack>
	);
}
