import '@testing-library/jest-dom';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import apiFetch from '@wordpress/api-fetch';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import { RulesPage } from '../../client/admin-settings/rules-page';
import {
	rulesStore,
	type Rule,
} from '../../client/admin-settings/data/rules-store';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );
jest.mock( '@wordpress/dataviews/wp', () => ( {
	...jest.requireActual( '@wordpress/dataviews/wp' ),
	DataViews: jest.requireActual( './mocks/rules-dataviews' ).DataViews,
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

jest.setTimeout( 35_000 );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
const firstRule: Rule = {
	id: 1,
	action: 'allow',
	value: 'first@example.com',
	type: 'email',
	created_at: '2026-09-14T12:00:00Z',
	updated_at: null,
};
const secondRule: Rule = {
	...firstRule,
	id: 2,
	value: 'second@example.com',
};

function collectionResponse(): Response {
	return {
		json: async () => [ firstRule, secondRule ],
		headers: {
			get: ( name: string ) => ( name === 'X-WP-TotalPages' ? '1' : '2' ),
		},
	} as unknown as Response;
}

function renderRules() {
	const registry = createRegistry();
	registry.register( rulesStore );
	registry.register( noticesStore );
	return render(
		<MemoryRouter>
			<RegistryProvider value={ registry }>
				<RulesPage />
			</RegistryProvider>
		</MemoryRouter>
	);
}

async function chooseRuleAction( value: string, action: string ) {
	const row = ( await screen.findByText( value ) ).closest( 'tr' );
	if ( ! row ) {
		throw new Error( `Rule row not found for ${ value }.` );
	}
	await userEvent.click(
		within( row ).getByRole( 'button', { name: action } )
	);
}

async function leavePendingEdit( value: string ) {
	await chooseRuleAction( value, 'Edit' );
	expect( await screen.findByText( 'Loading rule' ) ).toBeInTheDocument();
	await userEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
	await waitFor( () =>
		expect(
			screen.queryByRole( 'dialog', { name: 'Edit rule' } )
		).not.toBeInTheDocument()
	);
}

beforeEach( () => {
	mockedApiFetch.mockReset();
} );

it( 'keeps the latest edit when an older edit request finishes', async () => {
	let resolveFirst: ( response: Rule ) => void = () => undefined;
	let resolveSecond: ( response: Rule ) => void = () => undefined;
	const firstRequest = new Promise< Rule >( ( resolve ) => {
		resolveFirst = resolve;
	} );
	const secondRequest = new Promise< Rule >( ( resolve ) => {
		resolveSecond = resolve;
	} );
	mockedApiFetch.mockImplementation( ( options ) => {
		const path = ( options as { path?: string } ).path ?? '';
		if ( path.includes( '/rules?' ) ) {
			return Promise.resolve( collectionResponse() ) as never;
		}
		return (
			path.endsWith( '/1' ) ? firstRequest : secondRequest
		) as never;
	} );
	renderRules();
	await leavePendingEdit( firstRule.value );
	await chooseRuleAction( secondRule.value, 'Edit' );
	await act( async () => resolveFirst( firstRule ) );
	expect(
		screen.queryByDisplayValue( firstRule.value )
	).not.toBeInTheDocument();
	expect( screen.getByText( 'Loading rule' ) ).toBeInTheDocument();
	await act( async () => resolveSecond( secondRule ) );
	expect( await screen.findByDisplayValue( secondRule.value ) ).toBeVisible();
} );

it( 'keeps Create open when an older edit request finishes', async () => {
	let resolveDetail: ( response: Rule ) => void = () => undefined;
	const detailRequest = new Promise< Rule >( ( resolve ) => {
		resolveDetail = resolve;
	} );
	mockedApiFetch.mockImplementation( ( options ) => {
		const path = ( options as { path?: string } ).path ?? '';
		return (
			path.includes( '/rules?' )
				? Promise.resolve( collectionResponse() )
				: detailRequest
		) as never;
	} );
	renderRules();
	await leavePendingEdit( firstRule.value );
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Create rule' } )
	);
	await act( async () => resolveDetail( firstRule ) );
	expect(
		screen.getByRole( 'dialog', { name: 'Create rule' } )
	).toBeInTheDocument();
	expect( screen.getByLabelText( 'Value' ) ).toHaveValue( '' );
} );

it( 'keeps Delete open when an older edit request finishes', async () => {
	let resolveDetail: ( response: Rule ) => void = () => undefined;
	const detailRequest = new Promise< Rule >( ( resolve ) => {
		resolveDetail = resolve;
	} );
	mockedApiFetch.mockImplementation( ( options ) => {
		const path = ( options as { path?: string } ).path ?? '';
		return (
			path.includes( '/rules?' )
				? Promise.resolve( collectionResponse() )
				: detailRequest
		) as never;
	} );
	renderRules();
	await leavePendingEdit( firstRule.value );
	await chooseRuleAction( firstRule.value, 'Delete' );
	await act( async () => resolveDetail( firstRule ) );
	expect(
		screen.getByRole( 'dialog', { name: 'Delete rule' } )
	).toBeInTheDocument();
	expect(
		screen.queryByRole( 'dialog', { name: 'Edit rule' } )
	).not.toBeInTheDocument();
} );
