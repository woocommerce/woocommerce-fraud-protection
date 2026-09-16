import '@testing-library/jest-dom';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createMemoryHistory } from 'history';

import apiFetch from '@wordpress/api-fetch';
import {
	createReduxStore,
	createRegistry,
	RegistryProvider,
} from '@wordpress/data';

import { settingsStore } from '../../client/admin-settings/data/store';
import { rulesStore } from '../../client/admin-settings/data/rules-store';
import { FraudProtectionAdminApp } from '../../client/admin-settings';

function mockGetNewPath( _query: { page: string; tab: string }, path: string ) {
	return path;
}

const createTestHistory = ( initialRoute: string ) =>
	createMemoryHistory( { initialEntries: [ initialRoute ] } );

let mockHistory: ReturnType< typeof createTestHistory >;

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/notices', () => ( {
	store: { name: 'core/notices' },
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () => ( {
		isRequesting: false,
		updateUserPreferences: jest.fn(),
	} ),
} ) );

jest.mock( '@woocommerce/navigation', () => ( {
	getHistory: () => mockHistory,
	getNewPath: mockGetNewPath,
} ) );

// The real checkout attempts page renders DataViews; it is bundled and heavy,
// and these tests cover the app's routing rather than the list, so it is
// replaced with a no-op. Its Tabs still render and need a ResizeObserver. The
// list imports DataViews from the `/wp` runtime entry point, so mock that.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	__esModule: true,
	DataViews: () => null,
	DataForm: () => null,
	useFormValidity: () => ( { validity: undefined, isValid: true } ),
} ) );

if ( ! window.ResizeObserver ) {
	window.ResizeObserver = class {
		observe() {}
		unobserve() {}
		disconnect() {}
	};
}

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
const settingsResponse = {
	automatic_protection: false,
	performance: {
		flagged_by_fraud_prevention: 0,
		blocked_automatically: 0,
		allowed_by_rules: 0,
		blocked_by_rules: 0,
	},
};

const SETTINGS_PATH = '/wc-fraud-protection/v1/settings';

// The settings data is fetched as a plain object; the checkout attempts list is
// fetched with `parse: false` and reads a Response. Answer each in kind so the
// real list page mounts without error while the routing is exercised.
const apiFetchImplementation = ( options: unknown ) => {
	const { path } = ( options ?? {} ) as { path?: string };
	if (
		path &&
		( path.startsWith( '/wc-fraud-protection/v1/sessions' ) ||
			path.startsWith( '/wc-fraud-protection/v1/rules' ) )
	) {
		return Promise.resolve( {
			json: () => Promise.resolve( [] ),
			headers: { get: () => '0' },
		} );
	}

	return Promise.resolve( settingsResponse );
};

// How many times the settings endpoint specifically was requested; the list
// route also calls apiFetch, so a bare call count no longer isolates settings.
const settingsFetchCount = () =>
	mockedApiFetch.mock.calls.filter(
		( [ options ] ) =>
			( options as { path?: string } )?.path === SETTINGS_PATH
	).length;

const noticesStore = createReduxStore( 'core/notices', {
	reducer: ( state = null ) => state,
	actions: {
		createSuccessNotice: () => ( { type: 'CREATE_SUCCESS_NOTICE' } ),
	},
} );

const renderApp = ( initialRoute = '/' ) => {
	mockHistory = createTestHistory( initialRoute );
	const registry = createRegistry();
	registry.register( settingsStore );
	registry.register( rulesStore );
	registry.register( noticesStore );

	return render(
		<RegistryProvider value={ registry }>
			<FraudProtectionAdminApp />
		</RegistryProvider>
	);
};

