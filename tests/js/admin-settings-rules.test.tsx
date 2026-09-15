import '@testing-library/jest-dom';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import apiFetch from '@wordpress/api-fetch';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import type { View } from '@wordpress/dataviews';
import { store as noticesStore } from '@wordpress/notices';

import { rulesStore } from '../../client/admin-settings/data/rules-store';
import {
	getQueryFromView,
	getUtcDateFilterBound,
	RulesPage,
} from '../../client/admin-settings/rules-page';
import {
	getInitialRuleFormData,
	getRuleValuePlaceholder,
	isCompleteIp,
	RuleFormDrawer,
} from '../../client/admin-settings/components/rule-form-drawer';
import type { RuleFormContext } from '../../client/admin-settings/components/rule-form-drawer';
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

const renderDrawer = (
	onClose = jest.fn(),
	onSuccess = jest.fn(),
	context?: RuleFormContext
) => {
	const registry = createRegistry();
	registry.register( rulesStore );
	registry.register( noticesStore );
	render(
		<MemoryRouter>
			<RegistryProvider value={ registry }>
				<RuleFormDrawer
					open
					onClose={ onClose }
					onSuccess={ onSuccess }
					context={ context }
				/>
			</RegistryProvider>
		</MemoryRouter>
	);
	return { onClose, onSuccess, registry };
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

	it( 'validates complete IPv4 and IPv6 values', () => {
		expect( isCompleteIp( '203.0.113.9' ) ).toBe( true );
		expect( isCompleteIp( '2001:db8::1' ) ).toBe( true );
		expect( isCompleteIp( '203.0.113' ) ).toBe( false );
		expect( isCompleteIp( ':::' ) ).toBe( false );
		expect( isCompleteIp( '2001:db8:0:0:0:0:0:0:1' ) ).toBe( false );
	} );

	it( 'derives contextual action and keeps contextual fields fixed', () => {
		expect( getInitialRuleFormData() ).toEqual( {
			action: 'allow',
			type: 'email',
			value: '',
		} );
		expect(
			getInitialRuleFormData( {
				recordedAttemptId: 7,
				type: 'ip',
				value: '203.0.113.9',
				finalStatus: 'allowed',
			} )
		).toEqual( {
			action: 'block',
			type: 'ip',
			value: '203.0.113.9',
		} );
		expect( getRuleValuePlaceholder( 'email' ) ).toBe(
			'e.g. j.holland@gmail.com'
		);
		expect( getRuleValuePlaceholder( 'ip' ) ).toBe(
			'e.g. 111.111.111.111'
		);
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

	it( 'keeps all rule columns sortable with newest-created default order', async () => {
		renderRules();

		await waitFor( () => expect( mockedApiFetch ).toHaveBeenCalled() );

		expect( dataViews.props?.view?.sort ).toEqual( {
			field: 'created_at',
			direction: 'desc',
		} );
		expect( dataViews.props?.fields ).toHaveLength( 4 );
		expect(
			dataViews.props?.fields?.every(
				( field ) => field.enableSorting !== false
			)
		).toBe( true );
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

	it( 'uses the Created filter label', async () => {
		renderRules();
		await waitFor( () => expect( mockedApiFetch ).toHaveBeenCalled() );

		expect(
			dataViews.props?.fields?.find(
				( field ) => field.id === 'created_at'
			)?.label
		).toBe( 'Created' );
	} );

	it( 'uses the DataViews loading state while the initial request loads', async () => {
		let resolveRequest: ( response: RulesResponse ) => void = () => {};
		mockedApiFetch.mockReturnValueOnce(
			new Promise< RulesResponse >( ( resolve ) => {
				resolveRequest = resolve;
			} )
		);

		renderRules();

		expect(
			await screen.findByText( 'Loading rules' )
		).toBeInTheDocument();
		expect( dataViews.props?.data ).toHaveLength( 0 );
		expect( dataViews.props?.isLoading ).toBe( true );
		expect( screen.queryByText( 'No rules' ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( 'Loading rules' ).closest( '[aria-busy="true"]' )
		).toBeInTheDocument();

		await act( async () => {
			resolveRequest( {
				data: [],
				totalItems: 0,
				totalPages: 0,
				page: 1,
				perPage: 20,
			} );
		} );
	} );

	it( 'shows a prefixed load error below the tabs', async () => {
		mockedApiFetch.mockRejectedValueOnce(
			new Error( 'Could not get a valid response from the server.' )
		);

		renderRules();

		const tabs = screen.getByRole( 'tablist' );
		const toolbar = tabs.closest( '.wc-fraud-protection-rules__toolbar' );
		await waitFor( () =>
			expect( toolbar?.nextElementSibling ).toHaveTextContent(
				'The fraud prevention rules could not be loaded. Could not get a valid response from the server.'
			)
		);
		expect( screen.queryByText( 'No rules' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the rules empty state without actions', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			data: [],
			totalItems: 0,
			totalPages: 0,
			page: 1,
			perPage: 20,
		} );

		renderRules();

		expect( await screen.findByText( 'No rules' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Any custom rules you create will appear here.' )
		).toBeInTheDocument();
	} );

	it( 'shows a separate empty state when filters match no rules', async () => {
		renderRules();

		expect(
			await screen.findByText( 'shopper@example.com' )
		).toBeInTheDocument();
		mockedApiFetch.mockResolvedValueOnce( {
			data: [],
			totalItems: 0,
			totalPages: 0,
			page: 1,
			perPage: 20,
		} );

		await userEvent.click( screen.getByRole( 'tab', { name: 'Block' } ) );

		expect(
			await screen.findByText( 'No matching rules' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Try changing or removing your filters.' )
		).toBeInTheDocument();
		expect( screen.queryByText( 'No rules' ) ).not.toBeInTheDocument();
	} );

	it( 'converts local filter dates to inclusive UTC bounds', () => {
		expect( getUtcDateFilterBound( '2026-09-15', false ) ).toBe(
			'2026-09-15T04:00:00Z'
		);
		expect( getUtcDateFilterBound( '2026-09-15', true ) ).toBe(
			'2026-09-16T03:59:59Z'
		);
	} );

	it( 'selects All when DataViews removes the action filter', async () => {
		renderRules();
		await waitFor( () => expect( mockedApiFetch ).toHaveBeenCalled() );

		await userEvent.click( screen.getByRole( 'tab', { name: 'Block' } ) );
		await waitFor( () =>
			expect(
				screen.getByRole( 'tab', { name: 'Block' } )
			).toHaveAttribute( 'aria-selected', 'true' )
		);

		const currentView = dataViews.props?.view;
		act( () => {
			dataViews.props?.onChangeView?.( {
				...currentView,
				filters: [],
			} as View );
		} );

		await waitFor( () =>
			expect(
				screen.getByRole( 'tab', { name: 'All' } )
			).toHaveAttribute( 'aria-selected', 'true' )
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

	it( 'ignores an older success after a newer request refreshes the same query', async () => {
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
		const firstRequest = registry
			.dispatch( rulesStore )
			.requestRules( query );
		const secondRequest = registry
			.dispatch( rulesStore )
			.requestRules( query );
		const newerRules = {
			data: [
				{
					id: 2,
					action: 'allow' as const,
					value: 'newer@example.com',
					type: 'email' as const,
					created_at: '2026-09-15T12:00:00Z',
				},
			],
			totalItems: 1,
			totalPages: 1,
			page: 1,
			perPage: 20,
		};

		await act( async () => {
			resolveSecond( newerRules );
			await secondRequest;
		} );
		await act( async () => {
			resolveFirst( {
				...newerRules,
				data: [
					{
						...newerRules.data[ 0 ],
						id: 1,
						value: 'older@example.com',
					},
				],
			} );
			await firstRequest;
		} );

		expect( registry.select( rulesStore ).getRules() ).toEqual(
			newerRules.data
		);
	} );

	it( 'ignores an older failure after a newer request refreshes the same query', async () => {
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
		const query = { page: 1, perPage: 20 };
		const firstRequest = registry
			.dispatch( rulesStore )
			.requestRules( query );
		const secondRequest = registry
			.dispatch( rulesStore )
			.requestRules( query );

		await act( async () => {
			resolveSecond( {
				data: [],
				totalItems: 0,
				totalPages: 0,
				page: 1,
				perPage: 20,
			} );
			await secondRequest;
		} );
		await act( async () => {
			rejectFirst( new Error( 'The older request failed.' ) );
			await firstRequest;
		} );

		expect( registry.select( rulesStore ).getError() ).toBeNull();
		expect( registry.select( rulesStore ).isLoading() ).toBe( false );
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

	it( 'clears previous rows when a different query fails', async () => {
		const registry = createRegistry();
		registry.register( rulesStore );

		await act( async () => {
			await registry
				.dispatch( rulesStore )
				.requestRules( { page: 1, perPage: 20 } );
		} );
		expect( registry.select( rulesStore ).getRules() ).toHaveLength( 1 );

		mockedApiFetch.mockRejectedValueOnce(
			new Error( 'Rules unavailable.' )
		);
		await act( async () => {
			await registry.dispatch( rulesStore ).requestRules( {
				page: 1,
				perPage: 20,
				action: 'block',
			} );
		} );

		expect( registry.select( rulesStore ).getRules() ).toEqual( [] );
		expect( registry.select( rulesStore ).getTotalItems() ).toBe( 0 );
		expect( registry.select( rulesStore ).getTotalPages() ).toBe( 0 );
		expect( registry.select( rulesStore ).getError() ).toBe(
			'Rules unavailable.'
		);
	} );

	it( 'rejects an invalid successful response', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			data: null,
			totalItems: 0,
			totalPages: 0,
		} );
		const registry = createRegistry();
		registry.register( rulesStore );

		await act( async () => {
			await registry
				.dispatch( rulesStore )
				.requestRules( { page: 1, perPage: 20 } );
		} );

		expect( registry.select( rulesStore ).getRules() ).toEqual( [] );
		expect( registry.select( rulesStore ).getError() ).toBe(
			'Could not get a valid response from the server.'
		);
	} );

	it( 'opens the create rule drawer from the rules page', async () => {
		renderRules();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Create rule' } )
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Rule type' ) ).toHaveDisplayValue(
			'email address'
		);
		expect( screen.getByLabelText( 'Value' ) ).toBeInTheDocument();
	} );

	it( 'submits the drawer, closes it, refreshes rules, and shows the success snackbar', async () => {
		const onClose = jest.fn();
		const onSuccess = jest.fn();
		const { registry } = renderDrawer( onClose, onSuccess );
		mockedApiFetch
			.mockResolvedValueOnce( {
				id: 9,
				action: 'allow',
				value: 'created@example.com',
				type: 'email',
				created_at: '2026-09-14T12:00:00Z',
			} )
			.mockResolvedValueOnce( {
				data: [],
				totalItems: 0,
				totalPages: 0,
			} );

		await userEvent.type(
			screen.getByLabelText( /Value/ ),
			'created@example.com'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);

		await waitFor( () => expect( onClose ).toHaveBeenCalledTimes( 1 ) );
		expect( onSuccess ).toHaveBeenCalledTimes( 1 );
		expect( mockedApiFetch ).toHaveBeenNthCalledWith( 1, {
			path: '/wc-fraud-protection/v1/rules',
			method: 'POST',
			data: {
				action: 'allow',
				type: 'email',
				value: 'created@example.com',
				origin: 'rules',
			},
		} );
		expect( mockedApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wc-fraud-protection/v1/rules?page=1&per_page=20',
		} );
		expect( registry.select( noticesStore ).getNotices() ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					content: 'Rule created successfully',
					type: 'snackbar',
				} ),
			] )
		);
	} );

	it( 'keeps a duplicate error in the drawer and clears it after a value change', async () => {
		const onClose = jest.fn();
		mockedApiFetch.mockRejectedValueOnce( {
			code: 'woocommerce_fraud_protection_duplicate_rule',
			message: 'This email is already allowed by a rule.',
		} );
		renderDrawer( onClose );

		const value = screen.getByLabelText( /Value/ );
		await userEvent.type( value, 'duplicate@example.com' );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);

		const drawer = screen.getByRole( 'dialog', { name: 'Create rule' } );
		const error = await within( drawer ).findByText(
			'This email is already allowed by a rule.'
		);
		const duplicateValue = within( drawer ).getByLabelText( 'Value' );
		expect( error.tagName ).toBe( 'P' );
		expect( error.querySelector( 'svg[height="16"]' ) ).toBeInTheDocument();
		expect( error.previousElementSibling ).toContainElement(
			duplicateValue
		);
		expect( duplicateValue ).toHaveAttribute( 'aria-invalid', 'true' );
		expect( duplicateValue ).toHaveAttribute(
			'aria-describedby',
			error.id
		);
		expect( duplicateValue ).not.toHaveAttribute( 'data-validity-visible' );
		expect(
			within( drawer ).getAllByText(
				'This email is already allowed by a rule.'
			)
		).toHaveLength( 1 );
		expect( onClose ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'button', { name: 'Create rule' } )
		).toHaveAttribute( 'aria-disabled', 'true' );

		await userEvent.type( duplicateValue, 'x' );
		expect(
			screen.queryByText( 'This email is already allowed by a rule.' )
		).not.toBeInTheDocument();
	} );

	it( 'keeps contextual rule type and value fixed', () => {
		renderDrawer( jest.fn(), jest.fn(), {
			recordedAttemptId: 7,
			type: 'ip',
			value: '203.0.113.9',
			finalStatus: 'allowed',
		} );

		expect( screen.getByLabelText( 'Action' ) ).toHaveValue( 'block' );
		expect( screen.getByLabelText( 'Rule type' ) ).toBeDisabled();
		expect( screen.getByLabelText( /Value/ ) ).toBeDisabled();
		expect( screen.getByLabelText( /Value/ ) ).toHaveValue( '203.0.113.9' );
	} );

	it( 'submits contextual values and shows a duplicate on the disabled Value field', async () => {
		mockedApiFetch.mockRejectedValueOnce( {
			code: 'woocommerce_fraud_protection_duplicate_rule',
			message: 'This email is already allowed by a rule.',
		} );
		renderDrawer( jest.fn(), jest.fn(), {
			recordedAttemptId: 7,
			type: 'email',
			value: 'customer@example.com',
			finalStatus: 'blocked',
		} );

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);

		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/rules',
			method: 'POST',
			data: {
				action: 'allow',
				type: 'email',
				value: 'customer@example.com',
				recorded_attempt_id: 7,
				origin: 'checkout_attempts',
			},
		} );
		expect(
			await screen.findByText(
				'This email is already allowed by a rule.'
			)
		).toBeInTheDocument();
		expect( screen.getByLabelText( 'Value' ) ).toBeDisabled();
		expect(
			screen.getByRole( 'button', { name: 'Create rule' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	it.each( [
		[ 'email', 'incomplete', 'Enter a complete email address.' ],
		[ 'ip', '203.0.113', 'Enter a complete IP address.' ],
	] )(
		'does not submit an invalid %s value',
		async ( type, value, message ) => {
			renderDrawer();
			if ( type === 'ip' ) {
				await userEvent.selectOptions(
					screen.getByLabelText( 'Rule type' ),
					'ip'
				);
			}
			await userEvent.type( screen.getByLabelText( 'Value' ), value );
			await userEvent.tab();

			expect( await screen.findByText( message ) ).toBeInTheDocument();
			expect(
				screen.getByRole( 'button', { name: 'Create rule' } )
			).toHaveAttribute( 'aria-disabled', 'true' );
			expect( mockedApiFetch ).not.toHaveBeenCalled();
		}
	);

	it( 'sends the exact create request through the rules store', async () => {
		const registry = createRegistry();
		registry.register( rulesStore );
		const createdRule = {
			id: 9,
			action: 'allow' as const,
			value: 'shopper@example.com',
			type: 'email' as const,
			created_at: '2026-09-14T12:00:00Z',
		};
		mockedApiFetch
			.mockResolvedValueOnce( createdRule )
			.mockResolvedValueOnce( {
				data: [],
				totalItems: 0,
				totalPages: 0,
				page: 1,
				perPage: 20,
			} );

		await act( async () => {
			await registry.dispatch( rulesStore ).createRule( {
				action: 'allow',
				type: 'email',
				value: 'shopper@example.com',
				origin: 'rules',
			} );
		} );

		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-fraud-protection/v1/rules',
			method: 'POST',
			data: {
				action: 'allow',
				type: 'email',
				value: 'shopper@example.com',
				origin: 'rules',
			},
		} );
	} );
} );
