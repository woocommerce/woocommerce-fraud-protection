import '@testing-library/jest-dom';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import apiFetch from '@wordpress/api-fetch';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import type { View } from '@wordpress/dataviews';

import {
	rulesStore,
	type Rule,
} from '../../client/admin-settings/data/rules-store';
import {
	getQueryFromView,
	RulesPage,
} from '../../client/admin-settings/rules-page';
import { getUtcDateFilterBound } from '../../client/admin-settings/rule-date';
import { dataViews } from './mocks/dataviews';

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
const rule: Rule = {
	id: 1,
	action: 'allow',
	value: 'shopper@example.com',
	type: 'email',
	created_at: '2026-09-14T12:00:00Z',
};

function collectionResponse(
	items: Rule[] = [ rule ],
	total = items.length,
	pages = total ? 1 : 0
): Response {
	const responseHeaders: Record< string, string > = {
		'X-WP-Total': String( total ),
		'X-WP-TotalPages': String( pages ),
	};
	return {
		json: async () => items,
		headers: {
			get: ( name: string ) => responseHeaders[ name ] ?? null,
		},
	} as unknown as Response;
}

function renderRules() {
	const registry = createRegistry();
	registry.register( rulesStore );
	return render(
		<MemoryRouter>
			<RegistryProvider value={ registry }>
				<RulesPage />
			</RegistryProvider>
		</MemoryRouter>
	);
}

beforeEach( () => {
	mockedApiFetch.mockReset();
	mockedApiFetch.mockResolvedValue( collectionResponse() as never );
	dataViews.props = undefined;
} );

describe( 'RulesPage', () => {
	it( 'maps filters and one active sort to the query', () => {
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
					value: [ '2026-09-01', '2026-09-30' ],
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
			from: getUtcDateFilterBound( '2026-09-01', false ),
			to: getUtcDateFilterBound( '2026-09-30', true ),
		} );
	} );

	it( 'converts browser dates to inclusive UTC bounds', () => {
		expect( getUtcDateFilterBound( '2026-09-15', false ) ).toBe(
			'2026-09-15T04:00:00Z'
		);
		expect( getUtcDateFilterBound( '2026-09-15', true ) ).toBe(
			'2026-09-16T03:59:59Z'
		);
		expect( getUtcDateFilterBound( '2026-02-30', false ) ).toBeUndefined();
	} );

	it( 'loads rules through the resolver and changes the action filter', async () => {
		renderRules();
		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&orderby=created_at&order=desc',
				parse: false,
			} )
		);
		expect( await screen.findByText( rule.value ) ).toBeInTheDocument();
		await userEvent.click( screen.getByRole( 'tab', { name: 'Block' } ) );
		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&action=block&orderby=created_at&order=desc',
				parse: false,
			} )
		);
	} );

	it( 'keeps all four columns sortable with Created descending as default', async () => {
		renderRules();
		await waitFor( () =>
			expect( dataViews.props?.fields ).toHaveLength( 4 )
		);
		expect( dataViews.props?.view?.sort ).toEqual( {
			field: 'created_at',
			direction: 'desc',
		} );
		expect(
			dataViews.props?.fields?.every(
				( field ) => field.enableSorting !== false
			)
		).toBe( true );
		act(
			() =>
				dataViews.props?.onChangeView?.( {
					...dataViews.props?.view,
					sort: { field: 'value', direction: 'asc' },
				} as View )
		);
		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&orderby=value&order=asc',
				parse: false,
			} )
		);
	} );

	it( 'shows list loading, empty, and error states from resolver metadata', async () => {
		let resolveList: ( response: Response ) => void = () => undefined;
		mockedApiFetch.mockReturnValueOnce(
			new Promise< Response >( ( resolve ) => {
				resolveList = resolve;
			} ) as never
		);
		const { unmount } = renderRules();
		expect(
			await screen.findByText( 'Loading rules' )
		).toBeInTheDocument();
		expect( dataViews.props?.isLoading ).toBe( true );
		await act( async () => resolveList( collectionResponse( [] ) ) );
		expect( await screen.findByText( 'No rules' ) ).toBeInTheDocument();
		unmount();

		mockedApiFetch.mockReset();
		mockedApiFetch.mockRejectedValueOnce(
			new Error( 'Rules unavailable.' )
		);
		renderRules();
		expect(
			await screen.findAllByText(
				'The fraud prevention rules could not be loaded. Rules unavailable.'
			)
		).not.toHaveLength( 0 );
		expect( screen.queryByText( 'No rules' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the filtered empty state when no rules match', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockResolvedValueOnce( collectionResponse( [] ) as never );
		renderRules();
		await screen.findByText( rule.value );
		act(
			() =>
				dataViews.props?.onChangeView?.( {
					...dataViews.props?.view,
					filters: [ { field: 'type', operator: 'is', value: 'ip' } ],
					page: 1,
				} as View )
		);
		expect(
			await screen.findByText( 'No matching rules' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Try changing or removing your filters.' )
		).toBeInTheDocument();
	} );
} );
