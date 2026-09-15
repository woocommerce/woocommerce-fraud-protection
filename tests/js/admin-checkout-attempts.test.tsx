import '@testing-library/jest-dom';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { ComponentType, ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

import apiFetch from '@wordpress/api-fetch';
import { dispatch as dataDispatch } from '@wordpress/data';
import type { View } from '@wordpress/dataviews';

import { buildActions } from '../../client/admin-checkout-attempts/actions';
import { getFields } from '../../client/admin-checkout-attempts/fields';
import {
	getOutcomeLabel,
	getOutcomeOptions,
} from '../../client/admin-checkout-attempts/outcomes';
import { buildListPath } from '../../client/admin-checkout-attempts/use-checkout-attempts';
import {
	loadPrefs,
	savePrefs,
} from '../../client/admin-checkout-attempts/persisted-state';
import { settingsStore } from '../../client/admin-settings/data/store';
import type {
	CheckoutAttemptsConfig,
	RuleReference,
	Session,
} from '../../client/admin-checkout-attempts/types';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

// The list lives inside the settings single-page app: its breadcrumb builds an
// in-app route, so the navigation helper is mocked and renders are wrapped in a
// router.
jest.mock( '@woocommerce/navigation', () => ( {
	__esModule: true,
	getNewPath: () =>
		'/wp-admin/admin.php?page=wc-settings&tab=woocommerce_fraud_protection',
} ) );

// DataViews is bundled and heavy; the page's own wiring is what these tests
// cover, so DataViews is replaced with a spy that records the props it receives.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	__esModule: true,
	DataViews: jest.fn( () => null ),
} ) );

import { DataViews } from '@wordpress/dataviews/wp';

import { CheckoutAttemptsPage } from '../../client/admin-checkout-attempts/checkout-attempts-page';

const mockedApiFetch = apiFetch as unknown as jest.Mock;
const mockedDataViews = DataViews as unknown as jest.Mock;

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

const lastDataViewsProps = () =>
	mockedDataViews.mock.calls[ mockedDataViews.mock.calls.length - 1 ][ 0 ];

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

const setConfig = ( config: Partial< CheckoutAttemptsConfig > = {} ) => {
	window.wcFraudProtectionCheckoutAttempts = {
		automaticProtection: false,
		automaticProtectionEnabledAt: null,
		settingsUrl: 'https://example.test/wp-admin/settings',
		paymentMethods: [ { id: 'stripe', title: 'Stripe' } ],
		...config,
	};
};

