import { Notice, Tabs } from '@wordpress/ui';
import {
	useCallback,
	useEffect,
	useMemo,
	useRef,
	useState,
} from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews/wp';
import type { View } from '@wordpress/dataviews';
import { getHistory, getNewPath } from '@woocommerce/navigation';
import { Link, useSearchParams } from 'react-router-dom';

import { buildActions } from './actions';
import { EnableFraudPreventionDrawer } from './enable-fraud-prevention-drawer';
import { getFields } from './fields';
import { ProtectionOffBanner } from './protection-off-banner';
import { loadPrefs, savePrefs } from './persisted-state';
import { useCheckoutAttempts } from './use-checkout-attempts';
import { getFraudProtectionRoute } from '../admin-settings/navigation';
import { settingsStore } from '../admin-settings/data/store';
import type { Rule } from '../admin-settings/data/rules-store';
import { useRuleFormDrawer } from '../admin-settings/hooks/use-rule-form-drawer';
import {
	RuleFormDrawer,
	type RuleFormContext,
} from '../admin-settings/components/rule-form-drawer';
import { RuleDeleteDialog } from '../admin-settings/components/rule-delete-dialog';
import type { DisplayPrefs, StatusTab } from './persisted-state';
import type { FinalStatus } from './types';
import type { RuleActionType } from './actions';
import './style.scss';

// The settings pane URL: the breadcrumb links to it, and the flagged-attempt
// tooltip opens it in a new tab.
const settingsRoute = getFraudProtectionRoute( '/' );

const TABS: StatusTab[] = [ 'all', 'allowed', 'blocked' ];

const TAB_TO_STATUS: Record< StatusTab, FinalStatus | null > = {
	all: null,
	allowed: 'allowed',
	blocked: 'blocked',
};

const DEFAULT_SORT_FIELD = 'recorded_at';
const DEFAULT_SORT_DIRECTION = 'desc';
const DEFAULT_PER_PAGE = 20;
const DEFAULT_FIELDS = [
	'recorded_at',
	'email',
	'ip',
	'ip_country',
	'billing_country',
	'outcome',
];

// The navigation state (search, filters, status tab, sort, page) lives in the
// URL query so a link reproduces the view and Back/Forward restore it. Column
// visibility, density and page size are display preferences and live in
// localStorage instead. These helpers translate between the two and the
// DataViews `view` object.

function getListParam( params: URLSearchParams, key: string ): string[] {
	const value = params.get( key );
	return value ? value.split( ',' ).filter( Boolean ) : [];
}

function getTab( params: URLSearchParams ): StatusTab {
	const status = params.get( 'status' );
	return TABS.indexOf( status as StatusTab ) !== -1
		? ( status as StatusTab )
		: 'all';
}

function viewFromParams( params: URLSearchParams, prefs: DisplayPrefs ): View {
	const filters: NonNullable< View[ 'filters' ] > = [];

	const outcome = getListParam( params, 'outcome' );
	if ( outcome.length ) {
		filters.push( { field: 'outcome', operator: 'isAny', value: outcome } );
	}
	const provider = getListParam( params, 'provider' );
	if ( provider.length ) {
		filters.push( {
			field: 'payment_method',
			operator: 'isAny',
			value: provider,
		} );
	}
	const rules = params.get( 'rules' );
	if ( 'with' === rules || 'without' === rules ) {
		filters.push( { field: 'rules', operator: 'is', value: rules } );
	}

	const paged = parseInt( params.get( 'paged' ) ?? '', 10 );

	return {
		type: 'table',
		page: Number.isInteger( paged ) && paged > 0 ? paged : 1,
		perPage: prefs.perPage ?? DEFAULT_PER_PAGE,
		sort: {
			field: params.get( 'orderby' ) || DEFAULT_SORT_FIELD,
			direction: 'asc' === params.get( 'order' ) ? 'asc' : 'desc',
		},
		search: params.get( 'search' ) ?? '',
		filters,
		titleField: 'payment_method',
		fields: prefs.fields ?? DEFAULT_FIELDS,
		...( prefs.layout ? { layout: prefs.layout } : {} ),
	};
}

