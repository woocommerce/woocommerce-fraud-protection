import '@testing-library/jest-dom';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import apiFetch from '@wordpress/api-fetch';
import { createRegistry, RegistryProvider } from '@wordpress/data';
import type { View } from '@wordpress/dataviews';
import { store as noticesStore } from '@wordpress/notices';

import {
	rulesStore,
	type Rule,
} from '../../client/admin-settings/data/rules-store';
import {
	getQueryFromView,
	RulesPage,
} from '../../client/admin-settings/rules-page';
import { getUtcDateFilterBound } from '../../client/admin-settings/rule-date';
import {
	getInitialRuleFormData,
	getRuleValuePlaceholder,
	isCompleteIp,
	RuleFormDrawer,
} from '../../client/admin-settings/components/rule-form-drawer';

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
	updated_at: null,
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
	registry.register( noticesStore );
	const result = render(
		<MemoryRouter>
			<RegistryProvider value={ registry }>
				<RulesPage />
			</RegistryProvider>
		</MemoryRouter>
	);
	return { ...result, registry };
}

function renderDrawer(
	props: Partial< React.ComponentProps< typeof RuleFormDrawer > > = {}
) {
	const registry = createRegistry();
	registry.register( rulesStore );
	registry.register( noticesStore );
	const onClose = props.onClose ?? jest.fn();
	const result = render(
		<MemoryRouter>
			<RegistryProvider value={ registry }>
				<RuleFormDrawer open onClose={ onClose } { ...props } />
			</RegistryProvider>
		</MemoryRouter>
	);
	return { ...result, registry, onClose };
}

async function chooseRuleAction( value: string, action: string ) {
	const row = screen.getByText( value ).closest( 'tr' );
	if ( ! row ) {
		throw new Error( `Rule row not found for ${ value }.` );
	}
	fireEvent.mouseDown(
		within( row ).getByRole( 'button', { name: 'Actions' } )
	);
	await userEvent.click(
		await screen.findByRole( 'menuitem', { name: action } )
	);
}

beforeEach( () => {
	mockedApiFetch.mockReset();
	mockedApiFetch.mockResolvedValue( collectionResponse() as never );
} );

