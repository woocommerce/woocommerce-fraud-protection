import '@testing-library/jest-dom';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import apiFetch from '@wordpress/api-fetch';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import type { View } from '@wordpress/dataviews';

import { rulesStore } from '../../client/admin-settings/data/rules-store';
import {
	getQueryFromView,
	RulesPage,
} from '../../client/admin-settings/rules-page';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@woocommerce/navigation', () => ( {
	getNewPath: ( query: Record< string, string >, path: string ) => {
		const route = new URLSearchParams( query );
		if ( path !== '/' ) {
			route.set( 'path', path );
		}
		return `/wp-admin/admin.php?${ route.toString() }`;
	},
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type RulesResponse = {
	data: Array< {
		id: number;
		action: 'allow' | 'block';
		value: string;
		type: 'email' | 'ip';
		created_at: string;
	} >;
	totalItems: number;
	totalPages: number;
	page: number;
	perPage: number;
};

const renderRules = () => {
	const registry = createRegistry();
	registry.register( rulesStore );

	return render(
		<MemoryRouter>
			<RegistryProvider value={ registry }>
				<RulesPage />
			</RegistryProvider>
		</MemoryRouter>
	);
};

describe( 'RulesPage', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockedApiFetch.mockResolvedValue( {
			data: [
				{
					id: 1,
					action: 'allow',
					value: 'shopper@example.com',
					type: 'email',
					created_at: '2026-09-14T12:00:00',
				},
			],
			totalItems: 1,
			totalPages: 1,
			page: 1,
			perPage: 20,
		} );
	} );

	it( 'loads rules and maps the action tab to a single server filter', async () => {
		renderRules();

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&orderby=created_at&order=desc',
			} )
		);
		expect(
			await screen.findByText( 'shopper@example.com' )
		).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Block' } ) );

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&action=block&orderby=created_at&order=desc',
			} )
		);
	} );

	it( 'links back to fraud prevention settings', async () => {
		renderRules();
		await waitFor( () => expect( mockedApiFetch ).toHaveBeenCalled() );

		expect(
			screen.getByRole( 'link', { name: 'Fraud prevention' } )
		).toHaveAttribute(
			'href',
			'/wp-admin/admin.php?page=wc-settings&tab=woocommerce_fraud_protection'
		);
	} );

	it( 'ignores a stale response after the query changes', async () => {
		let resolveFirst: ( response: RulesResponse ) => void = () => {};
		let resolveSecond: ( response: RulesResponse ) => void = () => {};
		const firstResponse = new Promise< RulesResponse >( ( resolve ) => {
			resolveFirst = resolve;
		} );
		const secondResponse = new Promise< RulesResponse >( ( resolve ) => {
			resolveSecond = resolve;
		} );
		mockedApiFetch
			.mockReturnValueOnce( firstResponse )
			.mockReturnValueOnce( secondResponse );

		const registry = createRegistry();
		registry.register( rulesStore );
		const query = { page: 1, perPage: 20 };
		const newerQuery = { page: 2, perPage: 20 };
		const firstRequest = registry
			.dispatch( rulesStore )
			.requestRules( query );
		const secondRequest = registry
			.dispatch( rulesStore )
			.requestRules( newerQuery );
		const newerRules = {
			data: [
				{
					id: 2,
					action: 'block' as const,
					value: 'newer@example.com',
					type: 'email' as const,
					created_at: '2026-09-14T12:00:00',
				},
			],
			totalItems: 1,
			totalPages: 1,
			page: 2,
			perPage: 20,
		};
		const olderRules = {
			...newerRules,
			data: [
				{ ...newerRules.data[ 0 ], id: 1, value: 'older@example.com' },
			],
		};

		await act( async () => {
			resolveSecond( newerRules );
			await secondRequest;
		} );
		expect( registry.select( rulesStore ).getRules()[ 0 ].value ).toBe(
			'newer@example.com'
		);

		await act( async () => {
			resolveFirst( olderRules );
			await firstRequest;
		} );
		expect( registry.select( rulesStore ).getRules()[ 0 ].value ).toBe(
			'newer@example.com'
		);
	} );

	it( 'ignores a stale error after the query changes', async () => {
		let rejectFirst: ( error: Error ) => void = () => {};
		let resolveSecond: ( response: RulesResponse ) => void = () => {};
		const firstResponse = new Promise< RulesResponse >( ( _, reject ) => {
			rejectFirst = reject;
		} );
		const secondResponse = new Promise< RulesResponse >( ( resolve ) => {
			resolveSecond = resolve;
		} );
		mockedApiFetch
			.mockReturnValueOnce( firstResponse )
			.mockReturnValueOnce( secondResponse );

		const registry = createRegistry();
		registry.register( rulesStore );
		const firstRequest = registry
			.dispatch( rulesStore )
			.requestRules( { page: 1, perPage: 20 } );
		const secondRequest = registry
			.dispatch( rulesStore )
			.requestRules( { page: 2, perPage: 20 } );

		await act( async () => {
			resolveSecond( {
				data: [],
				totalItems: 0,
				totalPages: 0,
				page: 2,
				perPage: 20,
			} );
			await secondRequest;
		} );
		await act( async () => {
			rejectFirst( new Error( 'The older request failed.' ) );
			await firstRequest;
		} );

		expect( registry.select( rulesStore ).getError() ).toBeNull();
	} );

	it( 'maps all supported view filters to the rules query', () => {
		const query = getQueryFromView( {
			type: 'table',
			page: 3,
			perPage: 50,
			sort: { field: 'value', direction: 'asc' },
			filters: [
				{ field: 'action', operator: 'is', value: 'block' },
				{ field: 'type', operator: 'is', value: 'ip' },
				{ field: 'value', operator: 'is', value: '198.51.100.1' },
				{
					field: 'created_at',
					operator: 'between',
					value: [ '2026-09-01T00:00:00', '2026-09-30T23:59:59' ],
				},
			],
			fields: [],
			layout: {},
		} as View );

		expect( query ).toEqual( {
			page: 3,
			perPage: 50,
			orderby: 'value',
			order: 'asc',
			action: 'block',
			type: 'ip',
			value: '198.51.100.1',
			from: '2026-09-01',
			to: '2026-09-30',
		} );
	} );

	it( 'exposes request errors and stops loading', async () => {
		mockedApiFetch.mockRejectedValueOnce(
			new Error( 'Rules unavailable.' )
		);
		const registry = createRegistry();
		registry.register( rulesStore );

		await act( async () => {
			await registry
				.dispatch( rulesStore )
				.requestRules( { page: 1, perPage: 20 } );
		} );

		expect( registry.select( rulesStore ).isLoading() ).toBe( false );
		expect( registry.select( rulesStore ).getError() ).toBe(
			'Rules unavailable.'
		);
	} );
} );
