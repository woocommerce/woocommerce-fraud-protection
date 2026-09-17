import '@testing-library/jest-dom';
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentType, ReactNode } from 'react';
import { unstable_HistoryRouter as HistoryRouter } from 'react-router-dom';
import { createMemoryHistory } from 'history';

import apiFetch from '@wordpress/api-fetch';
import {
	createReduxStore,
	createRegistry,
	dispatch as dataDispatch,
	register,
	RegistryProvider,
} from '@wordpress/data';
import type { View } from '@wordpress/dataviews';

import { buildActions } from '../../client/admin-checkout-attempts/actions';
import {
	formatDate,
	formatDateTime,
} from '../../client/admin-checkout-attempts/dates';
import { getFields } from '../../client/admin-checkout-attempts/fields';
import {
	getOutcomeLabel,
	getOutcomeOptions,
	OutcomeBadge,
} from '../../client/admin-checkout-attempts/outcomes';
import { getPaymentMethodElements } from '../../client/admin-checkout-attempts/payment-method-elements';
import { getFlaggedExplanation } from '../../client/admin-checkout-attempts/flagged-chip';
import { buildListPath } from '../../client/admin-checkout-attempts/use-checkout-attempts';
import {
	loadPrefs as loadStoredPrefs,
	savePrefs as saveStoredPrefs,
} from '../../client/persisted-state';
import { settingsStore } from '../../client/admin-settings/data/store';
import {
	rulesStore,
	type Rule,
} from '../../client/admin-settings/data/rules-store';
import type {
	CheckoutAttemptsConfig,
	RuleReference,
	Session,
} from '../../client/admin-checkout-attempts/types';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const mockUpdateUserPreferences = jest.fn();
const mockUseUserPreferences = jest.fn();

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () => mockUseUserPreferences(),
} ) );

// The enable drawer confirms a successful save with a snackbar through
// @wordpress/notices. Point that store at a spy so the toast is assertable; a
// matching core/notices store is registered below.
const mockCreateSuccessNotice = jest.fn();

jest.mock( '@wordpress/notices', () => ( {
	__esModule: true,
	store: { name: 'core/notices' },
} ) );

// The list lives inside the settings single-page app. `getHistory()` and the
// router share one in-memory history (reset per test in renderPage) so a
// `getHistory().push()` is what `useSearchParams()` reads back. `getNewPath()`
// mirrors WooCommerce's builder: `admin.php` with a `path` query arg.
let mockHistory: ReturnType< typeof createMemoryHistory >;

jest.mock( '@woocommerce/navigation', () => ( {
	__esModule: true,
	getHistory: () => mockHistory,
	getNewPath: (
		query: Record< string, string >,
		path: string,
		currentQuery: Record< string, string > = {}
	) => {
		const params = new URLSearchParams( {
			page: 'wc-admin',
			...currentQuery,
			...query,
		} );
		if ( path && path !== '/' ) {
			params.set( 'path', path );
		}
		return `/wp-admin/admin.php?${ params.toString() }`;
	},
} ) );

import { CheckoutAttemptsPage } from '../../client/admin-checkout-attempts/checkout-attempts-page';

const mockedApiFetch = apiFetch as unknown as jest.Mock;
const PREFS_STORAGE_KEY = 'wc-fraud-protection-checkout-attempts-prefs';
const PREFS_STORAGE_VERSION = 2;
const loadPrefs = () =>
	loadStoredPrefs( PREFS_STORAGE_KEY, PREFS_STORAGE_VERSION );
const savePrefs = ( prefs: Parameters< typeof saveStoredPrefs >[ 2 ] ) =>
	saveStoredPrefs( PREFS_STORAGE_KEY, PREFS_STORAGE_VERSION, prefs );

// Register the spied core/notices store into the default registry the page and
// drawer use (they read data through the global @wordpress/data registry, not a
// per-test one).
register(
	createReduxStore( 'core/notices', {
		reducer: ( state = null ) => state,
		actions: {
			createSuccessNotice: (
				content: string,
				options: { type: 'snackbar' }
			) => {
				mockCreateSuccessNotice( content, options );
				return { type: 'CREATE_SUCCESS_NOTICE' };
			},
		},
	} )
);

if ( ! window.PointerEvent ) {
	Object.defineProperty( window, 'PointerEvent', {
		configurable: true,
		writable: true,
		value: MouseEvent,
	} );
}

// Base UI's Tabs observe their size; jsdom provides no ResizeObserver.
if ( ! window.ResizeObserver ) {
	window.ResizeObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
}

const aSession = ( overrides: Partial< Session > = {} ): Session => ( {
	id: 1,
	recorded_at_gmt: '2026-04-22T09:23:00',
	payment_method: { id: 'stripe', title: 'Stripe', icon: null },
	email: 'shopper@example.com',
	ip: '203.0.113.9',
	ip_country: { code: 'US', name: 'United States' },
	billing_address: {
		country: { code: 'US', name: 'United States' },
		city: 'San Francisco',
		postcode: '94110',
	},
	final_status: 'allowed',
	outcome: 'allowed',
	order_id: null,
	rules: { email: null, ip: null },
	...overrides,
} );

const aRule = (
	overrides: Partial< NonNullable< RuleReference > > = {}
): NonNullable< RuleReference > => ( {
	id: 1,
	action: 'block',
	created_at: '2026-04-01T10:00:00',
	updated_at: null,
	...overrides,
} );

const listResponse = (
	sessions: Session[],
	total = sessions.length,
	totalPages = 1
) => ( {
	json: () => Promise.resolve( sessions ),
	headers: {
		get: ( name: string ) => {
			if ( name === 'X-WP-Total' ) {
				return String( total );
			}
			if ( name === 'X-WP-TotalPages' ) {
				return String( totalPages );
			}
			return null;
		},
	},
} );

const settledPaths = () =>
	mockedApiFetch.mock.calls.map( ( [ options ] ) => options.path as string );

const BASE_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 20,
	sort: { field: 'recorded_at', direction: 'desc' },
	search: '',
	filters: [],
};

describe( 'checkout attempts outcomes', () => {
	it( 'labels every outcome and exposes them as filter options', () => {
		expect( getOutcomeLabel( 'flagged_by_fraud_prevention' ) ).toBe(
			'Allowed, flagged'
		);
		expect( getOutcomeLabel( 'blocked_by_rules' ) ).toBe(
			'Blocked by rules'
		);
		expect( getOutcomeLabel( 'blocked_automatically' ) ).toBe( 'Blocked' );

		const options = getOutcomeOptions();
		expect( options ).toHaveLength( 5 );
		expect( options.map( ( option ) => option.value ) ).toEqual(
			expect.arrayContaining( [
				'allowed',
				'allowed_by_rules',
				'flagged_by_fraud_prevention',
				'blocked_automatically',
				'blocked_by_rules',
			] )
		);
	} );

	it( 'explains an automatic block with an info control', async () => {
		render( <OutcomeBadge outcome="blocked_automatically" /> );
		expect( screen.getByText( 'Blocked' ) ).toBeInTheDocument();
		const infoControl = screen.getByRole( 'button', {
			name: 'Why was this blocked?',
		} );
		await userEvent.tab();
		expect( infoControl ).toHaveFocus();
		await userEvent.keyboard( '{Enter}' );
		expect(
			await screen.findByText(
				'Blocked automatically by fraud prevention.'
			)
		).toBeInTheDocument();
	} );
} );