function filterValueList( view: View, field: string ): string[] {
	const filter = ( view.filters ?? [] ).find(
		( candidate ) => candidate.field === field
	);
	if ( ! filter || filter.value === undefined || filter.value === null ) {
		return [];
	}
	return Array.isArray( filter.value )
		? filter.value.map( String )
		: [ String( filter.value ) ];
}

function setParam(
	params: URLSearchParams,
	key: string,
	value: string,
	defaultValue: string
): void {
	if ( '' === value || value === defaultValue ) {
		params.delete( key );
	} else {
		params.set( key, value );
	}
}

// Build the list's own query args from the current view and tab.
function navParams( view: View, tab: StatusTab ): URLSearchParams {
	const params = new URLSearchParams();
	setParam( params, 'search', view.search ?? '', '' );
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
	setParam(
		params,
		'outcome',
		filterValueList( view, 'outcome' ).join( ',' ),
		''
	);
	setParam(
		params,
		'provider',
		filterValueList( view, 'payment_method' ).join( ',' ),
		''
	);
	setParam(
		params,
		'rules',
		filterValueList( view, 'rules' )[ 0 ] ?? '',
		''
	);
	setParam( params, 'status', tab, 'all' );
	return params;
}

// A canonical string of the list's nav state, for comparing the view against the
// URL without being tripped up by param order or omitted defaults.
function serializeNav( view: View, tab: StatusTab ): string {
	const params = navParams( view, tab );
	params.sort();
	return params.toString();
}

// The canonical nav string the URL currently represents (defaults normalized the
// same way as serializeNav, so an explicit `paged=1` matches an omitted one).
function urlNav( params: URLSearchParams ): string {
	return serializeNav( viewFromParams( params, {} ), getTab( params ) );
}

// The full WooCommerce admin URL for the list in a given view and tab. Writing
// this (rather than the router's `/checkout-attempts` pathname) keeps the browser
// on `admin.php` with the route in the `path` query arg, so a reload resolves.
function listAdminPath( view: View, tab: StatusTab ): string {
	const query: Record< string, string > = {
		page: 'wc-settings',
		tab: 'woocommerce_fraud_protection',
	};
	navParams( view, tab ).forEach( ( value, key ) => {
		query[ key ] = value;
	} );

	return getNewPath( query, '/checkout-attempts', {} );
}

