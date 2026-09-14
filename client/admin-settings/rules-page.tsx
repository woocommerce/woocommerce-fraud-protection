import { useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice, Stack, Tabs, Text } from '@wordpress/ui';
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
	{ value: 'ip', label: __( 'IP address', 'woocommerce-fraud-protection' ) },
];

const fields: Field< Rule >[] = [
	{
		id: 'action',
		label: __( 'Action', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: ruleActions,
		filterBy: { operators: [ 'is' ] },
		enableSorting: false,
		render: ( { item } ) =>
			item.action === 'allow'
				? __( 'Allow', 'woocommerce-fraud-protection' )
				: __( 'Block', 'woocommerce-fraud-protection' ),
	},
	{
		id: 'value',
		label: __( 'Value', 'woocommerce-fraud-protection' ),
		type: 'text',
		filterBy: { operators: [ 'is' ] },
		enableSorting: false,
	},
	{
		id: 'type',
		label: __( 'Rule type', 'woocommerce-fraud-protection' ),
		type: 'text',
		elements: ruleTypes,
		filterBy: { operators: [ 'is' ] },
		enableSorting: false,
		render: ( { item } ) =>
			item.type === 'email'
				? __( 'Email', 'woocommerce-fraud-protection' )
				: __( 'IP address', 'woocommerce-fraud-protection' ),
	},
	{
		id: 'created_at',
		label: __( 'Created', 'woocommerce-fraud-protection' ),
		type: 'date',
		filterBy: { operators: [ 'between' ] },
		enableSorting: false,
	},
];

export const getQueryFromView = ( view: View ): RulesQuery => {
	const query: RulesQuery = {
		page: view.page ?? 1,
		perPage: view.perPage ?? 20,
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
					? filter.value[ 0 ].slice( 0, 10 )
					: undefined;
			query.to =
				typeof filter.value[ 1 ] === 'string'
					? filter.value[ 1 ].slice( 0, 10 )
					: undefined;
		}
	} );
	return query;
};

export function RulesPage() {
	const [ actionTab, setActionTab ] = useState( 'all' );
	const [ view, setView ] = useState< View >( {
		type: 'table' as const,
		page: 1,
		perPage: 20,
		filters: [],
		fields: [ 'action', 'value', 'type', 'created_at' ],
		layout: {},
	} );
	const { error, isLoading, requestRules, rules, totalItems, totalPages } =
		useRules();

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
	const dataViews = (
		<DataViews
			data={ rules }
			fields={ fields }
			view={ view }
			onChangeView={ setView }
			isLoading={ isLoading }
			paginationInfo={ { totalItems, totalPages } }
			getItemId={ ( item ) => String( item.id ) }
			defaultLayouts={ { table: {} } }
			empty={ empty }
			search={ false }
			config={ { perPageSizes: [ 20, 50, 100 ] } }
		/>
	);

	return (
		<Stack
			className="wc-fraud-protection-rules"
			direction="column"
			gap="lg"
		>
			<nav
				className="wc-fraud-protection-rules__breadcrumb"
				aria-label={ __(
					'Breadcrumb',
					'woocommerce-fraud-protection'
				) }
			>
				<Link to={ rootSettingsHref }>
					{ __( 'Fraud prevention', 'woocommerce-fraud-protection' ) }
				</Link>
				<span aria-hidden="true">/</span>
				<span aria-current="page">
					{ __( 'Rules', 'woocommerce-fraud-protection' ) }
				</span>
			</nav>
			<Stack direction="column" gap="xs">
				<Text variant="heading-lg" render={ <h1 /> }>
					{ __( 'Rules', 'woocommerce-fraud-protection' ) }
				</Text>
				<Text variant="body-md" render={ <p /> }>
					{ __(
						'Allow rules take priority over block rules.',
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
					setActionTab( value );
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
				<Tabs.List>
					<Tabs.Tab value="all">
						{ __( 'All', 'woocommerce-fraud-protection' ) }
					</Tabs.Tab>
					<Tabs.Tab value="allow">
						{ __( 'Allow', 'woocommerce-fraud-protection' ) }
					</Tabs.Tab>
					<Tabs.Tab value="block">
						{ __( 'Block', 'woocommerce-fraud-protection' ) }
					</Tabs.Tab>
				</Tabs.List>
				<Tabs.Panel value="all">
					{ actionTab === 'all' && dataViews }
				</Tabs.Panel>
				<Tabs.Panel value="allow">
					{ actionTab === 'allow' && dataViews }
				</Tabs.Panel>
				<Tabs.Panel value="block">
					{ actionTab === 'block' && dataViews }
				</Tabs.Panel>
			</Tabs.Root>
		</Stack>
	);
}
