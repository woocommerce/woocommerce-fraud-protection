import '@testing-library/jest-dom';
import { act, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';

import apiFetch from '@wordpress/api-fetch';
import {
	createReduxStore,
	createRegistry,
	RegistryProvider,
} from '@wordpress/data';

import { FraudProtectionSettingsPage } from '../../client/admin-settings/settings-page';
import {
	type Performance,
	settingsStore,
} from '../../client/admin-settings/data/store';
import { rulesStore } from '../../client/admin-settings/data/rules-store';

const mockCreateSuccessNotice = jest.fn();
const mockSettingsHistory = { block: jest.fn( () => jest.fn() ) };
function mockGetNewPath( query: { page: string; tab: string }, path: string ) {
	const route = new URLSearchParams( query );
	if ( path !== '/' ) {
		route.set( 'path', path );
	}

	return `/wp-admin/admin.php?${ route.toString() }`;
}
const noticesStore = createReduxStore( 'core/notices', {
	reducer: ( state = null ) => state,
	actions: {
		createSuccessNotice: (
			content: string,
			options: { type: 'snackbar' }
		) => {
			mockCreateSuccessNotice( content, options );
			return { type: 'CREATE_SUCCESS_NOTICE' };
		},
	},
} );

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
} ) );

jest.mock( '@wordpress/notices', () => ( {
	store: { name: 'core/notices' },
} ) );