describe( 'RulesPage', () => {
	it( 'validates complete IPv4 and IPv6 values', () => {
		expect( isCompleteIp( '203.0.113.9' ) ).toBe( true );
		expect( isCompleteIp( '2001:db8::1' ) ).toBe( true );
		expect( isCompleteIp( '203.0.113' ) ).toBe( false );
		expect( isCompleteIp( ':::' ) ).toBe( false );
	} );

	it( 'derives contextual form values and placeholders', () => {
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
		).toEqual( { action: 'block', type: 'ip', value: '203.0.113.9' } );
		expect( getRuleValuePlaceholder( 'email' ) ).toBe(
			'e.g. j.holland@gmail.com'
		);
		expect( getRuleValuePlaceholder( 'ip' ) ).toBe(
			'e.g. 111.111.111.111'
		);
	} );

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

	it( 'keeps the row actions while a new query loads', async () => {
		// The second (filtered) request never settles: the list keeps showing
		// the previous rows while it loads, and their action menus must stay.
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockReturnValueOnce( new Promise( () => undefined ) as never );
		renderRules();
		const row = ( await screen.findByText( rule.value ) ).closest( 'tr' );
		expect(
			within( row as HTMLElement ).getByRole( 'button', {
				name: 'Actions',
			} )
		).toBeInTheDocument();

		await userEvent.click( screen.getByRole( 'tab', { name: 'Block' } ) );

		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenLastCalledWith(
				expect.objectContaining( {
					path: expect.stringContaining( 'action=block' ),
				} )
			)
		);
		const loadingRow = screen.getByText( rule.value ).closest( 'tr' );
		expect(
			within( loadingRow as HTMLElement ).getByRole( 'button', {
				name: 'Actions',
			} )
		).toBeInTheDocument();
	} );

	it( 'keeps all four columns sortable with Created descending as default', async () => {
		renderRules();
		await screen.findByText( rule.value );
		expect(
			screen.getByRole( 'columnheader', { name: /Created/ } )
		).toHaveAttribute( 'aria-sort', 'descending' );
		for ( const name of [ 'Action', 'Value', 'Rule type', 'Created' ] ) {
			expect(
				screen.getByRole( 'button', { name } )
			).toBeInTheDocument();
		}
		fireEvent.mouseDown( screen.getByRole( 'button', { name: 'Value' } ) );
		await userEvent.click(
			await screen.findByRole( 'menuitemradio', {
				name: 'Sort ascending',
			} )
		);
		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc-fraud-protection/v1/rules?page=1&per_page=20&orderby=value&order=asc',
				parse: false,
			} )
		);
		expect(
			screen.getByRole( 'button', { name: 'View options' } )
		).toBeInTheDocument();
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
		await act( async () => resolveList( collectionResponse( [] ) ) );
		expect( await screen.findByText( 'No rules' ) ).toBeInTheDocument();
		expect(
			screen.getByText( 'Any rules you create will appear here.' )
		).toBeInTheDocument();
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
		const user = userEvent.setup();
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockResolvedValueOnce( collectionResponse( [] ) as never );
		renderRules();
		await screen.findByText( rule.value );
		await user.click( screen.getByRole( 'tab', { name: 'Block' } ) );
		expect(
			await screen.findByText( 'No matching rules' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Try changing or removing your filters.' )
		).toBeInTheDocument();
	} );

	it( 'opens edit immediately and shows detail loading', async () => {
		let resolveDetail: ( response: Rule ) => void = () => undefined;
		const detailRequest = new Promise< Rule >( ( resolve ) => {
			resolveDetail = resolve;
		} );
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockReturnValueOnce( detailRequest as never );
		renderRules();
		await screen.findByText( rule.value );
		await chooseRuleAction( rule.value, 'Edit' );
		expect(
			screen.getByRole( 'heading', { name: 'Edit rule' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'Loading rule' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Value' ) ).not.toBeInTheDocument();
		await act( async () => resolveDetail( rule ) );
		expect( await screen.findByLabelText( 'Value' ) ).toHaveValue(
			rule.value
		);
	} );

	it( 'shows and retries a detail request failure', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockRejectedValueOnce( new Error( 'request failed' ) )
			.mockResolvedValueOnce( rule as never );
		renderRules();
		await screen.findByText( rule.value );
		await chooseRuleAction( rule.value, 'Edit' );
		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Retry' } )
		);
		expect( await screen.findByLabelText( 'Value' ) ).toHaveValue(
			rule.value
		);
	} );

	it( 'shows only the creation date for a rule that was never updated', async () => {
		mockedApiFetch.mockResolvedValueOnce( rule as never );
		renderDrawer( { ruleId: rule.id } );

		const metadata = await screen.findByText(
			/^This rule was created on /
		);
		expect( metadata ).not.toHaveTextContent( 'last updated' );
	} );

	it( 'shows the update date for a rule that was updated', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			...rule,
			updated_at: '2026-09-15T13:30:00Z',
		} as never );
		renderDrawer( { ruleId: rule.id } );

		expect(
			await screen.findByText( /and last updated on/ )
		).toBeInTheDocument();
	} );

	it( 'stays closed when an edit request finishes after close', async () => {
		let resolveDetail: ( response: Rule ) => void = () => undefined;
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockReturnValueOnce(
				new Promise< Rule >( ( resolve ) => {
					resolveDetail = resolve;
				} ) as never
			);
		renderRules();
		await screen.findByText( rule.value );
		await chooseRuleAction( rule.value, 'Edit' );
		fireEvent.click( screen.getByRole( 'button', { name: 'Close' } ) );
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Edit rule' } )
			).not.toBeInTheDocument()
		);
		await act( async () => resolveDetail( rule ) );
		expect(
			screen.queryByRole( 'dialog', { name: 'Edit rule' } )
		).not.toBeInTheDocument();
	} );

	it( 'rejects invalid and overlong email values through DataForm', async () => {
		const user = userEvent.setup();
		renderDrawer();
		const valueInput = screen.getByLabelText( 'Value' );
		const submit = screen.getByRole( 'button', { name: 'Create rule' } );
		await user.type( valueInput, 'not-an-email' );
		expect( ( valueInput as HTMLInputElement ).validity.typeMismatch ).toBe(
			true
		);
		await waitFor( () =>
			expect( submit ).toHaveAttribute( 'aria-disabled', 'true' )
		);
		await user.clear( valueInput );
		const overlongEmail = `${ 'a'.repeat( 250 ) }@b.com`;
		await user.paste( overlongEmail );
		expect( valueInput ).not.toHaveValue( overlongEmail );
		expect( ( valueInput as HTMLInputElement ).value ).toHaveLength( 254 );
		await user.clear( valueInput );
		await user.type( valueInput, 'buyer@internal' );
		expect( ( valueInput as HTMLInputElement ).validity.valid ).toBe(
			true
		);
		await waitFor( () =>
			expect( submit ).toHaveAttribute( 'aria-disabled', 'false' )
		);
	} );

	it( 'creates a rule and shows the mutation snackbar', async () => {
		const { onClose, registry } = renderDrawer();
		mockedApiFetch.mockResolvedValueOnce( { ...rule, id: 9 } as never );
		const valueInput = screen.getByLabelText( 'Value' );
		expect( valueInput ).toHaveAttribute( 'type', 'email' );
		expect( valueInput ).toHaveAttribute( 'maxlength', '254' );
		await userEvent.type( valueInput, 'buyer@internal' );
		expect( valueInput ).toBeValid();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);
		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
		expect( registry.select( noticesStore ).getNotices() ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					content: 'Rule created successfully.',
					type: 'snackbar',
				} ),
			] )
		);
	} );

	it( 'prevents the drawer from closing while a rule is being saved', async () => {
		let resolveCreate: ( response: Rule ) => void = () => undefined;
		mockedApiFetch.mockReturnValueOnce(
			new Promise< Rule >( ( resolve ) => {
				resolveCreate = resolve;
			} ) as never
		);
		const { onClose } = renderDrawer();
		await userEvent.type(
			screen.getByLabelText( 'Value' ),
			'buyer@example.com'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Close' } )
			).toHaveAttribute( 'aria-disabled', 'true' )
		);
		await userEvent.keyboard( '{Escape}' );
		expect( onClose ).not.toHaveBeenCalled();
		expect(
			screen.getByRole( 'dialog', { name: 'Create rule' } )
		).toBeInTheDocument();
		await act( async () => resolveCreate( { ...rule, id: 10 } ) );
		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
	} );

	it( 'shows a create failure in the drawer and stays open', async () => {
		const { onClose, registry } = renderDrawer();
		mockedApiFetch.mockRejectedValueOnce( {
			message: 'The exact create error.',
		} );
		await userEvent.type(
			screen.getByLabelText( 'Value' ),
			'failed@example.com'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);
		const drawer = screen.getByRole( 'dialog', { name: 'Create rule' } );
		expect(
			await within( drawer ).findByText( 'The exact create error.' )
		).toBeInTheDocument();
		expect( registry.select( noticesStore ).getNotices() ).toEqual( [] );
		expect( onClose ).not.toHaveBeenCalled();
		expect( drawer ).toBeInTheDocument();
	} );

	it( 'keeps contextual fields fixed and submits their source attempt', async () => {
		mockedApiFetch.mockResolvedValueOnce( { ...rule, id: 9 } as never );
		renderDrawer( {
			context: {
				recordedAttemptId: 7,
				type: 'ip',
				value: '203.0.113.9',
				finalStatus: 'allowed',
			},
		} );
		expect( screen.getByLabelText( 'Action' ) ).toHaveValue( 'block' );
		expect( screen.getByLabelText( 'Rule type' ) ).toBeDisabled();
		expect( screen.getByLabelText( 'Value' ) ).toBeDisabled();
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);
		await waitFor( () =>
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/rules',
				method: 'POST',
				data: {
					action: 'block',
					type: 'ip',
					value: '203.0.113.9',
					recorded_attempt_id: 7,
					origin: 'checkout_attempts',
				},
			} )
		);
	} );

	it( 'keeps a duplicate error beside the value and opens the existing rule', async () => {
		const onViewRule = jest.fn();
		mockedApiFetch.mockRejectedValueOnce( {
			code: 'woocommerce_fraud_protection_duplicate_rule',
			message: 'This email is already allowed by a rule.',
			data: { rule_id: 17 },
		} );
		renderDrawer( { onViewRule } );
		await userEvent.type(
			screen.getByLabelText( 'Value' ),
			'duplicate@example.com'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Create rule' } )
		);
		const drawer = screen.getByRole( 'dialog', { name: 'Create rule' } );
		await userEvent.click(
			within( drawer ).getByRole( 'button', {
				name: 'Edit existing rule',
			} )
		);
		expect( onViewRule ).toHaveBeenCalledWith( 17 );
	} );

	it( 'updates a loaded rule and shows the update snackbar', async () => {
		const updatedRule: Rule = { ...rule, action: 'block' };
		mockedApiFetch
			.mockResolvedValueOnce( rule as never )
			.mockResolvedValueOnce( updatedRule as never );
		const { onClose, registry } = renderDrawer( { ruleId: rule.id } );
		await screen.findByDisplayValue( rule.value );
		await userEvent.selectOptions(
			screen.getByLabelText( 'Action' ),
			'block'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: `/wc-fraud-protection/v1/rules/${ rule.id }`,
			method: 'PUT',
			data: {
				action: 'block',
				type: 'email',
				value: rule.value,
				origin: 'rules',
			},
		} );
		expect( registry.select( noticesStore ).getNotices() ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					content: 'Rule updated successfully.',
					type: 'snackbar',
				} ),
			] )
		);
	} );

	it( 'shows an update failure in the drawer and stays open', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( rule as never )
			.mockRejectedValueOnce( { message: 'The exact update error.' } );
		const { onClose, registry } = renderDrawer( { ruleId: rule.id } );
		await screen.findByDisplayValue( rule.value );
		await userEvent.selectOptions(
			screen.getByLabelText( 'Action' ),
			'block'
		);
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save changes' } )
		);
		const drawer = screen.getByRole( 'dialog', { name: 'Edit rule' } );
		expect(
			await within( drawer ).findByText( 'The exact update error.' )
		).toBeInTheDocument();
		expect( registry.select( noticesStore ).getNotices() ).toEqual( [] );
		expect( onClose ).not.toHaveBeenCalled();
		expect( drawer ).toBeInTheDocument();
	} );

	it( 'keeps the delete dialog open after a delete failure', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockRejectedValueOnce( { message: 'The exact delete error.' } );
		renderRules();
		await screen.findByText( rule.value );
		await chooseRuleAction( rule.value, 'Delete' );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete rule',
		} );
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Delete' } )
		);
		expect(
			await within( dialog ).findByText( 'The exact delete error.' )
		).toBeInTheDocument();
	} );

	it( 'deletes a rule and shows the delete snackbar', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( collectionResponse() as never )
			.mockResolvedValueOnce( undefined as never );
		const { registry } = renderRules();
		await screen.findByText( rule.value );
		await chooseRuleAction( rule.value, 'Delete' );
		const dialog = await screen.findByRole( 'dialog', {
			name: 'Delete rule',
		} );
		await userEvent.click(
			within( dialog ).getByRole( 'button', { name: 'Delete' } )
		);
		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Delete rule' } )
			).toBeNull()
		);
		expect( mockedApiFetch ).toHaveBeenCalledWith( {
			path: `/wc-fraud-protection/v1/rules/${ rule.id }?origin=rules`,
			method: 'DELETE',
		} );
		expect( registry.select( noticesStore ).getNotices() ).toEqual(
			expect.arrayContaining( [
				expect.objectContaining( {
					content: 'Rule deleted.',
					type: 'snackbar',
				} ),
			] )
		);
	} );
} );
