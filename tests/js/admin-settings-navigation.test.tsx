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

jest.mock( '@woocommerce/navigation', () => ( {
	getHistory: () => mockHistory,
	getNewPath: mockGetNewPath,
} ) );

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
		mockedApiFetch.mockResolvedValue( settingsResponse );
	} );

	afterEach( () => {
		jest.restoreAllMocks();
	} );

	it( 'navigates between settings and checkout attempts without reloading', async () => {
		const confirm = jest.spyOn( window, 'confirm' );
		renderApp();

		await userEvent.click(
			await screen.findByRole( 'link', {
				name: 'View checkout sessions',
			} )
		);

		expect( mockHistory.location.pathname ).toBe( '/checkout-attempts' );
		expect(
			screen.getByText( 'Hello from the checkout attempts page.' )
		).toBeVisible();

		await userEvent.click( screen.getByRole( 'link', { name: 'Back' } ) );

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
			screen.getByText( 'Hello from the checkout attempts page.' )
		).toBeVisible();
		expect( mockedApiFetch ).not.toHaveBeenCalled();

		await userEvent.click( screen.getByRole( 'link', { name: /^Back$/ } ) );

		expect( await screen.findByRole( 'checkbox' ) ).not.toBeChecked();
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/settings',
		} );
	} );

	it( 'loads the rules page on the dedicated route', async () => {
		mockedApiFetch.mockResolvedValue( {
			data: [],
			totalItems: 0,
			totalPages: 0,
			page: 1,
			perPage: 20,
		} );
		renderApp( '/rules' );

		expect(
			await screen.findByRole( 'navigation', { name: 'Breadcrumb' } )
		).toBeVisible();
		expect( mockHistory.location.pathname ).toBe( '/rules' );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&orderby=created_at&order=desc',
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
			screen.getByRole( 'link', { name: 'View checkout sessions' } )
		);

		expect( confirm ).toHaveBeenCalledTimes( 1 );
		expect( mockHistory.location.pathname ).toBe( '/' );
		expect( checkbox ).toBeChecked();
		expect(
			screen.getByRole( 'button', { name: 'Save' } )
		).not.toHaveAttribute( 'aria-disabled', 'true' );

		await userEvent.click(
			screen.getByRole( 'link', { name: 'View checkout sessions' } )
		);
		expect( confirm ).toHaveBeenCalledTimes( 2 );
		expect( mockHistory.location.pathname ).toBe( '/' );
	} );

	it( 'retries checkout-attempt navigation once when confirmed', async () => {
		jest.spyOn( window, 'confirm' ).mockReturnValueOnce( true );
		renderApp();
		await userEvent.click( await screen.findByRole( 'checkbox' ) );

		await userEvent.click(
			screen.getByRole( 'link', { name: 'View checkout sessions' } )
		);

		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		expect( mockHistory.location.pathname ).toBe( '/checkout-attempts' );
		expect(
			screen.getByText( 'Hello from the checkout attempts page.' )
		).toBeVisible();

		await userEvent.click( screen.getByRole( 'link', { name: /^Back$/ } ) );
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
				name: 'View checkout sessions',
			} )
		);
		await userEvent.click( screen.getByRole( 'link', { name: 'Back' } ) );
		await userEvent.click( await screen.findByRole( 'checkbox' ) );

		act( () => mockHistory.back() );
		expect( mockHistory.location.pathname ).toBe( '/' );
		expect( confirm ).toHaveBeenCalledTimes( 1 );

		act( () => mockHistory.back() );
		expect( mockHistory.location.pathname ).toBe( '/checkout-attempts' );
		expect( confirm ).toHaveBeenCalledTimes( 2 );

		await userEvent.click( screen.getByRole( 'link', { name: /^Back$/ } ) );
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
