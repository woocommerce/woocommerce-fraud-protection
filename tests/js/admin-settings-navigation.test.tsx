import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import apiFetch from '@wordpress/api-fetch';

import { FraudProtectionAdminApp } from '../../client/admin-settings';

type HistoryTarget =
	| string
	| { pathname?: string; search?: string; hash?: string };

const getNewPath = ( query: { page: string; tab: string }, path: string ) => {
	const route = new URLSearchParams( query );
	if ( path !== '/' ) {
		route.set( 'path', path );
	}

	return `/wp-admin/admin.php?${ route.toString() }`;
};

const targetToHref = ( target: HistoryTarget ) =>
	typeof target === 'string'
		? target
		: `${ target.pathname ?? '' }${ target.search ?? '' }${
				target.hash ?? ''
		  }`;

const createTestHistory = ( initialRoute: string ) => {
	let action = 'POP';
	let href = getNewPath(
		{ page: 'wc-settings', tab: 'woocommerce_fraud_protection' },
		initialRoute
	);
	const listeners = new Set< ( update: unknown ) => void >();
	const getLocation = () => {
		const url = new URL( href, 'http://localhost' );

		return {
			pathname: url.searchParams.get( 'path' ) ?? '/',
			search: url.search,
			hash: url.hash,
			state: null,
			key: 'test',
		};
	};
	const update = ( nextAction: string, target: HistoryTarget ) => {
		action = nextAction;
		href = targetToHref( target );
		const nextLocation = getLocation();
		listeners.forEach( ( listener ) =>
			listener( { action, location: nextLocation } )
		);
	};

	return {
		get action() {
			return action;
		},
		get location() {
			return getLocation();
		},
		createHref: targetToHref,
		push: ( target: HistoryTarget ) => update( 'PUSH', target ),
		replace: ( target: HistoryTarget ) => update( 'REPLACE', target ),
		go: jest.fn(),
		back: jest.fn(),
		forward: jest.fn(),
		block: jest.fn( () => jest.fn() ),
		listen: ( listener: ( update: unknown ) => void ) => {
			listeners.add( listener );
			return () => listeners.delete( listener );
		},
	};
};

let mockHistory = createTestHistory( '/' );

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@woocommerce/navigation', () => ( {
	getHistory: () => mockHistory,
	getNewPath: ( query: { page: string; tab: string }, path: string ) => {
		const route = new URLSearchParams( query );
		if ( path !== '/' ) {
			route.set( 'path', path );
		}

		return `/wp-admin/admin.php?${ route.toString() }`;
	},
	useConfirmUnsavedChanges: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'FraudProtectionAdminApp navigation', () => {
	beforeEach( () => {
		mockHistory = createTestHistory( '/' );
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( {
			automatic_protection: false,
			performance: {
				recommended_for_blocking: 0,
				blocked_automatically: 0,
				allowed_by_rules: 0,
				blocked_by_rules: 0,
			},
		} );
	} );

	it( 'navigates between settings and checkout attempts without reloading', async () => {
		render( <FraudProtectionAdminApp /> );

		await userEvent.click(
			await screen.findByRole( 'link', {
				name: 'View checkout attempts',
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
	} );

	it( 'redirects unknown routes to settings', async () => {
		mockHistory = createTestHistory( '/unknown' );

		render( <FraudProtectionAdminApp /> );

		await waitFor( () =>
			expect( mockHistory.location.pathname ).toBe( '/' )
		);
		expect( mockHistory.action ).toBe( 'REPLACE' );
		expect(
			screen.getByRole( 'heading', { name: 'Performance' } )
		).toBeVisible();
	} );
} );