jest.mock( '@woocommerce/navigation', () => ( {
	getHistory: () => mockSettingsHistory,
	getNewPath: mockGetNewPath,
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
const settingsFetchCount = () =>
	mockedApiFetch.mock.calls.filter(
		( [ options ] ) =>
			( options as { path?: string } )?.path ===
			'/wc-fraud-protection/v1/settings'
	).length;

const zeroPerformance: Performance = {
	flagged_by_fraud_prevention: 0,
	blocked_automatically: 0,
	allowed_by_rules: 0,
	blocked_by_rules: 0,
};

const settingsResponse = (
	automaticProtection: boolean,
	performance: Performance = zeroPerformance,
	optedOut = false
) => ( {
	automatic_protection: automaticProtection,
	automatic_protection_opted_out: optedOut,
	automatic_protection_enabled_at: automaticProtection
		? '2026-04-20T00:00:00'
		: null,
	performance,
} );

const performanceWithFlagged = ( flaggedByFraudPrevention: number ) => ( {
	...zeroPerformance,
	flagged_by_fraud_prevention: flaggedByFraudPrevention,
} );

const findVisibleText = async ( text: string ) => {
	const matches = await screen.findAllByText( text, { exact: true } );
	const visibleMatches = matches.filter(
		( element ) => ! element.hasAttribute( 'aria-live' )
	);

	expect( visibleMatches ).toHaveLength( 1 );
	return visibleMatches[ 0 ];
};

const renderSettings = () => {
	const registry = createRegistry();
	registry.register( settingsStore );
	registry.register( rulesStore );
	registry.register( noticesStore );

	return render(
		<MemoryRouter>
			<RegistryProvider value={ registry }>
				<FraudProtectionSettingsPage />
			</RegistryProvider>
		</MemoryRouter>
	);
};

describe( 'FraudProtectionSettingsPage', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockCreateSuccessNotice.mockReset();
		mockSettingsHistory.block.mockClear();
		window.history.replaceState( {}, '', '/' );
	} );

	it( 'shows the Rules card controls without a rule count', async () => {
		mockedApiFetch.mockResolvedValueOnce( settingsResponse( false ) );
		renderSettings();

		const rulesCard = (
			await screen.findByRole( 'heading', { name: 'Rules' } )
		).closest( 'section' );
		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' );
		expect( rulesCard ).not.toBeNull();
		expect( performanceCard ).not.toBeNull();
		expect( rulesCard?.nextElementSibling ).toBe( performanceCard );
		const rules = within( rulesCard as HTMLElement );

		expect(
			rules.getByText( /Create rules to always allow/ )
		).toHaveTextContent(
			'Create rules to always allow or block checkout attempts that match specific criteria. Rules take priority over automatic fraud prevention and allow rules override block rules. See our best practices.'
		);
		expect(
			rules.getByRole( 'link', { name: 'best practices' } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/fraud-protection/'
		);
		expect(
			rules.getByRole( 'button', { name: 'Create rule' } )
		).toBeVisible();
		expect(
			rules.getByRole( 'link', { name: 'View rules' } )
		).toBeVisible();
		expect( rules.queryByText( /^\d+ rules?$/ ) ).not.toBeInTheDocument();
	} );

	it( 'creates a rule from the Rules card and refreshes with a success toast', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( settingsResponse( false ) )
			.mockResolvedValueOnce( {
				id: 18,
				action: 'allow',
				type: 'email',
				value: 'card@example.com',
				created_at: '2026-09-15T12:00:00Z',
				updated_at: null,
			} );
		renderSettings();

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Create rule' } )
		);
		const drawer = await screen.findByRole( 'dialog', {
			name: 'Create rule',
		} );
		await userEvent.type(
			within( drawer ).getByLabelText( 'Value' ),
			'card@example.com'
		);
		await userEvent.click(
			within( drawer ).getByRole( 'button', { name: 'Create rule' } )
		);

		await waitFor( () =>
			expect(
				screen.queryByRole( 'dialog', { name: 'Create rule' } )
			).not.toBeInTheDocument()
		);
		expect( mockedApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wc-fraud-protection/v1/rules',
			method: 'POST',
			data: {
				action: 'allow',
				type: 'email',
				value: 'card@example.com',
				origin: 'rules',
			},
		} );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Rule created successfully.',
			{ type: 'snackbar' }
		);
	} );

	it( 'opens an existing duplicate rule in the Edit drawer', async () => {
		const duplicate = {
			id: 17,
			action: 'allow',
			type: 'email',
			value: 'duplicate@example.com',
			created_at: '2026-09-15T12:00:00Z',
			updated_at: null,
		};
		mockedApiFetch
			.mockResolvedValueOnce( settingsResponse( false ) )
			.mockRejectedValueOnce( {
				code: 'woocommerce_fraud_protection_duplicate_rule',
				message: 'This email is already allowed by a rule.',
				data: { rule_id: duplicate.id },
			} )
			.mockResolvedValueOnce( duplicate );
		renderSettings();

		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Create rule' } )
		);
		let drawer = await screen.findByRole( 'dialog', {
			name: 'Create rule',
		} );
		await userEvent.type(
			within( drawer ).getByLabelText( 'Value' ),
			duplicate.value
		);
		await userEvent.click(
			within( drawer ).getByRole( 'button', { name: 'Create rule' } )
		);
		await userEvent.click(
			await within( drawer ).findByRole( 'button', {
				name: 'Edit existing rule',
			} )
		);

		drawer = await screen.findByRole( 'dialog', { name: 'Edit rule' } );
		expect( within( drawer ).getByLabelText( 'Value' ) ).toHaveValue(
			duplicate.value
		);
	} );

	it( 'disables controls, ignores Save, and renders the disabled value while loading', async () => {
		let resolveLoad: (
			response: ReturnType< typeof settingsResponse >
		) => void = () => {};
		mockedApiFetch.mockReturnValueOnce(
			new Promise< ReturnType< typeof settingsResponse > >(
				( resolve ) => {
					resolveLoad = resolve;
				}
			)
		);
		renderSettings();

		const save = screen.getByRole( 'button', { name: 'Save' } );
		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' );
		expect( screen.queryByRole( 'checkbox' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'presentation' ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'status' ) ).toHaveLength( 2 );
		expect(
			screen.getByText( 'Loading automatic fraud prevention setting.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Loading performance results.' )
		).toBeInTheDocument();
		expect( performanceCard?.querySelector( 'dl' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
		expect(
			performanceCard?.querySelectorAll(
				'.wc-fraud-protection-settings__performance-skeleton'
			)
		).toHaveLength( 4 );
		expect(
			performanceCard?.querySelector(
				'.wc-fraud-protection-settings__performance-caution-icon'
			)
		).not.toBeInTheDocument();
		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByRole( 'heading', { name: 'Fraud prevention' } )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Fraud prevention scans checkout attempts for potentially automated or malicious shopper behavior. Flagged checkout attempts are recorded by default and are only blocked when automatic blocking is turned on.'
			)
		).toBeInTheDocument();
		await waitFor( () => {
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/settings',
			} );
		} );
		// Save is disabled while loading, so this click must not start a save request.
		await userEvent.click( save );
		expect( settingsFetchCount() ).toBe( 1 );

		await act( async () => {
			resolveLoad( settingsResponse( false ) );
		} );
		const checkbox = await screen.findByRole( 'checkbox' );
		expect( checkbox ).not.toBeChecked();
		expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.queryByRole( 'presentation' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		expect( screen.getAllByText( '0' ) ).toHaveLength( 4 );
		expect(
			performanceCard?.querySelector(
				'.wc-fraud-protection-settings__performance-caution-icon'
			)
		).not.toBeInTheDocument();
	} );

	it( 'loads the enabled value', async () => {
		mockedApiFetch.mockResolvedValueOnce( settingsResponse( true ) );
		renderSettings();

		await waitFor( () => {
			const checkbox = screen.getByRole( 'checkbox', {
				name: 'Automatically block checkout attempts flagged by fraud prevention.',
			} );
			expect( checkbox ).toBeChecked();
			expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' );
		} );
		expect(
			screen.getByRole( 'button', { name: 'Save' } )
		).toHaveAttribute( 'aria-disabled', 'true' );
		expect( settingsFetchCount() ).toBe( 1 );
	} );

	it( 'shows all four performance outcomes when automatic fraud prevention is disabled', async () => {
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, {
				flagged_by_fraud_prevention: 12,
				blocked_automatically: 3,
				allowed_by_rules: 4,
				blocked_by_rules: 5,
			} )
		);
		renderSettings();

		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' );
		await screen.findByText( '12' );
		expect( performanceCard ).not.toBeNull();
		const performance = within( performanceCard as HTMLElement );

		expect( performanceCard ).toHaveTextContent(
			'See how fraud prevention is evaluating recent checkout activity.'
		);
		expect( performanceCard ).toHaveTextContent( 'Last 30 days' );
		expect(
			performance
				.getAllByRole( 'term' )
				.map( ( element ) => element.textContent )
		).toEqual( [
			'Flagged by fraud prevention',
			'Blocked automatically',
			'Allowed by rules',
			'Blocked by rules',
		] );
		expect(
			performance
				.getAllByRole( 'definition' )
				.map( ( element ) => element.textContent )
		).toEqual( [ '12', '3', '4', '5' ] );
		expect(
			performanceCard?.querySelector(
				'svg.wc-fraud-protection-settings__performance-caution-icon'
			)
		).toBeInTheDocument();
		expect(
			performance.getByRole( 'link', {
				name: 'View checkout attempts',
			} )
		).toHaveAttribute(
			'href',
			'/wp-admin/admin.php?page=wc-settings&tab=woocommerce_fraud_protection&path=%2Fcheckout-attempts'
		);
	} );

	it( 'hides flagged checkout attempts when automatic fraud prevention is enabled', async () => {
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( true, {
				flagged_by_fraud_prevention: 12,
				blocked_automatically: 3,
				allowed_by_rules: 4,
				blocked_by_rules: 5,
			} )
		);
		renderSettings();

		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' );
		await screen.findByText( '3' );
		expect( performanceCard ).not.toBeNull();
		const performance = within( performanceCard as HTMLElement );

		expect(
			performance
				.getAllByRole( 'term' )
				.map( ( element ) => element.textContent )
		).toEqual( [
			'Blocked automatically',
			'Allowed by rules',
			'Blocked by rules',
		] );
		expect(
			performance
				.getAllByRole( 'definition' )
				.map( ( element ) => element.textContent )
		).toEqual( [ '3', '4', '5' ] );
		expect(
			performanceCard?.querySelector(
				'.wc-fraud-protection-settings__performance-caution-icon'
			)
		).not.toBeInTheDocument();
	} );

	it( 'shows an error and keeps controls disabled when loading fails', async () => {
		mockedApiFetch.mockRejectedValueOnce(
			new Error( 'Check your connection and try again.' )
		);
		renderSettings();

		expect(
			await findVisibleText(
				'The fraud prevention settings could not be loaded. Check your connection and try again.'
			)
		).toBeVisible();
		expect( screen.getByRole( 'checkbox' ) ).toHaveAttribute(
			'aria-disabled',
			'true'
		);
		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.queryByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).not.toBeInTheDocument();
		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' );
		expect( performanceCard ).not.toBeNull();
		const definitions = within(
			performanceCard as HTMLElement
		).getAllByRole( 'definition' );
		expect( definitions ).toHaveLength( 4 );
		expect( definitions.map( ( value ) => value.textContent ) ).toEqual( [
			'—',
			'—',
			'—',
			'—',
		] );

		// Clicking the disabled button must not retry the failed request.
		await userEvent.click( save );
		expect( settingsFetchCount() ).toBe( 1 );
	} );

	it( 'saves a changed Boolean and queues the success Snackbar', async () => {
		mockedApiFetch
			.mockResolvedValueOnce(
				settingsResponse( false, {
					flagged_by_fraud_prevention: 12,
					blocked_automatically: 3,
					allowed_by_rules: 4,
					blocked_by_rules: 5,
				} )
			)
			.mockResolvedValueOnce( {
				automatic_protection: true,
				automatic_protection_opted_out: false,
			} );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await waitFor( () =>
			expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
		await userEvent.click( checkbox );
		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( performanceCard ).getByText( 'Flagged by fraud prevention' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).toBeInTheDocument();
		await userEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => {
			expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc-fraud-protection/v1/settings',
				method: 'POST',
				data: { automatic_protection: true },
			} );
		} );
		await waitFor( () => {
			expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
				'Settings saved.',
				{
					type: 'snackbar',
				}
			);
		} );
		expect( screen.queryByText( '12' ) ).not.toBeInTheDocument();
		expect( screen.getByText( '3' ) ).toBeInTheDocument();
		expect( screen.getByText( '4' ) ).toBeInTheDocument();
		expect( screen.getByText( '5' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'keeps the controls disabled while a changed value is saving', async () => {
		let resolveSave: ( response: {
			automatic_protection: boolean;
			automatic_protection_opted_out: boolean;
		} ) => void = () => {};
		const pendingSave = new Promise< {
			automatic_protection: boolean;
			automatic_protection_opted_out: boolean;
		} >( ( resolve ) => {
			resolveSave = resolve;
		} );
		mockedApiFetch
			.mockResolvedValueOnce( settingsResponse( false ) )
			.mockReturnValueOnce( pendingSave );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await waitFor( () =>
			expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
		await userEvent.click( checkbox );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		await userEvent.click( save );

		await waitFor( () => {
			expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
			expect( checkbox ).toHaveAttribute( 'aria-disabled', 'true' );
		} );

		resolveSave( {
			automatic_protection: true,
			automatic_protection_opted_out: false,
		} );
		await waitFor( () => {
			expect( mockCreateSuccessNotice ).toHaveBeenCalled();
		} );
	} );

	it( 'shows an inline Notice when saving fails', async () => {
		mockedApiFetch
			.mockResolvedValueOnce(
				settingsResponse( true, performanceWithFlagged( 12 ) )
			)
			.mockRejectedValueOnce( new Error( 'Try again later.' ) )
			.mockResolvedValueOnce( {
				automatic_protection: false,
				automatic_protection_opted_out: false,
			} );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await waitFor( () =>
			expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' )
		);
		await userEvent.click( checkbox );
		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' ) as HTMLElement;
		expect(
			within( performanceCard ).queryByText(
				'Flagged by fraud prevention'
			)
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).not.toBeInTheDocument();
		const save = screen.getByRole( 'button', { name: 'Save' } );
		await userEvent.click( save );

		expect(
			await findVisibleText(
				'The fraud prevention setting could not be saved. Try again later.'
			)
		).toBeVisible();
		expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( save ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			within( performanceCard ).queryByText(
				'Flagged by fraud prevention'
			)
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).not.toBeInTheDocument();
		await userEvent.click( save );

		await waitFor( () => {
			expect( mockedApiFetch ).toHaveBeenCalledTimes( 3 );
			expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc-fraud-protection/v1/settings',
				method: 'POST',
				data: { automatic_protection: false },
			} );
		} );
		await waitFor( () => {
			expect( mockCreateSuccessNotice ).toHaveBeenCalled();
		} );
		expect(
			within( performanceCard ).getByText( 'Flagged by fraud prevention' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).toBeInTheDocument();
		expect(
			screen
				.queryAllByText(
					'The fraud prevention setting could not be saved. Try again later.',
					{ exact: true }
				)
				.filter( ( element ) => ! element.hasAttribute( 'aria-live' ) )
		).toHaveLength( 0 );
	} );

	it.each< [ number, string ] >( [
		[ 1, '1 checkout attempt' ],
		[ 12, '12 checkout attempts' ],
	] )( 'links the %s flagged attempt count', async ( count, label ) => {
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, performanceWithFlagged( count ) )
		);
		renderSettings();

		expect(
			await screen.findByRole( 'link', { name: label } )
		).toHaveAttribute(
			'href',
			'/wp-admin/admin.php?page=wc-settings&tab=woocommerce_fraud_protection&path=%2Fcheckout-attempts'
		);
	} );

	it( 'shows the opt-out actions and offers no dismiss on the opt-out notice', async () => {
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, performanceWithFlagged( 12 ) )
		);
		renderSettings();

		expect(
			await screen.findByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).toBeEnabled();
		expect(
			screen.getByRole( 'link', { name: 'Learn more' } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/fraud-protection/'
		);

		// The opt-out notice presents a decision, so it cannot be dismissed.
		expect(
			screen.queryByRole( 'button', {
				name: 'Dismiss automatic fraud prevention notice',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'shows the automatic-protection recommendation after an opt-out is stored', async () => {
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, performanceWithFlagged( 12 ), true )
		);
		renderSettings();

		const countLink = await screen.findByRole( 'link', {
			name: '12 checkout attempts',
		} );
		expect( countLink.parentElement ).toHaveTextContent(
			'12 checkout attempts in the last 30 days are flagged as suspicious but allowed because automatic fraud prevention is off. We recommend turning it on.'
		);
		expect(
			screen.queryByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'Learn more' } )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', {
				name: 'Dismiss automatic fraud prevention notice',
			} )
		).not.toBeInTheDocument();
	} );

	it.each( [
		[
			false,
			'Automatic fraud prevention is off. It will turn on by default on October 20. You can turn it on now using the setting above, or opt out of this new feature.',
		],
		[
			true,
			'Automatic fraud prevention is off. We recommend turning it on.',
		],
	] )(
		'shows count-free copy when opted-out is %s and no attempts were flagged',
		async ( optedOut, copy ) => {
			mockedApiFetch.mockResolvedValueOnce(
				settingsResponse( false, zeroPerformance, optedOut )
			);
			renderSettings();

			expect( await findVisibleText( copy ) ).toBeVisible();
			expect(
				screen.queryByRole( 'link', { name: '0 checkout attempts' } )
			).not.toBeInTheDocument();
		}
	);

	it.each( [
		[ 'inbox', '/?source=inbox' ],
		[ 'settings', '/' ],
	] )(
		'stores a %s opt-out and queues the approved success message',
		async ( source, path ) => {
			window.history.replaceState( {}, '', path );
			mockedApiFetch
				.mockResolvedValueOnce( settingsResponse( false ) )
				.mockResolvedValueOnce( {
					automatic_protection: false,
					automatic_protection_opted_out: true,
				} );
			renderSettings();

			await userEvent.click(
				await screen.findByRole( 'button', {
					name: 'Opt out of automatic blocking',
				} )
			);

			await waitFor( () => {
				expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
					path: '/wc-fraud-protection/v1/settings/opt-out',
					method: 'POST',
					data: { source },
				} );
			} );
			await waitFor( () => {
				expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
					'Automatic blocking stays off.',
					{ type: 'snackbar' }
				);
			} );
			expect(
				screen.queryByRole( 'button', {
					name: 'Opt out of automatic blocking',
				} )
			).not.toBeInTheDocument();
		}
	);

	it( 'keeps the opt-out available when the request fails', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( settingsResponse( false ) )
			.mockRejectedValueOnce( new Error( 'Try again later.' ) );
		renderSettings();

		const optOut = await findOptOutButton();
		await userEvent.click( optOut );

		expect(
			await findVisibleText(
				'We could not opt you out of automatic blocking. Try again later.'
			)
		).toBeVisible();
		expect( optOut ).toBeEnabled();
		expect( screen.getByRole( 'checkbox' ) ).toBeEnabled();
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
	} );
} );

const findOptOutButton = () =>
	screen.findByRole( 'button', { name: 'Opt out of automatic blocking' } );
