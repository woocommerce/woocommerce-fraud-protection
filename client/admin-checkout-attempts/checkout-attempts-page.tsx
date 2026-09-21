import { Notice, Stack, Tabs } from '@wordpress/ui';
import { useCallback, useMemo, useState } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';
import { DataViews } from '@wordpress/dataviews/wp';
import type { View } from '@wordpress/dataviews';
import { Link } from 'react-router-dom';

import { buildActions } from './actions';
import { EnableFraudPreventionDrawer } from './enable-fraud-prevention-drawer';
import {
	getFields,
	outcomeFilter,
	providerFilter,
	rulesFilter,
} from './fields';
import { ProtectionOffBanner } from './protection-off-banner';
import { createPreferenceStore } from '../persisted-state';
import { createListUrlCodec, useListState } from '../list-state';
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
const SUPPORTED_FIELDS = [ ...DEFAULT_FIELDS, 'rules' ];
const SUPPORTED_PER_PAGE = [ 10, 20, 50, 100 ];
const SUPPORTED_DENSITIES = [ 'compact', 'balanced', 'comfortable' ];
const STORAGE_KEY = 'wc-fraud-protection-checkout-attempts-prefs';
const STORAGE_VERSION = 2;
const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: DEFAULT_PER_PAGE,
	sort: {
		field: DEFAULT_SORT_FIELD,
		direction: DEFAULT_SORT_DIRECTION,
	},
	search: '',
	filters: [],
	titleField: 'payment_method',
	fields: DEFAULT_FIELDS,
};

const preferenceStore = createPreferenceStore( {
	storageKey: STORAGE_KEY,
	version: STORAGE_VERSION,
	supportedFields: SUPPORTED_FIELDS,
	supportedPerPage: SUPPORTED_PER_PAGE,
	supportedDensities: SUPPORTED_DENSITIES,
} );

type StatusTab = 'all' | FinalStatus;

const listCodec = createListUrlCodec< StatusTab >( {
	path: '/checkout-attempts',
	defaultView: DEFAULT_VIEW,
	filters: [ outcomeFilter, providerFilter, rulesFilter ],
	search: true,
	state: { param: 'status', defaultValue: 'all', values: TABS },
	queryOrder: [ 'search', 'page', 'sort', 'filters', 'state' ],
} );

function useCheckoutAttemptList( view: View, tab: StatusTab | undefined ) {
	return useCheckoutAttempts( view, TAB_TO_STATUS[ tab ?? 'all' ] );
}

export function CheckoutAttemptsPage() {
	const {
		pageRef,
		view,
		state: tab = 'all',
		onChangeView,
		onChangeState: onTabChange,
		result: { sessions, totalItems, totalPages, isLoading, error, refresh },
	} = useListState( {
		codec: listCodec,
		preferenceStore,
		policies: {
			replaceOnSearchOnly: true,
			resetPageOnStateChange: true,
		},
		useData: useCheckoutAttemptList,
	} );

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
	const paginationInfo = {
		totalItems,
		totalPages:
			isLoading || error
				? Math.max( totalPages, view.page ?? 1 )
				: totalPages,
	};

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
			<DataViews.Layout />
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

			{ /* The list is composed from the DataViews parts (as the rules page
			     is) so the status tabs share a row with the search, filters and
			     view options, with the filters bar and the table below. */ }
			<DataViews< ( typeof sessions )[ number ] >
				data={ sessions }
				fields={ fields }
				view={ view }
				onChangeView={ onChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				isLoading={ isLoading }
				defaultLayouts={ { table: {} } }
				getItemId={ ( item ) => String( item.id ) }
				empty={ <p>{ emptyMessage }</p> }
			>
				<Tabs.Root value={ tab } onValueChange={ onTabChange }>
					<Stack
						className="wc-fraud-protection-checkout-attempts__toolbar"
						direction="row"
						align="center"
						justify="space-between"
						gap="sm"
					>
						<Tabs.List variant="minimal">
							<Tabs.Tab value="all">
								{ __( 'All', 'woocommerce-fraud-protection' ) }
							</Tabs.Tab>
							<Tabs.Tab value="allowed">
								{ __(
									'Allowed',
									'woocommerce-fraud-protection'
								) }
							</Tabs.Tab>
							<Tabs.Tab value="blocked">
								{ __(
									'Blocked',
									'woocommerce-fraud-protection'
								) }
							</Tabs.Tab>
						</Tabs.List>
						<Stack
							className="wc-fraud-protection-checkout-attempts__view-controls"
							direction="row"
							align="center"
							gap="xs"
						>
							<DataViews.Search
								label={ __(
									'Search by email or IP',
									'woocommerce-fraud-protection'
								) }
							/>
							<DataViews.FiltersToggle />
							<DataViews.ViewConfig />
						</Stack>
					</Stack>
					<DataViews.FiltersToggled className="dataviews-filters__container" />
					{ /* Each tab controls its own panel (the accessibility
					     contract), but the list is the same across tabs, so it
					     is rendered only in the panel that is currently active. */ }
					<Tabs.Panel value="all">
						{ 'all' === tab && listContent }
					</Tabs.Panel>
					<Tabs.Panel value="allowed">
						{ 'allowed' === tab && listContent }
					</Tabs.Panel>
					<Tabs.Panel value="blocked">
						{ 'blocked' === tab && listContent }
					</Tabs.Panel>
					<DataViews.Footer />
				</Tabs.Root>
			</DataViews>

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