describe( 'checkout attempts dates', () => {
	// The test process runs in America/New_York (see jest-global-setup), which
	// is four hours behind UTC in April, while the date settings default to a
	// UTC site. The recorded GMT values must render in the browser's zone.
	it( 'renders the recorded time in the browser time zone', () => {
		expect( formatDateTime( '2026-04-22T09:23:00' ) ).toBe(
			'Apr 22, 2026 5:23 am'
		);
		// Crossing midnight moves the date too.
		expect( formatDateTime( '2026-04-22T02:30:00' ) ).toBe(
			'Apr 21, 2026 10:30 pm'
		);
	} );

	it( 'renders tooltip dates in the browser time zone without the time', () => {
		expect( formatDate( '2026-04-01T10:00:00' ) ).toBe( 'Apr 1, 2026' );
		expect( formatDate( '2026-04-01T02:30:00' ) ).toBe( 'Mar 31, 2026' );
	} );

	it( 'formats the Date and time column with the browser time zone', () => {
		const field = getFields( {
			automaticProtection: false,
			automaticProtectionEnabledAt: null,
			settingsUrl: '',
		} ).find( ( candidate ) => candidate.id === 'recorded_at' );
		const Render = field!.render as unknown as ComponentType< {
			item: Session;
		} >;
		render( <Render item={ aSession() } /> );

		expect(
			screen.getByText( 'Apr 22, 2026 5:23 am' )
		).toBeInTheDocument();
	} );
} );

describe( 'checkout attempts provider filter', () => {
	const config: CheckoutAttemptsConfig = {
		automaticProtection: false,
		automaticProtectionEnabledAt: null,
		settingsUrl: '',
	};

	it( 'lets DataViews load the provider options', () => {
		const field = getFields( config ).find(
			( candidate ) => candidate.id === 'payment_method'
		);

		expect( field?.getElements ).toBe( getPaymentMethodElements );
		expect( field?.elements ).toBeUndefined();
	} );

	it( 'resolves REST payment methods to DataViews options', async () => {
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( [
			{ id: 'bacs', title: 'Direct bank transfer' },
			{ id: 'cod', title: '' },
		] );

		await expect( getPaymentMethodElements() ).resolves.toEqual( [
			{ value: 'bacs', label: 'Direct bank transfer' },
			{ value: 'cod', label: 'cod' },
		] );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/sessions/payment-methods',
		} );
	} );
} );