describe( 'FraudProtectionAdminApp navigation', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockedApiFetch.mockImplementation( apiFetchImplementation );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'navigates between settings and checkout attempts without reloading', async () => {
		const confirm = jest.spyOn( window, 'confirm' );
		renderApp();

		await userEvent.click(
			await screen.findByRole( 'link', {
				name: 'View checkout attempts',
			} )
		);

		expect( mockHistory.location.pathname ).toBe( '/checkout-attempts' );
		expect(
			screen.getByText( /A record of past checkout attempts/ )
		).toBeVisible();

		await userEvent.click(
			screen.getByRole( 'link', { name: 'Fraud prevention' } )
		);

		await waitFor( () =>
			expect( mockHistory.location.pathname ).toBe( '/' )
		);
		expect(
			screen.getByRole( 'heading', { name: 'Performance' } )
		).toBeVisible();
		expect( confirm ).not.toHaveBeenCalled();
	} );

	it( 'loads settings only after returning from a direct checkout-attempt visit', async () => {
		renderApp( '/checkout-attempts' );

		expect(
			screen.getByText( /A record of past checkout attempts/ )
		).toBeVisible();
		// The list route never fetches the settings data, only its own list.
		expect( settingsFetchCount() ).toBe( 0 );

		await userEvent.click(
			screen.getByRole( 'link', { name: 'Fraud prevention' } )
		);

		expect( await screen.findByRole( 'checkbox' ) ).not.toBeChecked();
		expect( settingsFetchCount() ).toBe( 1 );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/settings',
		} );
	} );

	it( 'loads the rules page on the dedicated route', async () => {
		renderApp( '/rules' );

		expect(
			await screen.findByRole( 'navigation', { name: 'Breadcrumb' } )
		).toBeVisible();
		expect( mockHistory.location.pathname ).toBe( '/rules' );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&orderby=created_at&order=desc',
			parse: false,
		} );
	} );

	it( 'keeps settings open when checkout-attempt navigation is cancelled', async () => {
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValue( false );
		renderApp();
		const checkbox = await screen.findByRole( 'checkbox' );
		await userEvent.click( checkbox );

		await userEvent.click(
			screen.getByRole( 'link', { name: 'View checkout attempts' } )
		);

		expect( confirm ).toHaveBeenCalledTimes( 1 );
		expect( mockHistory.location.pathname ).toBe( '/' );
		expect( checkbox ).toBeChecked();
		expect(
			screen.getByRole( 'button', { name: 'Save' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );

		await userEvent.click(
			screen.getByRole( 'link', { name: 'View checkout attempts' } )
		);
		expect( confirm ).toHaveBeenCalledTimes( 2 );
		expect( mockHistory.location.pathname ).toBe( '/' );
	} );

	it( 'retries checkout-attempt navigation once when confirmed', async () => {
		jest.spyOn( window, 'confirm' ).mockReturnValueOnce( true );
		renderApp();
		await userEvent.click( await screen.findByRole( 'checkbox' ) );

		await userEvent.click(
			screen.getByRole( 'link', { name: 'View checkout attempts' } )
		);

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect( mockHistory.location.pathname ).toBe( '/checkout-attempts' );
		expect(
			screen.getByText( /A record of past checkout attempts/ )
		).toBeVisible();

		await userEvent.click(
			screen.getByRole( 'link', { name: 'Fraud prevention' } )
		);
		const checkbox = await screen.findByRole( 'checkbox' );
		expect( checkbox ).not.toBeChecked();
		expect(
			screen.getByRole( 'button', { name: 'Save' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it( 'guards Back navigation while settings are dirty', async () => {
		const confirm = jest
			.spyOn( window, 'confirm' )
			.mockReturnValueOnce( false )
			.mockReturnValueOnce( true );
		renderApp();

		await userEvent.click(
			await screen.findByRole( 'link', {
				name: 'View checkout attempts',
			} )
		);
		await userEvent.click(
			screen.getByRole( 'link', { name: 'Fraud prevention' } )
		);
		await userEvent.click( await screen.findByRole( 'checkbox' ) );

		act( () => mockHistory.back() );
		expect( mockHistory.location.pathname ).toBe( '/' );
		expect( confirm ).toHaveBeenCalledTimes( 1 );

		act( () => mockHistory.back() );
		expect( mockHistory.location.pathname ).toBe( '/checkout-attempts' );
		expect( confirm ).toHaveBeenCalledTimes( 2 );

		await userEvent.click(
			screen.getByRole( 'link', { name: 'Fraud prevention' } )
		);
		expect( await screen.findByRole( 'checkbox' ) ).not.toBeChecked();
	} );

	it( 'redirects unknown routes to settings', async () => {
		renderApp( '/unknown' );

		await waitFor( () =>
			expect( mockHistory.location.pathname ).toBe( '/' )
		);
		expect( mockHistory.action ).toBe( 'REPLACE' );
		expect(
			screen.getByRole( 'heading', { name: 'Performance' } )
		).toBeVisible();
	} );
} );
