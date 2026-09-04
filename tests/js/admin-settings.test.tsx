import '@testing-library/jest-dom';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

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

// Base UI dispatches checkbox activation through PointerEvent, which jsdom does not provide.
if ( ! window.PointerEvent ) {
	Object.defineProperty( window, 'PointerEvent', {
		configurable: true,
		writable: true,
		value: MouseEvent,
	} );
}

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const zeroPerformance: Performance = {
	recommended_for_blocking: 0,
	blocked_automatically: 0,
	allowed_by_rules: 0,
	blocked_by_rules: 0,
};

const settingsResponse = (
	automaticProtection: boolean,
	performance: Performance = zeroPerformance
) => ( {
	automatic_protection: automaticProtection,
	performance,
} );

const dispatchBeforeUnload = () => {
	const event = new Event( 'beforeunload', { cancelable: true } );
	const result = window.dispatchEvent( event );

	return { event, result };
};

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
		<RegistryProvider value={ registry }>
			<FraudProtectionSettingsPage />
		</RegistryProvider>
	);
};

describe( 'FraudProtectionSettingsPage', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
		mockCreateSuccessNotice.mockReset();
	} );

	afterEach( () => {
		window.onbeforeunload = null;
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

		expect( performanceCard ).toHaveTextContent(
			'See how fraud prevention is evaluating recent checkout activity.'
		);
		expect( performanceCard ).toHaveTextContent( 'Last 30 days' );
		expect(
			Array.from( performanceCard?.querySelectorAll( 'dt' ) ?? [] ).map(
				( element ) => element.textContent
			)
		).toEqual( [
			'Recommended for blocking',
			'Blocked automatically',
			'Allowed by rules',
			'Blocked by rules',
		] );
		expect(
			Array.from( performanceCard?.querySelectorAll( 'dd' ) ?? [] ).map(
				( element ) => element.textContent
			)
		).toEqual( [ '12', '3', '4', '5' ] );
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
		const performanceCard = screen
			.getByRole( 'heading', { name: 'Performance' } )
			.closest( 'section' );
		expect( performanceCard?.querySelectorAll( 'dd' ) ).toHaveLength( 4 );
		expect(
			Array.from( performanceCard?.querySelectorAll( 'dd' ) ?? [] ).map(
				( value ) => value.textContent
			)
		).toEqual( [ '—', '—', '—', '—' ] );

		// Clicking the disabled button must not retry the failed request.
		await userEvent.click( save );
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'updates the unload warning through repeated dirty transitions', async () => {
		mockedApiFetch.mockResolvedValueOnce( settingsResponse( false ) );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await userEvent.click( checkbox );
		const legacyHandler = jest.fn();
		window.onbeforeunload = legacyHandler;
		const firstDirty = dispatchBeforeUnload();

		expect( firstDirty.result ).toBe( false );
		expect( firstDirty.event.defaultPrevented ).toBe( true );
		expect( window.onbeforeunload ).toBe( legacyHandler );

		await userEvent.click( checkbox );
		const firstClean = dispatchBeforeUnload();

		expect( window.onbeforeunload ).toBe( legacyHandler );
		expect( firstClean.result ).toBe( true );
		expect( firstClean.event.defaultPrevented ).toBe( false );

		await userEvent.click( checkbox );
		const secondDirty = dispatchBeforeUnload();

		expect( secondDirty.result ).toBe( false );
		expect( secondDirty.event.defaultPrevented ).toBe( true );

		await userEvent.click( checkbox );
		const secondClean = dispatchBeforeUnload();

		expect( secondClean.result ).toBe( true );
		expect( secondClean.event.defaultPrevented ).toBe( false );
	} );

	it( 'removes the unload warning when unmounted while dirty', async () => {
		mockedApiFetch.mockResolvedValueOnce( settingsResponse( false ) );
		const { unmount } = renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await userEvent.click( checkbox );
		unmount();
		const afterUnmount = dispatchBeforeUnload();

		expect( afterUnmount.result ).toBe( true );
		expect( afterUnmount.event.defaultPrevented ).toBe( false );
	} );

	it( 'clears the unload warning after a successful save', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( settingsResponse( false ) )
			.mockResolvedValueOnce( { automatic_protection: true } );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await userEvent.click( checkbox );
		const legacyHandler = jest.fn();
		window.onbeforeunload = legacyHandler;
		await userEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => {
			expect( mockCreateSuccessNotice ).toHaveBeenCalled();
			expect( window.onbeforeunload ).toBe( legacyHandler );
			expect( dispatchBeforeUnload().result ).toBe( true );
		} );
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
			.mockResolvedValueOnce( { automatic_protection: true } );
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
		} ) => void = () => {};
		const pendingSave = new Promise< { automatic_protection: boolean } >(
			( resolve ) => {
				resolveSave = resolve;
			}
		);
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

		resolveSave( { automatic_protection: true } );
		await waitFor( () => {
			expect( mockCreateSuccessNotice ).toHaveBeenCalled();
		} );
	} );

	it( 'shows an inline Notice when saving fails', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( settingsResponse( true ) )
			.mockRejectedValueOnce( new Error( 'Try again later.' ) )
			.mockResolvedValueOnce( { automatic_protection: false } );
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
		expect( dispatchBeforeUnload().result ).toBe( false );

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
} );