export function CheckoutAttemptsPage() {
	const pageRef = useRef< HTMLDivElement >( null );
	const [ searchParams ] = useSearchParams();

	// The DataViews view and the status tab are local state, so the list keeps
	// full control of its own interactions — including a filter that has been
	// added but does not have a value yet. The navigation parts are mirrored to
	// the URL (so a link reproduces the view and Back/Forward restore it), while
	// column visibility, density and page size are saved as display preferences.
	const [ view, setView ] = useState< View >( () =>
		viewFromParams( searchParams, loadPrefs() )
	);
	const [ tab, setTab ] = useState< StatusTab >( () =>
		getTab( searchParams )
	);

	// Automatic fraud prevention state comes from the shared settings store (its
	// initial GET is preloaded for this route). Until the settings are known —
	// still loading, or the load failed and left them null — treat protection as
	// on so protection-off controls (the banner and the "enable" row action) do
	// not appear or fire before the real state is confirmed.
	const { protectionOn, enabledAt } = useSelect( ( select ) => {
		const settings = select( settingsStore ).getSettings();
		return {
			protectionOn: settings
				? settings.automatic_protection === true
				: true,
			enabledAt: settings?.automatic_protection_enabled_at ?? null,
		};
	}, [] );

	const [ isEnableDrawerOpen, setIsEnableDrawerOpen ] = useState( false );
	const openEnableDrawer = useCallback(
		() => setIsEnableDrawerOpen( true ),
		[]
	);
	const [ ruleFormContext, setRuleFormContext ] =
		useState< RuleFormContext >();
	const [ deletingRule, setDeletingRule ] = useState< Rule >();
	const {
		closeRuleForm,
		isOpen: isRuleFormOpen,
		openCreateRule,
		openEditRule,
		ruleId,
	} = useRuleFormDrawer();

	const effectiveConfig = useMemo(
		() => ( {
			automaticProtection: protectionOn,
			automaticProtectionEnabledAt: enabledAt,
			settingsUrl: settingsRoute,
		} ),
		[ protectionOn, enabledAt ]
	);

	const isCompact = 'compact' === view.layout?.density;
	const fields = useMemo(
		() => getFields( effectiveConfig, isCompact ),
		[ effectiveConfig, isCompact ]
	);
	const { sessions, totalItems, totalPages, isLoading, error, refresh } =
		useCheckoutAttempts( view, TAB_TO_STATUS[ tab ] );
	const closeAttemptRuleForm = useCallback( () => {
		closeRuleForm();
		setRuleFormContext( undefined );
	}, [ closeRuleForm ] );
	const openAttemptRuleForm = useCallback(
		( item: ( typeof sessions )[ number ], type: RuleActionType ) => {
			const value = item[ type ];
			if ( ! value ) {
				return;
			}
			setRuleFormContext( {
				recordedAttemptId: item.id,
				type,
				value,
				finalStatus: item.final_status,
			} );
			openCreateRule();
		},
		[ openCreateRule ]
	);
	const openAttemptRuleEdit = useCallback(
		( selectedRuleId: number ) => {
			setRuleFormContext( undefined );
			openEditRule( selectedRuleId );
		},
		[ openEditRule ]
	);
	const openAttemptRuleDelete = useCallback(
		( item: ( typeof sessions )[ number ], type: RuleActionType ) => {
			const rule = item.rules[ type ];
			const value = item[ type ];
			if ( ! rule || ! value ) {
				return;
			}
			closeAttemptRuleForm();
			setDeletingRule( { ...rule, type, value } );
		},
		[ closeAttemptRuleForm ]
	);
	const actions = useMemo(
		() =>
			buildActions( effectiveConfig, openEnableDrawer, {
				onCreateRule: openAttemptRuleForm,
				onEditRule: openAttemptRuleEdit,
				onDeleteRule: openAttemptRuleDelete,
			} ),
		[
			effectiveConfig,
			openAttemptRuleDelete,
			openAttemptRuleEdit,
			openAttemptRuleForm,
			openEnableDrawer,
		]
	);

	// Mirror the current view and tab to the URL by pushing a full admin URL
	// through the WooCommerce history. `replace` avoids a history entry for
	// automatic corrections (and search typing); user navigation pushes so Back
	// restores the previous list state.
	const commitToUrl = useCallback(
		( nextView: View, nextTab: StatusTab, replace = false ) => {
			const path = listAdminPath( nextView, nextTab );
			const history = getHistory();
			if ( replace ) {
				history.replace( path );
			} else {
				history.push( path );
			}
		},
		[]
	);

	const onChangeView = useCallback(
		( nextView: View ) => {
			// A change to the search text alone replaces the URL so typing does
			// not create a history entry per keystroke; any other change pushes.
			const searchOnly =
				serializeNav( { ...nextView, search: '' }, tab ) ===
				serializeNav( { ...view, search: '' }, tab );

			setView( nextView );
			// Column visibility, density and page size are display preferences.
			savePrefs( {
				fields: nextView.fields,
				perPage: nextView.perPage,
				layout: nextView.layout,
			} );
			commitToUrl( nextView, tab, searchOnly );
		},
		[ commitToUrl, tab, view ]
	);

	const onTabChange = useCallback(
		( value: string ) => {
			const nextTab: StatusTab =
				TABS.indexOf( value as StatusTab ) !== -1
					? ( value as StatusTab )
					: 'all';
			// A new tab starts at the first page.
			const nextView = { ...view, page: 1 };
			setTab( nextTab );
			setView( nextView );
			commitToUrl( nextView, nextTab );
		},
		[ commitToUrl, view ]
	);

	// Reconcile local state from the URL on external navigation (Back/Forward or
	// a deep link). The list's own writes already match the URL and are skipped
	// here — which also preserves an in-progress filter that has no value yet, as
	// that is not represented in the URL.
	useEffect( () => {
		if ( urlNav( searchParams ) === serializeNav( view, tab ) ) {
			return;
		}
		setView( ( previous ) => viewFromParams( searchParams, previous ) );
		setTab( getTab( searchParams ) );
	}, [ searchParams, view, tab ] );

	// Stop the enclosing WooCommerce settings form from submitting — for example
	// when Enter is pressed in the search box — which would reload the page to a
	// broken URL. The list persists through the REST API, never a form submit.
	useEffect( () => {
		const form = pageRef.current?.closest( 'form' );
		if ( ! form ) {
			return;
		}
		const preventSubmit = ( event: Event ) => event.preventDefault();
		form.addEventListener( 'submit', preventSubmit );
		return () => form.removeEventListener( 'submit', preventSubmit );
	}, [] );

	// A page past the last one (a stale link, or rows pruned since) would show a
	// confusing empty list, so fall back to the last existing page — or page 1
	// when there are no results at all. A failed request reports zero pages, so
	// skip the correction on error: it would drop the deep link and refetch.
	useEffect( () => {
		if ( isLoading || error ) {
			return;
		}

		const current = view.page ?? 1;
		const target = totalPages >= 1 ? Math.min( current, totalPages ) : 1;

		if ( target !== current ) {
			const nextView = { ...view, page: target };
			setView( nextView );
			commitToUrl( nextView, tab, true );
		}
	}, [ isLoading, error, totalPages, view, tab, commitToUrl ] );

	// The empty state depends on why the list is empty, so it does not claim
	// there were no attempts when a search, filter, or load error is the cause.
	let emptyMessage;
	if ( error ) {
		emptyMessage = __(
			'No checkout attempts to show.',
			'woocommerce-fraud-protection'
		);
	} else if (
		Boolean( view.search ) ||
		( view.filters?.length ?? 0 ) > 0 ||
		tab !== 'all'
	) {
		emptyMessage = __(
			'No checkout attempts match your search or filters.',
			'woocommerce-fraud-protection'
		);
	} else {
		emptyMessage = __(
			'No checkout attempts have been recorded in the last 30 days.',
			'woocommerce-fraud-protection'
		);
	}

	// The banner and list are shared across tabs; rendered into whichever tab
	// panel is active (see below) so the list the tab controls lives inside it.
	const listContent = (
		<>
			{ ! protectionOn && (
				<ProtectionOffBanner onEnable={ openEnableDrawer } />
			) }

			<DataViews< ( typeof sessions )[ number ] >
				data={ sessions }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ { totalItems, totalPages } }
				isLoading={ isLoading }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				searchLabel={ __(
					'Search by email or IP',
					'woocommerce-fraud-protection'
				) }
				empty={ <p>{ emptyMessage }</p> }
			/>
		</>
	);

	return (
		<div
			ref={ pageRef }
			className="wc-fraud-protection-checkout-attempts__page"
		>
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
						'See checkout attempts and how fraud prevention responded to them, including any that fraud prevention blocked before completing.',
						'woocommerce-fraud-protection'
					) }
				</p>
			</header>

			{ error && (
				<Notice.Root intent="error">
					<Notice.Description>{ error }</Notice.Description>
				</Notice.Root>
			) }

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
				{ /* Each tab controls its own panel (the accessibility contract),
				     but the list is the same across tabs, so it is rendered only
				     in the panel that is currently active. */ }
				<Tabs.Panel value="all">
					{ 'all' === tab && listContent }
				</Tabs.Panel>
				<Tabs.Panel value="allowed">
					{ 'allowed' === tab && listContent }
				</Tabs.Panel>
				<Tabs.Panel value="blocked">
					{ 'blocked' === tab && listContent }
				</Tabs.Panel>
			</Tabs.Root>

			<EnableFraudPreventionDrawer
				open={ isEnableDrawerOpen }
				onOpenChange={ setIsEnableDrawerOpen }
			/>
			<RuleFormDrawer
				open={ isRuleFormOpen }
				ruleId={ ruleId }
				context={ ruleFormContext }
				origin="checkout_attempts"
				onClose={ closeAttemptRuleForm }
				onSuccess={ refresh }
				onViewRule={ openAttemptRuleEdit }
			/>
			<RuleDeleteDialog
				rule={ deletingRule }
				origin="checkout_attempts"
				onClose={ () => setDeletingRule( undefined ) }
				onSuccess={ refresh }
			/>
		</div>
	);
}
