import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { format } from '@wordpress/date';
import { Icon, Notice, Stack, Tabs, Text } from '@wordpress/ui';
import { notAllowed, published } from '@wordpress/icons';
import { DataViews } from '@wordpress/dataviews/wp';
import type { Field, View } from '@wordpress/dataviews';
import { Link } from 'react-router-dom';

import type { Rule, RulesQuery } from './data/rules-store';
import { useRules } from './hooks/use-rules';
import { getFraudProtectionRoute } from './navigation';

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
		label: __( 'Created date', 'woocommerce-fraud-protection' ),
		header: __( 'Created', 'woocommerce-fraud-protection' ),
		type: 'date',
		filterBy: { operators: [ 'between' ] },
		render: ( { item } ) => format( 'j M Y', item.created_at ),
	},
];

export const getUtcDateFilterBound = (
	value: string,
	endOfDay: boolean
): string | undefined => {
	const date = value.slice( 0, 10 );
	if ( ! /^\d{4}-\d{2}-\d{2}$/.test( date ) ) {
		return undefined;
	}

	const [ year, month, day ] = date.split( '-' ).map( Number );
	const localBound = new Date(
		year,
		month - 1,
		day,
		endOfDay ? 23 : 0,
		endOfDay ? 59 : 0,
		endOfDay ? 59 : 0
	);
	if (
		localBound.getFullYear() !== year ||
		localBound.getMonth() !== month - 1 ||
		localBound.getDate() !== day
	) {
		return undefined;
	}

	return localBound.toISOString().replace( '.000Z', 'Z' );
};

const getFields = ( sortField?: string ): Field< Rule >[] =>
	fields.map( ( field ) => {
		const header = field.header ?? field.label ?? field.id;

		return {
			...field,
			header: (
				<>
					{ header }
					{ field.id !== sortField && (
						<span aria-hidden="true"> ↓</span>
					) }
				</>
			),
		};
	} );

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

export function RulesPage() {
	const [ view, setView ] = useState< View >( {
		type: 'table' as const,
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
	const { error, isLoading, requestRules, rules, totalItems, totalPages } =
		useRules();
	const visibleFields = useMemo(
		() => getFields( view.sort?.field ),
		[ view.sort?.field ]
	);
	const actionTab = getActionTab( view );

	useEffect( () => {
		requestRules( getQueryFromView( view ) );
	}, [ requestRules, view ] );

	const empty = useMemo(
		() => (
			<Text variant="body-md" render={ <p /> }>
				{ __( 'No rules found.', 'woocommerce-fraud-protection' ) }
			</Text>
		),
		[]
	);
	return (
		<Stack className="wc-fraud-protection-rules" direction="column">
			<DataViews
				data={ rules }
				fields={ visibleFields }
				view={ view }
				onChangeView={ setView }
				isLoading={ isLoading }
				paginationInfo={ { totalItems, totalPages } }
				getItemId={ ( item ) => String( item.id ) }
				defaultLayouts={ { table: {} } }
				empty={ empty }
				search={ false }
				config={ { perPageSizes: [ 20, 50, 100 ] } }
			>
				<Stack direction="column">
					<Stack
						className="wc-fraud-protection-rules__header"
						direction="column"
						gap="none"
						style={ {
							height: 84,
							boxSizing: 'border-box',
							padding: '12px 16px',
						} }
					>
						<Text
							className="wc-fraud-protection-rules__breadcrumb"
							variant="heading-lg"
							style={ {
								display: 'flex',
								alignItems: 'center',
								gap: 8,
								margin: '0 8px 8px',
								height: 32,
								fontWeight: 500,
							} }
							render={
								<nav
									aria-label={ __(
										'Breadcrumb',
										'woocommerce-fraud-protection'
									) }
								/>
							}
						>
							<Link to={ rootSettingsHref }>
								{ __(
									'Fraud prevention',
									'woocommerce-fraud-protection'
								) }
							</Link>
							<span aria-hidden="true">/</span>
							<span aria-current="page">
								{ __(
									'Rules',
									'woocommerce-fraud-protection'
								) }
							</span>
						</Text>
						<Text
							className="wc-fraud-protection-rules__description"
							variant="body-md"
							style={ {
								color: 'var(--wpds-color-foreground-content-neutral-weak)',
								marginInline: 8,
							} }
							render={ <p /> }
						>
							{ __(
								'Rules that always let checkout attempts through or always block them, no matter what our fraud detection decides.',
								'woocommerce-fraud-protection'
							) }
						</Text>
					</Stack>
					{ error && (
						<Notice.Root intent="error">
							<Notice.Description>{ error }</Notice.Description>
						</Notice.Root>
					) }
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
							setView( {
								...view,
								page: 1,
								filters,
							} );
						} }
					>
						<Stack
							className="wc-fraud-protection-rules__toolbar"
							direction="row"
							align="center"
							justify="space-between"
							style={ {
								height: 40,
								boxSizing: 'border-box',
								padding: '0 24px',
							} }
						>
							<Tabs.List
								variant="minimal"
								style={ { height: 40, gap: 12 } }
							>
								<Tabs.Tab value="all" style={ { height: 40 } }>
									{ __(
										'All',
										'woocommerce-fraud-protection'
									) }
								</Tabs.Tab>
								<Tabs.Tab
									value="allow"
									style={ { height: 40 } }
								>
									{ __(
										'Allow',
										'woocommerce-fraud-protection'
									) }
								</Tabs.Tab>
								<Tabs.Tab
									value="block"
									style={ { height: 40 } }
								>
									{ __(
										'Block',
										'woocommerce-fraud-protection'
									) }
								</Tabs.Tab>
							</Tabs.List>
							<Stack direction="row" align="center" gap="sm">
								<DataViews.FiltersToggle />
								<DataViews.ViewConfig />
							</Stack>
						</Stack>
						<Tabs.Panel value="all">
							{ actionTab === 'all' && (
								<>
									<DataViews.FiltersToggled className="wc-fraud-protection-rules__filters" />
									<DataViews.Layout />
									<DataViews.Pagination />
								</>
							) }
						</Tabs.Panel>
						<Tabs.Panel value="allow">
							{ actionTab === 'allow' && (
								<>
									<DataViews.FiltersToggled className="wc-fraud-protection-rules__filters" />
									<DataViews.Layout />
									<DataViews.Pagination />
								</>
							) }
						</Tabs.Panel>
						<Tabs.Panel value="block">
							{ actionTab === 'block' && (
								<>
									<DataViews.FiltersToggled className="wc-fraud-protection-rules__filters" />
									<DataViews.Layout />
									<DataViews.Pagination />
								</>
							) }
						</Tabs.Panel>
					</Tabs.Root>
				</Stack>
			</DataViews>
		</Stack>
	);
}