describe( 'checkout attempts outcomes', () => {
	it( 'labels every outcome and exposes them as filter options', () => {
		expect( getOutcomeLabel( 'flagged_by_fraud_prevention' ) ).toBe(
			'Allowed, flagged'
		);
		expect( getOutcomeLabel( 'blocked_by_rules' ) ).toBe(
			'Blocked by rules'
		);

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
} );

describe( 'checkout attempts row actions', () => {
	const actionsConfig: CheckoutAttemptsConfig = {
		automaticProtection: false,
		automaticProtectionEnabledAt: null,
		settingsUrl: 'https://example.test/wp-admin/settings',
		paymentMethods: [],
	};

	const noopEnable = () => {};

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
} );

describe( 'checkout attempts status field', () => {
	const config: CheckoutAttemptsConfig = {
		automaticProtection: false,
		automaticProtectionEnabledAt: null,
		settingsUrl: 'https://example.test/wp-admin/settings',
		paymentMethods: [],
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

	it( 'explains the flag and links to enabling protection while it is off', async () => {
		renderField(
			'outcome',
			aSession( { outcome: 'flagged_by_fraud_prevention' } )
		);

		await userEvent.hover( flaggedInfo() );

		expect(
			await screen.findByText(
				/because automatic fraud prevention is off/,
				{},
				{ timeout: 3000 }
			)
		).toBeInTheDocument();
		const link = screen.getByRole( 'link', {
			name: 'Enable automatic fraud prevention',
		} );
		expect( link ).toHaveAttribute( 'href', config.settingsUrl );
		// The settings page opens in a new tab.
		expect( link ).toHaveAttribute( 'target', '_blank' );
		expect( link ).toHaveAttribute( 'rel', 'noopener noreferrer' );
	} );

	it( 'notes when protection was enabled in the tooltip once it is on', async () => {
		renderField(
			'outcome',
			aSession( { outcome: 'flagged_by_fraud_prevention' } ),
			{
				...config,
				automaticProtection: true,
				automaticProtectionEnabledAt: '2026-04-20T00:00:00',
			}
		);

		await userEvent.hover( flaggedInfo() );

		expect(
			await screen.findByText(
				/because automatic fraud prevention was off\. Enabled/,
				{},
				{ timeout: 3000 }
			)
		).toBeInTheDocument();
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

	it( 'shows the rule tooltip when hovering the label text, not only the icon', async () => {
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

		// Hovering the visible label (not the icon) must open the tooltip.
		await userEvent.hover( screen.getByText( 'Block rule' ) );

		expect(
			await screen.findByText( /Rule created/, {}, { timeout: 3000 } )
		).toBeInTheDocument();
	} );

	it( 'leads the compact rule tooltip with the allow/block word', async () => {
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

		await userEvent.hover(
			screen.getByText( 'Block rule' ) // sr-only label in compact
		);

		expect(
			await screen.findByText(
				/Block rule created/,
				{},
				{ timeout: 3000 }
			)
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
		paymentMethods: [],
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
	const STORAGE_KEY = 'wc-fraud-protection-checkout-attempts-prefs';

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
		window.localStorage.setItem( STORAGE_KEY, 'not json' );
		expect( loadPrefs() ).toEqual( {} );

		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( { version: 999, prefs: { perPage: 50 } } )
		);
		expect( loadPrefs() ).toEqual( {} );
	} );

	it( 'drops invalid preference values', () => {
		// A payload at the current version but with the wrong value types.
		window.localStorage.setItem(
			STORAGE_KEY,
			JSON.stringify( {
				version: 2,
				prefs: { fields: 'nope', perPage: -3 },
			} )
		);

		expect( loadPrefs() ).toEqual( {} );
	} );
} );

const settingsResponse = ( automaticProtection = false ) => ( {
	automatic_protection: automaticProtection,
	automatic_protection_opted_out: true,
	performance: {
		flagged_by_fraud_prevention: 0,
		blocked_automatically: 0,
		allowed_by_rules: 0,
		blocked_by_rules: 0,
	},
} );

type ListResponse = ReturnType< typeof listResponse >;

// Route apiFetch by path: the paginated sessions list (read with parse:false),
// the on-demand provider options, and the settings GET/POST the store uses.
const mockApi = ( {
	sessions,
	paymentMethods = [],
	onPost,
}: {
	sessions?: ListResponse | ( () => ListResponse | Promise< ListResponse > );
	paymentMethods?: Array< { id: string; title: string } >;
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
			if ( path.includes( '/sessions/payment-methods' ) ) {
				return Promise.resolve( paymentMethods );
			}
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

// The list's navigation state lives in the URL, so render inside a router seeded
// with the query string under test.
const renderPage = ( url = '/' ) =>
	render( <CheckoutAttemptsPage />, {
		wrapper: ( { children }: { children: ReactNode } ) => (
			<MemoryRouter initialEntries={ [ url ] }>{ children }</MemoryRouter>
		),
	} );

// Only the paginated list requests (which carry query args), not the
// provider-options request at /sessions/payment-methods.
const listPaths = () =>
	settledPaths().filter( ( path ) => path.includes( '/sessions?' ) );

// The list reads automatic-protection state from the settings store; seed it to
// a known value and mark it resolved so the resolver does not also fetch.
const seedProtection = ( on: boolean ) => {
	const store = dataDispatch( settingsStore ) as unknown as {
		receiveSettings: ( settings: {
			automatic_protection: boolean;
			automatic_protection_opted_out: boolean;
		} ) => void;
		finishResolution: ( selector: string, args: unknown[] ) => void;
		setError: ( error: null ) => void;
	};
	store.receiveSettings( {
		automatic_protection: on,
		automatic_protection_opted_out: true,
	} );
	store.finishResolution( 'getSettings', [] );
	store.setError( null );
};

describe( 'CheckoutAttemptsPage', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockedDataViews.mockClear();
		window.localStorage.clear();
		setConfig();
		seedProtection( false );
		mockApi();
	} );

	afterEach( () => {
		delete window.wcFraudProtectionCheckoutAttempts;
	} );

	it( 'renders the header and passes the loaded rows to DataViews', async () => {
		const sessions = [
			aSession(),
			aSession( { id: 2, outcome: 'blocked_by_rules' } ),
		];
		mockApi( { sessions: listResponse( sessions, 2 ) } );

		renderPage();

		expect(
			screen.getByText( /A record of past checkout attempts/ )
		).toBeInTheDocument();

		await waitFor( () => {
			expect( lastDataViewsProps().data ).toHaveLength( 2 );
		} );
		const props = lastDataViewsProps();
		expect( props.paginationInfo ).toEqual( {
			totalItems: 2,
			totalPages: 1,
		} );
		expect(
			props.actions.map( ( action: { id: string } ) => action.id )
		).toEqual( expect.arrayContaining( [ 'email-block', 'ip-block' ] ) );
		expect( listPaths()[ 0 ] ).toContain(
			'/wc-fraud-protection/v1/sessions'
		);
		expect( listPaths()[ 0 ] ).not.toContain( 'final_status' );
	} );

	it( 'refetches with the enforced status when a tab is selected', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 1 ) } );

		renderPage();

		await waitFor( () => {
			expect( lastDataViewsProps().data ).toHaveLength( 1 );
		} );

		await userEvent.click( screen.getByRole( 'tab', { name: 'Blocked' } ) );

		await waitFor( () => {
			expect(
				listPaths().some( ( path ) =>
					path.includes( 'final_status=blocked' )
				)
			).toBe( true );
		} );
	} );

	it( 'starts on the page named in the URL', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 40, 2 ) } );

		renderPage( '/?paged=2' );

		await waitFor( () => {
			expect( lastDataViewsProps().data ).toHaveLength( 1 );
		} );
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

		renderPage( '/?paged=5' );

		// The page is corrected to the last existing one, in the view and the fetch.
		await waitFor( () => {
			expect(
				listPaths().some( ( path ) => path.includes( 'page=2&' ) )
			).toBe( true );
		} );
		expect( lastDataViewsProps().view.page ).toBe( 2 );
	} );

	it( 'resets to page 1 when there are no results', async () => {
		mockApi( { sessions: listResponse( [], 0, 0 ) } );

		renderPage( '/?paged=3' );

		await waitFor( () => {
			expect( lastDataViewsProps().view.page ).toBe( 1 );
		} );
	} );

	it( 'selects the tab named in the URL and filters by it', async () => {
		mockApi( { sessions: listResponse( [ aSession() ], 1 ) } );

		renderPage( '/?status=blocked' );

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
		await waitFor( () =>
			expect( lastDataViewsProps().data ).toHaveLength( 1 )
		);

		// DataViews adds a filter field before the merchant picks a value; the
		// list must keep it so the value can then be chosen.
		const before = lastDataViewsProps();
		act( () => {
			before.onChangeView( {
				...before.view,
				filters: [ { field: 'outcome', operator: 'isAny', value: [] } ],
			} );
		} );

		await waitFor( () => {
			expect( lastDataViewsProps().view.filters ).toEqual(
				expect.arrayContaining( [
					expect.objectContaining( { field: 'outcome' } ),
				] )
			);
		} );
	} );

	it( 'prevents the enclosing settings form from submitting', async () => {
		mockApi();

		const { container } = render(
			<form>
				<CheckoutAttemptsPage />
			</form>,
			{
				wrapper: ( { children }: { children: ReactNode } ) => (
					<MemoryRouter>{ children }</MemoryRouter>
				),
			}
		);
		await waitFor( () => expect( lastDataViewsProps() ).toBeDefined() );

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

		renderPage( '/?paged=3' );

		await waitFor( () =>
			expect( screen.getAllByText( 'Nope.' ).length ).toBeGreaterThan( 0 )
		);
		// A failed request reports zero pages; the list must not reset the page
		// (which would drop the deep link and refetch).
		expect( lastDataViewsProps().view.page ).toBe( 3 );
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

		// ...and the banner goes away once protection is on.
		await waitFor( () =>
			expect(
				screen.queryByText( /Automatic fraud prevention is off/ )
			).not.toBeInTheDocument()
		);
	} );

	it( 'hides the automatic-protection banner when protection is on', async () => {
		seedProtection( true );
		mockApi();

		renderPage();

		await waitFor( () => expect( lastDataViewsProps() ).toBeDefined() );
		expect(
			screen.queryByRole( 'button', {
				name: 'Enable automatic fraud prevention',
			} )
		).not.toBeInTheDocument();
	} );
} );