describe( 'checkout attempts row actions', () => {
	const actionsConfig: CheckoutAttemptsConfig = {
		automaticProtection: false,
		automaticProtectionEnabledAt: null,
		settingsUrl: 'https://example.test/wp-admin/settings',
	};

	const noopEnable = () => {};
	const runAction = (
		actionId: string,
		session: Session,
		callbacks: Parameters< typeof buildActions >[ 2 ]
	) => {
		const action = buildActions(
			actionsConfig,
			noopEnable,
			callbacks
		).find( ( candidate ) => candidate.id === actionId )!;
		const { callback } = action as unknown as {
			callback: ( items: Session[] ) => void;
		};
		callback( [ session ] );
	};

	const eligibleIds = ( session: Session ) =>
		buildActions( actionsConfig, noopEnable )
			.filter( ( action ) => action.isEligible?.( session ) ?? true )
			.map( ( action ) => action.id );

	const labelFor = ( actionId: string, session: Session ): string => {
		const action = buildActions( actionsConfig, noopEnable ).find(
			( a ) => a.id === actionId
		)!;
		return typeof action.label === 'string'
			? action.label
			: action.label( [ session ] );
	};

	it( 'offers a block action for an allowed attempt with no matching rule', () => {
		const ids = eligibleIds( aSession( { final_status: 'allowed' } ) );

		expect( ids ).toContain( 'email-block' );
		expect( ids ).toContain( 'ip-block' );
		expect( ids ).not.toContain( 'email-allow' );
		expect( ids ).not.toContain( 'email-edit' );
	} );

	it( 'offers an allow action for a blocked attempt with no matching rule', () => {
		const ids = eligibleIds(
			aSession( {
				final_status: 'blocked',
				outcome: 'blocked_automatically',
			} )
		);

		expect( ids ).toContain( 'email-allow' );
		expect( ids ).toContain( 'ip-allow' );
		expect( ids ).not.toContain( 'email-block' );
	} );

	it( 'offers edit and delete when a rule already targets the value', () => {
		const ids = eligibleIds(
			aSession( {
				rules: { email: aRule( { id: 7, action: 'block' } ), ip: null },
			} )
		);

		expect( ids ).toContain( 'email-edit' );
		expect( ids ).toContain( 'email-delete' );
		expect( ids ).not.toContain( 'email-block' );
		expect( ids ).toContain( 'ip-block' );
	} );

	it( 'names edit and delete actions after the value they target, not the rule type', () => {
		// An allow rule on the email and a block rule on the IP: the labels no
		// longer mention allow/block, only the value.
		const withRules = aSession( {
			outcome: 'allowed_by_rules',
			final_status: 'allowed',
			rules: {
				email: aRule( { id: 1, action: 'allow' } ),
				ip: aRule( { id: 2, action: 'block' } ),
			},
		} );

		expect( labelFor( 'email-edit', withRules ) ).toBe(
			'Edit email address rule'
		);
		expect( labelFor( 'email-delete', withRules ) ).toBe(
			'Delete email address rule'
		);
		expect( labelFor( 'ip-edit', withRules ) ).toBe(
			'Edit IP address rule'
		);
		expect( labelFor( 'ip-delete', withRules ) ).toBe(
			'Delete IP address rule'
		);
	} );

	it( 'omits value actions when the value is absent', () => {
		const ids = eligibleIds( aSession( { email: null } ) );

		expect( ids ).not.toContain( 'email-block' );
		expect( ids ).not.toContain( 'email-allow' );
		expect( ids ).toContain( 'ip-block' );
	} );

	it( 'offers turning on automatic fraud prevention for a flagged attempt while it is off', () => {
		const flagged = aSession( { outcome: 'flagged_by_fraud_prevention' } );

		expect( eligibleIds( flagged ) ).toContain(
			'enable-automatic-protection'
		);
		// Not for a plain allowed attempt...
		expect( eligibleIds( aSession() ) ).not.toContain(
			'enable-automatic-protection'
		);
		// ...nor once automatic fraud prevention is already on.
		const whenOn = buildActions(
			{
				...actionsConfig,
				automaticProtection: true,
			},
			noopEnable
		)
			.filter( ( action ) => action.isEligible?.( flagged ) ?? true )
			.map( ( action ) => action.id );
		expect( whenOn ).not.toContain( 'enable-automatic-protection' );
	} );

	it( 'leads the menu with the automatic-protection shortcut', () => {
		expect( buildActions( actionsConfig, noopEnable )[ 0 ].id ).toBe(
			'enable-automatic-protection'
		);
	} );

	it( 'renders the automatic-protection shortcut with the accent label', () => {
		const action = buildActions( actionsConfig, noopEnable ).find(
			( a ) => a.id === 'enable-automatic-protection'
		)!;
		// The label is a function returning a node (see actions.tsx); render it
		// to confirm the wording and the accent class the CSS styles.
		const label =
			typeof action.label === 'function'
				? action.label( [] )
				: action.label;
		const { container } = render( <>{ label }</> );

		expect(
			screen.getByText( 'Turn on automatic fraud prevention' )
		).toBeInTheDocument();
		expect(
			container.querySelector(
				'.wc-fraud-protection-checkout-attempts__menu-link'
			)
		).toBeInTheDocument();
	} );

	it( 'runs the enable callback when the shortcut is chosen', () => {
		const onEnable = jest.fn();
		const action = buildActions( actionsConfig, onEnable ).find(
			( a ) => a.id === 'enable-automatic-protection'
		)!;

		( action as unknown as { callback: () => void } ).callback();

		expect( onEnable ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'passes the selected attempt and value type to create actions', () => {
		const allowed = aSession( { id: 27 } );
		const blocked = aSession( {
			id: 28,
			final_status: 'blocked',
			outcome: 'blocked_automatically',
		} );
		const callbacks = {
			onCreateRule: jest.fn(),
			onEditRule: jest.fn(),
			onDeleteRule: jest.fn(),
		};

		runAction( 'email-block', allowed, callbacks );
		runAction( 'ip-block', allowed, callbacks );
		runAction( 'email-allow', blocked, callbacks );
		runAction( 'ip-allow', blocked, callbacks );

		expect( callbacks.onCreateRule ).toHaveBeenNthCalledWith(
			1,
			allowed,
			'email'
		);
		expect( callbacks.onCreateRule ).toHaveBeenNthCalledWith(
			2,
			allowed,
			'ip'
		);
		expect( callbacks.onCreateRule ).toHaveBeenNthCalledWith(
			3,
			blocked,
			'email'
		);
		expect( callbacks.onCreateRule ).toHaveBeenNthCalledWith(
			4,
			blocked,
			'ip'
		);
	} );

	it( 'passes the matching rule ID and value type to edit and delete actions', () => {
		const session = aSession( {
			rules: {
				email: aRule( { id: 71 } ),
				ip: aRule( { id: 82 } ),
			},
		} );
		const callbacks = {
			onCreateRule: jest.fn(),
			onEditRule: jest.fn(),
			onDeleteRule: jest.fn(),
		};

		runAction( 'email-edit', session, callbacks );
		runAction( 'ip-edit', session, callbacks );
		runAction( 'email-delete', session, callbacks );
		runAction( 'ip-delete', session, callbacks );

		expect( callbacks.onEditRule ).toHaveBeenNthCalledWith( 1, 71 );
		expect( callbacks.onEditRule ).toHaveBeenNthCalledWith( 2, 82 );
		expect( callbacks.onDeleteRule ).toHaveBeenNthCalledWith(
			1,
			session,
			'email'
		);
		expect( callbacks.onDeleteRule ).toHaveBeenNthCalledWith(
			2,
			session,
			'ip'
		);
	} );
} );

describe( 'checkout attempts status field', () => {
	const config: CheckoutAttemptsConfig = {
		automaticProtection: false,
		automaticProtectionEnabledAt: null,
		settingsUrl: 'https://example.test/wp-admin/settings',
	};

	const renderField = (
		id: string,
		item: Session,
		cfg = config,
		compact = false
	) => {
		const field = getFields( cfg, compact ).find( ( f ) => f.id === id );
		const Render = field!.render as unknown as ComponentType< {
			item: Session;
		} >;
		return render( <Render item={ item } /> );
	};

	const CHIP = '.wc-fraud-protection-checkout-attempts__rule-chip';
	const VALUE = '.wc-fraud-protection-checkout-attempts__value';

	const flaggedInfo = () =>
		screen.getByRole( 'button', { name: 'Why was this flagged?' } );

	it( 'shows a flagged attempt as Allowed and Flagged with an info control', () => {
		renderField(
			'outcome',
			aSession( { outcome: 'flagged_by_fraud_prevention' } )
		);

		expect( screen.getByText( 'Allowed' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Flagged' ) ).toBeInTheDocument();
		expect( flaggedInfo() ).toBeInTheDocument();
	} );

	it( 'builds the explanation and enable link while protection is off', () => {
		const { container } = render(
			<>
				{ getFlaggedExplanation( {
					protectionOn: false,
					enabledAt: null,
					settingsUrl: config.settingsUrl,
				} ) }
			</>
		);

		expect( container ).toHaveTextContent(
			'Flagged as suspicious but allowed because automatic fraud prevention is off. Enable automatic fraud prevention.'
		);
		expect(
			screen.getByText( /because automatic fraud prevention is off/ )
		).toBeInTheDocument();
		const link = screen.getByRole( 'link', {
			name: 'Enable automatic fraud prevention',
		} );
		expect( link ).toHaveAttribute( 'href', config.settingsUrl );
		// The settings page opens in a new tab.
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute( 'rel', 'noopener noreferrer' );
	} );

	it( 'builds the enable-date explanation once protection is on', () => {
		const { container } = render(
			<>
				{ getFlaggedExplanation( {
					protectionOn: true,
					enabledAt: '2026-04-20T12:00:00',
					settingsUrl: config.settingsUrl,
				} ) }
			</>
		);

		expect( container ).toHaveTextContent(
			'Flagged as suspicious but allowed because automatic protection was off. Enabled Apr 20, 2026.'
		);
		// No enable link once protection is on.
		expect(
			screen.queryByRole( 'link', {
				name: 'Enable automatic fraud prevention',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'labels a block rule on the email value', () => {
		renderField(
			'email',
			aSession( {
				rules: { email: aRule( { id: 7, action: 'block' } ), ip: null },
			} )
		);

		expect( screen.getByText( 'Block rule' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Allow rule' ) ).not.toBeInTheDocument();
	} );

	it( 'labels an allow rule on the IP value', () => {
		renderField(
			'ip',
			aSession( {
				rules: { email: null, ip: aRule( { id: 3, action: 'allow' } ) },
			} )
		);

		expect( screen.getByText( 'Allow rule' ) ).toBeInTheDocument();
	} );

	it( 'uses the visible rule label as part of the tooltip trigger', () => {
		renderField(
			'email',
			aSession( {
				rules: {
					email: aRule( {
						id: 7,
						action: 'block',
						created_at: '2026-04-01T10:00:00',
					} ),
					ip: null,
				},
			} )
		);

		const label = screen.getByText( 'Block rule' );
		expect( label.closest( 'button' ) ).toHaveAccessibleName(
			/Block rule created/
		);
	} );

	it( 'keeps the rule type in the compact trigger name', () => {
		renderField(
			'email',
			aSession( {
				rules: {
					email: aRule( {
						id: 7,
						action: 'block',
						created_at: '2026-04-01T10:00:00',
					} ),
					ip: null,
				},
			} ),
			config,
			true
		);

		expect(
			screen.getByRole( 'button', { name: /Block rule created/ } )
		).toBeInTheDocument();
	} );

	it( 'shows the provider title as text, without a logo', () => {
		const { container } = renderField(
			'payment_method',
			aSession( {
				payment_method: {
					id: 'stripe',
					title: 'Stripe',
					icon: 'https://example.test/stripe.svg',
				},
			} )
		);

		expect( screen.getByText( 'Stripe' ) ).toBeInTheDocument();
		expect( container.querySelector( 'img' ) ).toBeNull();
	} );

	it( 'renders a labeled chip in the default density', () => {
		const { container } = renderField(
			'email',
			aSession( {
				rules: { email: aRule( { id: 1, action: 'block' } ), ip: null },
			} )
		);

		expect( container.querySelector( CHIP ) ).not.toHaveClass(
			'is-icon-only'
		);
		// Default density lets the chip wrap below the value.
		expect( container.querySelector( VALUE ) ).not.toHaveClass(
			'is-compact'
		);
	} );

	it( 'renders an icon-only chip in compact density, keeping the label for AT', () => {
		const { container } = renderField(
			'email',
			aSession( {
				rules: { email: aRule( { id: 1, action: 'block' } ), ip: null },
			} ),
			config,
			true
		);

		expect( container.querySelector( CHIP ) ).toHaveClass( 'is-icon-only' );
		// Compact keeps the chip on the same line, to the right of the value.
		expect( container.querySelector( VALUE ) ).toHaveClass( 'is-compact' );
		// The label stays in the DOM (visually hidden) for screen readers.
		expect( screen.getByText( 'Block rule' ) ).toBeInTheDocument();
	} );

	it( 'shows no rule chip when no rule targets the value', () => {
		renderField( 'email', aSession() );

		expect( screen.queryByText( 'Allow rule' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'Block rule' ) ).not.toBeInTheDocument();
	} );

	it( 'shows each side of an allow-email / block-IP conflict on its own value', () => {
		const conflicted = aSession( {
			outcome: 'allowed_by_rules',
			final_status: 'allowed',
			rules: {
				email: aRule( { id: 1, action: 'allow' } ),
				ip: aRule( { id: 2, action: 'block' } ),
			},
		} );

		const email = renderField( 'email', conflicted );
		expect(
			within( email.container ).getByText( 'Allow rule' )
		).toBeInTheDocument();
		expect(
			within( email.container ).queryByText( 'Block rule' )
		).not.toBeInTheDocument();

		const ip = renderField( 'ip', conflicted );
		expect(
			within( ip.container ).getByText( 'Block rule' )
		).toBeInTheDocument();
		expect(
			within( ip.container ).queryByText( 'Allow rule' )
		).not.toBeInTheDocument();
	} );
} );

describe( 'checkout attempts rules filter field', () => {
	const config: CheckoutAttemptsConfig = {
		automaticProtection: false,
		automaticProtectionEnabledAt: null,
		settingsUrl: '',
	};
	const rulesField = () =>
		getFields( config ).find( ( field ) => field.id === 'rules' )!;

	it( 'is a single-select (radio) filter with with/without options', () => {
		const field = rulesField();

		expect( field.filterBy ).toEqual( { operators: [ 'is' ] } );
		expect( field.elements?.map( ( option ) => option.value ) ).toEqual( [
			'with',
			'without',
		] );
	} );

	it( 'derives its value from whether a rule targets the row', () => {
		const field = rulesField();

		expect(
			field.getValue!( {
				item: aSession( {
					rules: {
						email: aRule( { id: 1, action: 'block' } ),
						ip: null,
					},
				} ),
			} )
		).toBe( 'with' );
		expect( field.getValue!( { item: aSession() } ) ).toBe( 'without' );
	} );
} );

describe( 'checkout attempts list path', () => {
	it( 'maps the view and status tab to query arguments', () => {
		const path = buildListPath(
			{
				...BASE_VIEW,
				page: 2,
				perPage: 50,
				sort: { field: 'email', direction: 'asc' },
				search: 'fraud',
				filters: [
					{
						field: 'outcome',
						operator: 'isAny',
						value: [ 'blocked_by_rules' ],
					},
					{
						field: 'payment_method',
						operator: 'isAny',
						value: [ 'stripe' ],
					},
				],
			},
			'blocked'
		);

		expect( path ).toContain( 'page=2' );
		expect( path ).toContain( 'per_page=50' );
		expect( path ).toContain( 'orderby=email' );
		expect( path ).toContain( 'order=asc' );
		expect( path ).toContain( 'search=fraud' );
		expect( path ).toContain( 'final_status=blocked' );
		expect( path ).toContain( 'outcome%5B0%5D=blocked_by_rules' );
		expect( path ).toContain( 'payment_method%5B0%5D=stripe' );
	} );

	it( 'omits the status filter for the all tab', () => {
		expect( buildListPath( BASE_VIEW, null ) ).not.toContain(
			'final_status'
		);
	} );

	it( 'maps the rules filter to the query, and omits it when unset', () => {
		expect( buildListPath( BASE_VIEW, null ) ).not.toContain( 'rules=' );

		const path = buildListPath(
			{
				...BASE_VIEW,
				filters: [
					{ field: 'rules', operator: 'is', value: 'without' },
				],
			},
			null
		);
		expect( path ).toContain( 'rules=without' );
	} );
} );

describe( 'checkout attempts display preferences', () => {
	beforeEach( () => window.localStorage.clear() );

	it( 'returns empty when nothing is stored', () => {
		expect( loadPrefs() ).toEqual( {} );
	} );

	it( 'round-trips fields, page size and layout', () => {
		savePrefs( {
			fields: [ 'email', 'ip' ],
			perPage: 50,
			layout: { density: 'compact' },
		} );

		expect( loadPrefs() ).toEqual( {
			fields: [ 'email', 'ip' ],
			perPage: 50,
			layout: { density: 'compact' },
		} );
	} );

	it( 'falls back to empty when the payload is corrupt or a stale version', () => {
		window.localStorage.setItem( PREFS_STORAGE_KEY, 'not json' );
		expect( loadPrefs() ).toEqual( {} );

		window.localStorage.setItem(
			PREFS_STORAGE_KEY,
			JSON.stringify( { version: 999, prefs: { perPage: 50 } } )
		);
		expect( loadPrefs() ).toEqual( {} );
	} );

	it( 'drops invalid preference values', () => {
		// A payload at the current version but with the wrong value types.
		window.localStorage.setItem(
			PREFS_STORAGE_KEY,
			JSON.stringify( {
				version: 2,
				prefs: { fields: 'nope', perPage: -3 },
			} )
		);

		expect( loadPrefs() ).toEqual( {} );
	} );

	it( 'ignores unavailable storage', () => {
		const getItem = jest
			.spyOn( Storage.prototype, 'getItem' )
			.mockImplementation( () => {
				throw new Error( 'Storage unavailable.' );
			} );
		expect( loadPrefs() ).toEqual( {} );
		getItem.mockRestore();

		const setItem = jest
			.spyOn( Storage.prototype, 'setItem' )
			.mockImplementation( () => {
				throw new Error( 'Storage unavailable.' );
			} );
		expect( () => savePrefs( { perPage: 50 } ) ).not.toThrow();
		setItem.mockRestore();
	} );
} );

const settingsResponse = ( automaticProtection = false ) => ( {
	automatic_protection: automaticProtection,
	automatic_protection_opted_out: true,
	automatic_protection_enabled_at: automaticProtection
		? '2026-04-20T00:00:00'
		: null,
	performance: {
		flagged_by_fraud_prevention: 0,
		blocked_automatically: 0,
		allowed_by_rules: 0,
		blocked_by_rules: 0,
	},
} );

type ListResponse = ReturnType< typeof listResponse >;

// Route apiFetch by path: the paginated sessions list (read with parse:false)
// and the settings GET/POST the store uses.
const mockApi = ( {
	sessions,
	onPost,
}: {
	sessions?: ListResponse | ( () => ListResponse | Promise< ListResponse > );
	onPost?: ( value: boolean ) => void;
} = {} ) => {
	const nextSessions =
		typeof sessions === 'function'
			? sessions
			: () => sessions ?? listResponse( [], 0 );

	mockedApiFetch.mockImplementation(
		( options: {
			path: string;
			method?: string;
			data?: { automatic_protection?: boolean };
		} ) => {
			const path = String( options.path );
			if ( path.includes( '/wc-fraud-protection/v1/sessions' ) ) {
				return Promise.resolve( nextSessions() );
			}
			if ( path.includes( '/wc-fraud-protection/v1/settings' ) ) {
				if ( 'POST' === options.method ) {
					const value = options.data?.automatic_protection ?? false;
					onPost?.( value );
					return Promise.resolve( settingsResponse( value ) );
				}
				return Promise.resolve( settingsResponse() );
			}
			return Promise.resolve( undefined );
		}
	);
};

const fullRule = ( overrides: Partial< Rule > = {} ): Rule => ( {
	id: 90,
	action: 'block',
	type: 'email',
	value: 'shopper@example.com',
	created_at: '2026-04-01T10:00:00',
	updated_at: null,
	...overrides,
} );

const mockRuleApi = ( {
	before,
	after = before,
	createError,
	details = {},
}: {
	before: Session[];
	after?: Session[];
	createError?: unknown;
	details?: Record< number, Rule >;
} ) => {
	let mutated = false;
	mockedApiFetch.mockImplementation(
		( options: {
			path: string;
			method?: string;
			data?: Partial< Rule > & { automatic_protection?: boolean };
		} ) => {
			const path = String( options.path );
			if ( path.includes( '/wc-fraud-protection/v1/sessions' ) ) {
				return Promise.resolve(
					listResponse( mutated ? after : before )
				);
			}
			if ( path.includes( '/wc-fraud-protection/v1/settings' ) ) {
				return Promise.resolve( settingsResponse() );
			}
			if ( path === '/wc-fraud-protection/v1/rules' ) {
				if ( createError ) {
					return Promise.reject( createError );
				}
				mutated = true;
				return Promise.resolve(
					fullRule( {
						id: 91,
						action: options.data?.action,
						type: options.data?.type,
						value: options.data?.value,
					} )
				);
			}
			const ruleMatch = path.match(
				/\/wc-fraud-protection\/v1\/rules\/(\d+)/
			);
			if ( ruleMatch ) {
				const id = Number( ruleMatch[ 1 ] );
				if ( options.method === 'DELETE' ) {
					mutated = true;
					return Promise.resolve( undefined );
				}
				if ( options.method === 'PUT' ) {
					mutated = true;
					return Promise.resolve(
						fullRule( {
							...details[ id ],
							...options.data,
							id,
						} )
					);
				}
				return Promise.resolve( details[ id ] );
			}
			return Promise.resolve( undefined );
		}
	);
};

// The list's navigation state lives in the URL. Seed the shared history with the
// admin URL (an optional query string under test) and render inside a router
// bound to that same history.
const renderPage = ( search = '' ) => {
	mockHistory = createMemoryHistory( {
		initialEntries: [ `/wp-admin/admin.php?${ search }` ],
	} );
	return render( <CheckoutAttemptsPage />, {
		wrapper: ( { children }: { children: ReactNode } ) => (
			<HistoryRouter history={ mockHistory }>{ children }</HistoryRouter>
		),
	} );
};

// Only the paginated list requests, which carry query arguments.
const listPaths = () =>
	settledPaths().filter( ( path ) => path.includes( '/sessions?' ) );

const ruleMutationRequests = () =>
	mockedApiFetch.mock.calls
		.map( ( [ options ] ) => options )
		.filter(
			( options: { method?: string; path?: string } ) =>
				String( options.path ).includes(
					'/wc-fraud-protection/v1/rules'
				) &&
				[ 'POST', 'PUT', 'DELETE' ].indexOf( options.method ?? '' ) !==
					-1
		);

const openAttemptActions = async ( value: string ) => {
	const row = ( await screen.findByText( value ) ).closest( 'tr' );
	if ( ! row ) {
		throw new Error( `Checkout-attempt row not found for ${ value }.` );
	}
	fireEvent.mouseDown(
		within( row ).getByRole( 'button', { name: 'Actions' } )
	);
};

const chooseAttemptAction = async ( value: string, actionLabel: string ) => {
	await openAttemptActions( value );
	await userEvent.click(
		await screen.findByRole( 'menuitem', { name: actionLabel } )
	);
};

// The list reads automatic-protection state from the settings store; seed it to
// a known value and mark it resolved so the resolver does not also fetch.
const seedProtection = ( on: boolean, enabledAt: string | null = null ) => {
	const store = dataDispatch( settingsStore ) as unknown as {
		receiveSettings: ( settings: {
			automatic_protection: boolean;
			automatic_protection_opted_out: boolean;
			automatic_protection_enabled_at: string | null;
		} ) => void;
		finishResolution: ( selector: string, args: unknown[] ) => void;
		setError: ( error: null ) => void;
	};
	store.receiveSettings( {
		automatic_protection: on,
		automatic_protection_opted_out: true,
		automatic_protection_enabled_at: enabledAt,
	} );
	store.finishResolution( 'getSettings', [] );
	store.setError( null );
};

describe( 'CheckoutAttemptsPage', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockCreateSuccessNotice.mockReset();
		mockUpdateUserPreferences.mockReset();
		mockUpdateUserPreferences.mockResolvedValue( {} );
		mockUseUserPreferences.mockReturnValue( {
			isRequesting: false,
			updateUserPreferences: mockUpdateUserPreferences,
		} );
		window.localStorage.clear();
		seedProtection( false );
		mockApi();
	} );

	it( 'renders the header, search control, and loaded rows', async () => {
		const sessions = [
			aSession(),
			aSession( { id: 2, outcome: 'blocked_by_rules' } ),
		];
		mockApi( { sessions: listResponse( sessions, 2 ) } );

		renderPage();

		expect(
			screen.getByText(
				'See checkout attempts and how fraud prevention responded to them, including any that fraud prevention blocked before completing.'
			)
		).toBeInTheDocument();

		expect(
			await screen.findAllByText( 'shopper@example.com' )
		).toHaveLength( 2 );
		expect(
			screen.getByRole( 'searchbox', { name: 'Search by email or IP' } )
		).toBeInTheDocument();
		expect( listPaths()[ 0 ] ).toContain(
			'/wc-fraud-protection/v1/sessions'
		);
		expect( listPaths()[ 0 ] ).not.toContain( 'final_status' );
	} );

	it( 'opens contextual create drawers for email and IP with the inverse action', async () => {
		const allowed = aSession( { id: 21 } );
		const blocked = aSession( {
			id: 22,
			email: 'blocked@example.com',
			ip: '198.51.100.22',
			final_status: 'blocked',
			outcome: 'blocked_automatically',
		} );
		mockRuleApi( { before: [ allowed, blocked ] } );

		renderPage();
		await chooseAttemptAction( allowed.email!, 'Block this email address' );
		expect(
			await screen.findByRole( 'dialog', { name: 'Create rule' } )
		).toBeVisible();
		expect( screen.getByLabelText( 'Action' ) ).toHaveValue( 'block' );
		expect( screen.getByLabelText( 'Rule type' ) ).toHaveValue( 'email' );
		expect( screen.getByLabelText( 'Value' ) ).toHaveValue( allowed.email );
		expect( ruleMutationRequests() ).toHaveLength( 0 );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Close' } )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Create rule' } )
			).not.toBeInTheDocument()
		);

		await chooseAttemptAction( blocked.ip!, 'Allow this IP address' );
		expect(
			await screen.findByRole( 'dialog', { name: 'Create rule' } )
		).toBeVisible();
		expect( screen.getByLabelText( 'Action' ) ).toHaveValue( 'allow' );
		expect( screen.getByLabelText( 'Rule type' ) ).toHaveValue( 'ip' );
		expect( screen.getByLabelText( 'Value' ) ).toHaveValue( blocked.ip );
		expect( ruleMutationRequests() ).toHaveLength( 0 );
	} );

	it( 'creates a contextual rule, closes the drawer, refreshes row actions, and preserves list state', async () => {
		const attempt = aSession( { id: 31 } );
		const refreshedAttempt = aSession( {
			id: 31,
			rules: { email: aRule( { id: 91 } ), ip: null },
		} );
		savePrefs( {
			fields: [ 'email', 'outcome' ],
			perPage: 50,
			layout: { density: 'compact' },
		} );
		mockRuleApi( { before: [ attempt ], after: [ refreshedAttempt ] } );

		renderPage(
			'status=allowed&search=shopper&outcome=allowed&provider=stripe'
		);
		await screen.findByText( attempt.email! );
		const searchBeforeSave = mockHistory.location.search;

		await chooseAttemptAction( attempt.email!, 'Block this email address' );
		expect( ruleMutationRequests() ).toHaveLength( 0 );
		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Create rule' } )
		);

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/rules',
				method: 'POST',
				data: {
					action: 'block',
					type: 'email',
					value: 'shopper@example.com',
					recorded_attempt_id: 31,
					origin: 'checkout_attempts',
				},
			} )
		);
		expect( ruleMutationRequests() ).toHaveLength( 1 );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Create rule' } )
			).not.toBeInTheDocument()
		);
		expect( mockHistory.location.search ).toBe( searchBeforeSave );
		expect(
			screen.getByRole( 'tab', { name: 'Allowed', selected: true } )
		).toBeInTheDocument();
		await openAttemptActions( refreshedAttempt.email! );
		expect(
			await screen.findByRole( 'menuitem', {
				name: 'Edit email address rule',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'menuitem', {
				name: 'Delete email address rule',
			} )
		).toBeInTheDocument();
		await userEvent.keyboard( '{Escape}' );
		expect(
			screen.getByRole( 'button', { name: 'Customer email' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Outcome' } )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'IP' } )
		).not.toBeInTheDocument();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'View options' } )
		);
		expect(
			await screen.findByRole( 'radio', { name: 'Compact' } )
		).toBeChecked();
		expect( screen.getByRole( 'radio', { name: '50' } ) ).toBeChecked();
		expect( listPaths().length ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'edits the matching rule with the checkout-attempts origin', async () => {
		const attempt = aSession( {
			rules: { email: aRule( { id: 701 } ), ip: null },
		} );
		const refreshedAttempt = aSession( {
			rules: {
				email: aRule( {
					id: 701,
					action: 'allow',
					updated_at: '2026-04-02T10:00:00',
				} ),
				ip: null,
			},
		} );
		mockRuleApi( {
			before: [ attempt ],
			after: [ refreshedAttempt ],
			details: { 701: fullRule( { id: 701 } ) },
		} );

		renderPage();
		await chooseAttemptAction( attempt.email!, 'Edit email address rule' );
		await screen.findByDisplayValue( 'shopper@example.com' );
		await userEvent.selectOptions(
			screen.getByLabelText( 'Action' ),
			'allow'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/rules/701',
				method: 'PUT',
				data: {
					action: 'allow',
					type: 'email',
					value: 'shopper@example.com',
					origin: 'checkout_attempts',
				},
			} )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Edit rule' } )
			).not.toBeInTheDocument()
		);
		expect(
			await screen.findByRole( 'button', { name: /Allow rule updated/ } )
		).toBeInTheDocument();
		expect( listPaths().length ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'deletes the matching rule with the checkout-attempts origin', async () => {
		const attempt = aSession( {
			rules: { email: null, ip: aRule( { id: 802 } ) },
		} );
		const refreshedAttempt = aSession( {
			rules: { email: null, ip: null },
		} );
		mockRuleApi( { before: [ attempt ], after: [ refreshedAttempt ] } );

		renderPage();
		await chooseAttemptAction( attempt.ip!, 'Delete IP address rule' );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete rule',
		} );
		expect( within( dialog ).getByLabelText( 'Rule type' ) ).toHaveValue(
			'ip'
		);
		expect( within( dialog ).getByLabelText( 'Value' ) ).toHaveValue(
			'203.0.113.9'
		);
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Delete' } )
		);

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/rules/802?origin=checkout_attempts',
				method: 'DELETE',
			} )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Delete rule' } )
			).not.toBeInTheDocument()
		);
		await openAttemptActions( refreshedAttempt.ip! );
		expect(
			await screen.findByRole( 'menuitem', {
				name: 'Block this IP address',
			} )
		).toBeInTheDocument();
		expect( listPaths().length ).toBeGreaterThanOrEqual( 2 );
	} );

	it( 'opens a duplicate rule for normal editing with the checkout-attempts origin', async () => {
		const attempt = aSession( { id: 41 } );
		const existingRule = fullRule( { id: 17, action: 'allow' } );
		mockRuleApi( {
			before: [ attempt ],
			createError: {
				code: 'woocommerce_fraud_protection_duplicate_rule',
				message: 'This email is already allowed by a rule.',
				data: { rule_id: 17 },
			},
			details: { 17: existingRule },
		} );

		renderPage();
		await chooseAttemptAction( attempt.email!, 'Block this email address' );
		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Create rule' } )
		);
		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Edit existing rule' } )
		);

		expect(
			await screen.findByRole( 'dialog', { name: 'Edit rule' } )
		).toBeVisible();
		expect( screen.getByLabelText( 'Rule type' ) ).toBeEnabled();
		expect( screen.getByLabelText( 'Value' ) ).toBeEnabled();
		expect( screen.getByLabelText( 'Value' ) ).toHaveValue(
			existingRule.value
		);
		await userEvent.selectOptions(
			screen.getByLabelText( 'Action' ),
			'block'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/rules/17',
				method: 'PUT',
				data: {
					action: 'block',
					type: 'email',
					value: 'shopper@example.com',
					origin: 'checkout_attempts',
				},
			} )
		);
	} );

	it( 'cancels rule deletion without sending a request', async () => {
		const attempt = aSession( {
			rules: { email: aRule( { id: 803 } ), ip: null },
		} );
		mockRuleApi( { before: [ attempt ] } );

		renderPage();
		await chooseAttemptAction(
			attempt.email!,
			'Delete email address rule'
		);
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete rule',
		} );
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Cancel' } )
		);

		expect(
			ruleMutationRequests().some(
				( request: { method?: string; path?: string } ) =>
					request.method === 'DELETE' &&
					String( request.path ).includes( '/rules/803' )
			)
		).toBe( false );
		expect(
			screen.queryByRole( 'dialog', { name: 'Delete rule' } )
		).not.toBeInTheDocument();
	} );

	it( 'refetches with the enforced status when a tab is selected', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 1 ) } );

		renderPage();
		await screen.findByText( 'shopper@example.com' );

		await userEvent.click( screen.getByRole( 'tab', { name: 'Blocked' } ) );

		await waitFor( () => {
			expect(
				listPaths().some( ( path ) =>
					path.includes( 'final_status=blocked' )
				)
			).toBe( true );
		} );
	} );

	it( 'keeps the browser on admin.php with the route in the path query', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 1 ) } );

		renderPage();
		await screen.findByText( 'shopper@example.com' );

		await userEvent.click( screen.getByRole( 'tab', { name: 'Blocked' } ) );

		await waitFor( () => {
			const query = new URLSearchParams( mockHistory.location.search );
			expect( query.get( 'status' ) ).toBe( 'blocked' );
			// The route stays a `path` query arg on admin.php, not the pathname
			// (which would 404 on reload).
			expect( query.get( 'path' ) ).toBe( '/checkout-attempts' );
			expect( mockHistory.location.pathname ).not.toContain(
				'checkout-attempts'
			);
		} );
	} );

	it( 'starts on the page named in the URL', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 40, 2 ) } );

		renderPage( 'paged=2' );
		await screen.findByText( 'shopper@example.com' );
		expect(
			listPaths().some( ( path ) => path.includes( 'page=2&' ) )
		).toBe( true );
	} );

	it( 'falls back to the last page when the URL page is past the end', async () => {
		let call = 0;
		// The out-of-range page returns no rows but the true total (2 pages);
		// the list then refetches the last existing page.
		mockApi( {
			sessions: () =>
				0 === call++
					? listResponse( [], 30, 2 )
					: listResponse( [ aSession() ], 30, 2 ),
		} );

		renderPage( 'paged=5' );

		// The page is corrected to the last existing one, in the view and the fetch.
		await waitFor( () => {
			expect(
				listPaths().some( ( path ) => path.includes( 'page=2&' ) )
			).toBe( true );
		} );
		await waitFor( () =>
			expect(
				new URLSearchParams( mockHistory.location.search ).get(
					'paged'
				)
			).toBe( '2' )
		);
	} );

	it( 'resets to page 1 when there are no results', async () => {
		mockApi( { sessions: listResponse( [], 0, 0 ) } );

		renderPage( 'paged=3' );

		await waitFor( () =>
			expect(
				new URLSearchParams( mockHistory.location.search ).get(
					'paged'
				)
			).toBeNull()
		);
	} );

	it( 'selects the tab named in the URL and filters by it', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 1 ) } );

		renderPage( 'status=blocked' );

		await waitFor( () => {
			expect(
				listPaths().some( ( path ) =>
					path.includes( 'final_status=blocked' )
				)
			).toBe( true );
		} );
		expect(
			screen.getByRole( 'tab', { name: 'Blocked', selected: true } )
		).toBeInTheDocument();
	} );

	it( 'keeps an in-progress filter that has no value yet', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 1 ) } );

		renderPage();
		await screen.findByText( 'shopper@example.com' );

		// DataViews adds a filter field before the merchant picks a value; the
		// list must keep it so the value can then be chosen.
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Add filter' } )
		);
		await userEvent.click(
			await screen.findByRole( 'menuitem', { name: 'Outcome' } )
		);
		expect(
			screen
				.getAllByRole( 'button', { name: 'Outcome' } )
				.some(
					( button ) =>
						button.getAttribute( 'aria-expanded' ) === 'true'
				)
		).toBe( true );
	} );

	it( 'prevents the enclosing settings form from submitting', async () => {
		mockApi();

		mockHistory = createMemoryHistory( {
			initialEntries: [ '/wp-admin/admin.php' ],
		} );
		const { container } = render(
			<HistoryRouter history={ mockHistory }>
				<form>
					<CheckoutAttemptsPage />
				</form>
			</HistoryRouter>
		);
		await waitFor( () => expect( listPaths() ).toHaveLength( 1 ) );

		const form = container.querySelector( 'form' )!;
		const submit = new Event( 'submit', {
			bubbles: true,
			cancelable: true,
		} );
		form.dispatchEvent( submit );

		// e.g. pressing Enter in the search box must not reload the page.
		expect( submit.defaultPrevented ).toBe( true );
	} );

	it( 'shows a notice when the list cannot be loaded', async () => {
		mockApi( {
			sessions: () =>
				Promise.reject( new Error( 'Service unavailable.' ) ),
		} );

		renderPage();

		// The Notice renders the message in both a visible node and an aria-live
		// region, so assert on the visible one.
		const matches = await screen.findAllByText( 'Service unavailable.' );
		expect(
			matches.filter(
				( element ) => ! element.hasAttribute( 'aria-live' )
			)
		).toHaveLength( 1 );
	} );

	it( 'keeps the current page when the request fails', async () => {
		mockApi( {
			sessions: () => Promise.reject( new Error( 'Nope.' ) ),
		} );

		renderPage( 'paged=3' );

		await waitFor( () =>
			expect( screen.getAllByText( 'Nope.' ).length ).toBeGreaterThan( 0 )
		);
		// A failed request reports zero pages; the list must not reset the page
		// (which would drop the deep link and refetch).
		expect(
			new URLSearchParams( mockHistory.location.search ).get( 'paged' )
		).toBe( '3' );
	} );

	it( 'shows the automatic-protection banner while protection is off', async () => {
		mockApi();

		renderPage();

		// The banner's message renders visibly (the Notice also mirrors it into an
		// aria-live region, so there are two matches).
		expect(
			screen.getAllByText( /Automatic fraud prevention is off/ ).length
		).toBeGreaterThan( 0 );
		expect(
			await screen.findByRole( 'button', {
				name: 'Enable automatic fraud prevention',
			} )
		).toBeInTheDocument();
	} );

	it( 'dismisses the banner and persists it per user', async () => {
		mockApi();

		renderPage();

		await screen.findByRole( 'button', {
			name: 'Enable automatic fraud prevention',
		} );

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Dismiss the automatic fraud prevention banner',
			} )
		);

		expect( mockUpdateUserPreferences ).toHaveBeenCalledWith( {
			fraud_protection_checkout_attempts_banner_dismissed: 'yes',
		} );
	} );

	it( 'does not show the banner once the user has dismissed it', async () => {
		mockUseUserPreferences.mockReturnValue( {
			isRequesting: false,
			updateUserPreferences: mockUpdateUserPreferences,
			fraud_protection_checkout_attempts_banner_dismissed: 'yes',
		} );
		mockApi();

		renderPage();

		await waitFor( () => expect( listPaths() ).toHaveLength( 1 ) );
		expect(
			screen.queryByRole( 'button', {
				name: 'Enable automatic fraud prevention',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'opens the enable drawer from the banner and turns protection on in place', async () => {
		mockApi();

		renderPage();

		// Clicking the banner button opens the drawer, without navigating away.
		await userEvent.click(
			await screen.findByRole( 'button', {
				name: 'Enable automatic fraud prevention',
			} )
		);
		expect(
			await screen.findByRole( 'heading', {
				name: 'Enable fraud prevention',
			} )
		).toBeVisible();

		// Save stays disabled until the checkbox changes from its initial state.
		// The @wordpress/ui Button marks its disabled state with aria-disabled
		// rather than the native attribute.
		expect(
			screen.getByRole( 'button', { name: 'Save' } )
		).toHaveAttribute( 'aria-disabled', 'true' );

		// Check the box and save: it posts to the settings endpoint...
		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: /Automatically block checkout attempts/,
			} )
		);
		expect(
			screen.getByRole( 'button', { name: 'Save' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );
		await userEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/settings',
				method: 'POST',
				data: { automatic_protection: true },
			} )
		);

		// ...confirms that automatic fraud prevention is on...
		await waitFor( () =>
			expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
				'Automatic fraud prevention is on, flagged checkout attempts will be blocked automatically going forward.',
				{ type: 'snackbar' }
			)
		);

		// ...and the banner goes away once protection is on.
		await waitFor( () =>
			expect(
				screen.queryByText( /Automatic fraud prevention is off/ )
			).not.toBeInTheDocument()
		);
	} );

	it( 'opens the existing enable drawer from a flagged row action', async () => {
		const flagged = aSession( {
			outcome: 'flagged_by_fraud_prevention',
		} );
		mockApi( { sessions: listResponse( [ flagged ], 1 ) } );

		renderPage();
		await chooseAttemptAction(
			flagged.email!,
			'Turn on automatic fraud prevention'
		);

		expect(
			await screen.findByRole( 'heading', {
				name: 'Enable fraud prevention',
			} )
		).toBeVisible();
	} );

	it( 'hides the automatic-protection banner when protection is on', async () => {
		seedProtection( true );
		mockApi();

		renderPage();

		await waitFor( () => expect( listPaths() ).toHaveLength( 1 ) );
		expect(
			screen.queryByRole( 'button', {
				name: 'Enable automatic fraud prevention',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'keeps the protection-off controls hidden when the settings load fails', async () => {
		mockApi();

		// A failed settings load finishes resolution but leaves the settings
		// null. The protection state is then unknown, so the off banner and the
		// "enable" row action must stay hidden rather than appear as if
		// protection were off. Use an isolated registry so `current` is null
		// (the shared store cannot be reset to null once seeded).
		const registry = createRegistry();
		registry.register( settingsStore );
		registry.register( rulesStore );
		// The enable drawer is always mounted and reads core/notices, so the
		// isolated registry needs it too.
		registry.register(
			createReduxStore( 'core/notices', {
				reducer: ( state = null ) => state,
				actions: {
					createSuccessNotice: () => ( {
						type: 'CREATE_SUCCESS_NOTICE',
					} ),
				},
			} )
		);
		const settingsDispatch = registry.dispatch(
			settingsStore
		) as unknown as {
			finishResolution: ( selector: string, args: unknown[] ) => void;
			setError: ( error: {
				message: string | null;
				operation: 'load';
			} ) => void;
		};
		settingsDispatch.finishResolution( 'getSettings', [] );
		settingsDispatch.setError( { message: 'Boom', operation: 'load' } );

		mockHistory = createMemoryHistory( {
			initialEntries: [ '/wp-admin/admin.php?' ],
		} );
		render( <CheckoutAttemptsPage />, {
			wrapper: ( { children }: { children: ReactNode } ) => (
				<RegistryProvider value={ registry }>
					<HistoryRouter history={ mockHistory }>
						{ children }
					</HistoryRouter>
				</RegistryProvider>
			),
		} );

		await waitFor( () => expect( listPaths() ).toHaveLength( 1 ) );
		expect(
			screen.queryByRole( 'button', {
				name: 'Enable automatic fraud prevention',
			} )
		).not.toBeInTheDocument();
		expect(
			screen.queryByText( /Automatic fraud prevention is off/ )
		).not.toBeInTheDocument();
	} );
} );
