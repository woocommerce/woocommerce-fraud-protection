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

const zeroPerformance: Performance = {
	recommended_for_blocking: 0,
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
	performance,
} );

const performanceWithRecommended = ( recommendedForBlocking: number ) => ( {
	...zeroPerformance,
	recommended_for_blocking: recommendedForBlocking,
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
			screen.getByText( 'Loading automatic protection setting.' )
		).toBeInTheDocument();
		expect(
			screen.getByText( 'Loading performance results.' )
		).toBeInTheDocument();
		expect( performanceCard?.querySelector( 'dl' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
		expect(
			performanceCard?.querySelectorAll( '[aria-hidden="true"]' )
		).toHaveLength( 4 );
		expect( save ).toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByRole( 'heading', { name: 'Automatic protection' } )
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
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			resolveLoad( settingsResponse( false ) );
		} );
		const checkbox = await screen.findByRole( 'checkbox' );
		expect( checkbox ).not.toBeChecked();
		expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( screen.queryByRole( 'presentation' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
		expect( screen.getAllByText( '0' ) ).toHaveLength( 4 );
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
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows all four performance outcomes with semantic labels', async () => {
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, {
				recommended_for_blocking: 12,
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
			'Recommended for blocking',
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
			performance.getByRole( 'link', {
				name: 'View checkout attempts',
			} )
		).toHaveAttribute(
			'href',
			'/wp-admin/admin.php?page=wc-settings&tab=woocommerce_fraud_protection&path=%2Fcheckout-attempts'
		);
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
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'saves a changed Boolean and queues the success Snackbar', async () => {
		mockedApiFetch
			.mockResolvedValueOnce(
				settingsResponse( false, {
					recommended_for_blocking: 12,
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
		expect( screen.getByText( '12' ) ).toBeInTheDocument();
		expect( screen.getByText( '3' ) ).toBeInTheDocument();
		expect( screen.getByText( '4' ) ).toBeInTheDocument();
		expect( screen.getByText( '5' ) ).toBeInTheDocument();
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
			.mockResolvedValueOnce( settingsResponse( true ) )
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
		const save = screen.getByRole( 'button', { name: 'Save' } );
		await userEvent.click( save );

		expect(
			await findVisibleText(
				'The fraud prevention setting could not be saved. Try again later.'
			)
		).toBeVisible();
		expect( checkbox ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect( save ).not.toHaveAttribute( 'aria-disabled', 'true' );
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
			screen
				.queryAllByText(
					'The fraud prevention setting could not be saved. Try again later.',
					{ exact: true }
				)
				.filter( ( element ) => ! element.hasAttribute( 'aria-live' ) )
		).toHaveLength( 0 );
	} );

	it( 'shows, dismisses, and restores the opt-out notice until the marker is stored', async () => {
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, performanceWithRecommended( 1 ) )
		);
		const singularRender = renderSettings();
		expect(
			await screen.findByText(
				'1 checkout attempt was flagged in the last 30 days and allowed because automatic blocking is off. Blocking will turn on by default on October 20. You can turn it on now using the setting above, or opt out of this change.'
			)
		).toBeVisible();
		singularRender.unmount();

		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, performanceWithRecommended( 12 ) )
		);
		const firstRender = renderSettings();

		expect(
			await screen.findByText(
				'12 checkout attempts were flagged in the last 30 days and allowed because automatic blocking is off. Blocking will turn on by default on October 20. You can turn it on now using the setting above, or opt out of this change.'
			)
		).toBeVisible();
		expect(
			screen.getByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).toBeEnabled();
		expect(
			screen.getByRole( 'link', { name: 'Learn more' } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/fraud-protection/'
		);

		fireEvent.click(
			screen.getByRole( 'button', {
				name: 'Dismiss automatic protection notice',
			} )
		);
		expect(
			screen.queryByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).not.toBeInTheDocument();

		firstRender.unmount();
		mockedApiFetch.mockResolvedValueOnce(
			settingsResponse( false, zeroPerformance, true )
		);
		renderSettings();
		await screen.findByRole( 'checkbox' );
		expect(
			screen.queryByRole( 'button', {
				name: 'Opt out of automatic blocking',
			} )
		).not.toBeInTheDocument();
	} );

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
					'You have successfully opted out and automatic protection will stay off',
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
				'The fraud prevention setting could not be saved. Try again later.'
			)
		).toBeVisible();
		expect( optOut ).toBeEnabled();
		expect( screen.getByRole( 'checkbox' ) ).toBeEnabled();
		expect( mockCreateSuccessNotice ).not.toHaveBeenCalled();
	} );
} );

const findOptOutButton = () =>
	screen.findByRole( 'button', { name: 'Opt out of automatic blocking' } );
