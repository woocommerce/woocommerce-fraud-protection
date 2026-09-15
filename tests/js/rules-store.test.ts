import apiFetch from '@wordpress/api-fetch';
import { createRegistry } from '@wordpress/data';

import {
	normalizeRulesQuery,
	rulesStore,
	type Rule,
	type RulesQuery,
} from '../../client/admin-settings/data/rules-store';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const rule: Rule = {
	id: 9,
	action: 'allow',
	value: 'shopper@example.com',
	type: 'email',
	created_at: '2026-09-14T12:00:00Z',
};

const collectionResponse = (
	data: unknown = [ rule ],
	total: string | null = '1',
	totalPages: string | null = '1'
): Response =>
	( {
		json: jest.fn().mockResolvedValue( data ),
		headers: {
			get: ( name: string ) => {
				if ( name === 'X-WP-Total' ) {
					return total;
				}
				if ( name === 'X-WP-TotalPages' ) {
					return totalPages;
				}
				return null;
			},
		},
	} ) as unknown as Response;

const setupRegistry = () => {
	const registry = createRegistry();
	registry.register( rulesStore );
	return registry;
};

describe( 'rulesStore', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
	} );

	it( 'applies query defaults and omits empty optional values', () => {
		expect(
			normalizeRulesQuery( {
				page: 0,
				perPage: 0,
				action: '',
				type: 'email',
				value: '',
			} )
		).toEqual( {
			page: 1,
			perPage: 20,
			type: 'email',
		} );
	} );

	it( 'resolves and caches collection responses by normalized query', async () => {
		const registry = setupRegistry();
		const query = {
			page: 1,
			perPage: 20,
			action: '',
		} as RulesQuery;
		mockedApiFetch.mockResolvedValueOnce(
			collectionResponse( [ rule ], '23', '2' )
		);

		await expect(
			registry.resolveSelect( rulesStore ).getRules( query )
		).resolves.toEqual( [ rule ] );
		await expect(
			registry.resolveSelect( rulesStore ).getRules( {
				page: 1,
				perPage: 20,
			} )
		).resolves.toEqual( [ rule ] );

		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/rules?page=1&per_page=20',
			parse: false,
		} );
		expect( registry.select( rulesStore ).getTotalItems( query ) ).toBe(
			23
		);
		expect( registry.select( rulesStore ).getTotalPages( query ) ).toBe(
			2
		);
	} );

	it( 'keeps separate collection results for separate queries', async () => {
		const registry = setupRegistry();
		const firstQuery = { page: 1, perPage: 20 };
		const secondQuery = { page: 2, perPage: 20 };
		const secondRule = { ...rule, id: 10, value: 'other@example.com' };
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse( [ rule ], '2', '2' ) )
			.mockResolvedValueOnce(
				collectionResponse( [ secondRule ], '2', '2' )
			);

		await registry.resolveSelect( rulesStore ).getRules( firstQuery );
		await registry.resolveSelect( rulesStore ).getRules( secondQuery );

		expect( registry.select( rulesStore ).getRules( firstQuery ) ).toEqual(
			[ rule ]
		);
		expect( registry.select( rulesStore ).getRules( secondQuery ) ).toEqual(
			[ secondRule ]
		);
	} );

	it.each( [
		[ 'a non-array body', collectionResponse( { data: [ rule ] } ) ],
		[ 'an invalid rule', collectionResponse( [ { ...rule, id: '9' } ] ) ],
		[ 'a missing total', collectionResponse( [ rule ], null, '1' ) ],
		[ 'a negative total', collectionResponse( [ rule ], '-1', '1' ) ],
		[ 'a decimal total', collectionResponse( [ rule ], '1.5', '1' ) ],
		[ 'a missing page total', collectionResponse( [ rule ], '1', null ) ],
	] )( 'records a resolution error for %s', async ( _, response ) => {
		const registry = setupRegistry();
		const query = { page: 1, perPage: 20 };
		mockedApiFetch.mockResolvedValueOnce( response );

		await expect(
			registry.resolveSelect( rulesStore ).getRules( query )
		).rejects.toThrow( 'Could not get a valid response from the server.' );
		expect(
			registry
				.select( rulesStore )
				.getResolutionError( 'getRules', [ query ] )
		).toEqual(
			expect.objectContaining( {
				message: 'Could not get a valid response from the server.',
			} )
		);
	} );

	it( 'records a generic resolution error when collection JSON cannot be parsed', async () => {
		const registry = setupRegistry();
		const query = { page: 1, perPage: 20 };
		const response = collectionResponse();
		( response.json as jest.Mock ).mockRejectedValueOnce(
			new SyntaxError( 'Unexpected token' )
		);
		mockedApiFetch.mockResolvedValueOnce( response );

		await expect(
			registry.resolveSelect( rulesStore ).getRules( query )
		).rejects.toThrow( 'Could not get a valid response from the server.' );
	} );
} );
