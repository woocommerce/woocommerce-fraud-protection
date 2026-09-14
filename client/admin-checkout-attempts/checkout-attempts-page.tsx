import { Notice, Tabs } from '@wordpress/ui';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews';
import type { View } from '@wordpress/dataviews';
import { Link } from 'react-router-dom';

import { buildActions } from './actions';
import { EnableFraudPreventionDrawer } from './enable-fraud-prevention-drawer';
import { getFields } from './fields';
import { ProtectionOffBanner } from './protection-off-banner';
import { loadState, saveState } from './persisted-state';
import { getConfig } from './types';
import { useCheckoutAttempts } from './use-checkout-attempts';
import { getFraudProtectionRoute } from '../admin-settings/navigation';
import type { StatusTab } from './persisted-state';
import type { FinalStatus } from './types';
import './style.scss';

const settingsRoute = getFraudProtectionRoute( '/' );

const TAB_TO_STATUS: Record< StatusTab, FinalStatus | null > = {
	all: null,
	allowed: 'allowed',
	blocked: 'blocked',
};

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'recorded_at', direction: 'desc' },
	search: '',
	filters: [],
	titleField: 'payment_method',
	fields: [
		'recorded_at',
		'email',
		'ip',
		'ip_country',
		'billing_country',
		'outcome',
	],
};

const DEFAULT_STATE = {
	view: DEFAULT_VIEW,
	tab: 'all' as StatusTab,
};

export function CheckoutAttemptsPage() {
	const config = useMemo( getConfig, [] );
	const [ initialState ] = useState( () => loadState( DEFAULT_STATE ) );
	const [ view, setView ] = useState< View >( initialState.view );
	const [ tab, setTab ] = useState< StatusTab >( initialState.tab );

	// Automatic fraud prevention state starts from the injected config but can be
	// turned on from the enable drawer, so the banner and flagged tooltips update
	// without a reload.
	const [ protectionOn, setProtectionOn ] = useState(
		config.automaticProtection
	);
	const [ isDrawerOpen, setIsDrawerOpen ] = useState( false );
	const openDrawer = useCallback( () => setIsDrawerOpen( true ), [] );

	const effectiveConfig = useMemo(
		() => ( { ...config, automaticProtection: protectionOn } ),
		[ config, protectionOn ]
	);

	const isCompact = 'compact' === view.layout?.density;
	const fields = useMemo(
		() => getFields( effectiveConfig, isCompact ),
		[ effectiveConfig, isCompact ]
	);
	const actions = useMemo(
		() => buildActions( effectiveConfig, openDrawer ),
		[ effectiveConfig, openDrawer ]
	);

	// Remember how the merchant left the list — including the page — so a reload
	// restores it. The list lives inside the settings single-page app, so page
	// position is kept in storage rather than a URL query arg the router owns.
	useEffect( () => {
		saveState( { view, tab } );
	}, [ view, tab ] );

	const { sessions, totalItems, totalPages, isLoading, error } =
		useCheckoutAttempts( view, TAB_TO_STATUS[ tab ] );

	// A page past the last one (a stale URL, or rows pruned since) would show a
	// confusing empty list, so fall back to the last existing page — or page 1
	// when there are no results at all.
	useEffect( () => {
		if ( isLoading ) {
			return;
		}

		const current = view.page ?? 1;
		const target = totalPages >= 1 ? Math.min( current, totalPages ) : 1;

		if ( target !== current ) {
			setView( ( previous ) => ( { ...previous, page: target } ) );
		}
	}, [ isLoading, totalPages, view.page ] );

	const resetToFirstPage = () =>
		setView( ( current ) => ( { ...current, page: 1 } ) );

	const onTabChange = ( value: string ) => {
		setTab( value as StatusTab );
		resetToFirstPage();
	};

	return (
		<div className="wc-fraud-protection-checkout-attempts__page">
			<header className="wc-fraud-protection-checkout-attempts__header">
				<nav
					className="wc-fraud-protection-checkout-attempts__breadcrumb"
					aria-label={ __(
						'Breadcrumb',
						'woocommerce-fraud-protection'
					) }
				>
					<Link to={ settingsRoute }>
						{ __(
							'Fraud prevention',
							'woocommerce-fraud-protection'
						) }
					</Link>
					<span aria-hidden="true"> / </span>
					<span>
						{ __(
							'Checkout attempts',
							'woocommerce-fraud-protection'
						) }
					</span>
				</nav>
				<p className="wc-fraud-protection-checkout-attempts__description">
					{ __(
						'A record of past checkout attempts and how fraud prevention responded to each one. Creating rules or changing your protection settings affects future attempts, not these records.',
						'woocommerce-fraud-protection'
					) }
				</p>
			</header>

			{ error && (
				<Notice.Root intent="error">
					<Notice.Description>{ error }</Notice.Description>
				</Notice.Root>
			) }

			{ /* The list below is shared across tabs, so each tab pairs with an
			     empty panel purely to satisfy the Tabs accessibility contract. */ }
			<Tabs.Root value={ tab } onValueChange={ onTabChange }>
				<Tabs.List>
					<Tabs.Tab value="all">
						{ __( 'All', 'woocommerce-fraud-protection' ) }
					</Tabs.Tab>
					<Tabs.Tab value="allowed">
						{ __( 'Allowed', 'woocommerce-fraud-protection' ) }
					</Tabs.Tab>
					<Tabs.Tab value="blocked">
						{ __( 'Blocked', 'woocommerce-fraud-protection' ) }
					</Tabs.Tab>
				</Tabs.List>
				<Tabs.Panel value="all" />
				<Tabs.Panel value="allowed" />
				<Tabs.Panel value="blocked" />
			</Tabs.Root>

			{ ! protectionOn && (
				<ProtectionOffBanner onEnable={ openDrawer } />
			) }

			<DataViews< ( typeof sessions )[ number ] >
				data={ sessions }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ { totalItems, totalPages } }
				isLoading={ isLoading }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				searchLabel={ __(
					'Search by email or IP address',
					'woocommerce-fraud-protection'
				) }
				empty={
					<p>
						{ __(
							'No checkout attempts have been recorded in the last 30 days.',
							'woocommerce-fraud-protection'
						) }
					</p>
				}
			/>

			<EnableFraudPreventionDrawer
				open={ isDrawerOpen }
				onOpenChange={ setIsDrawerOpen }
				onEnabled={ () => setProtectionOn( true ) }
			/>
		</div>
	);
}
