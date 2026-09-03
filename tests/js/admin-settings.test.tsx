import '@testing-library/jest-dom';
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';
import {
	createReduxStore,
	createRegistry,
	RegistryProvider,
} from '@wordpress/data';

import { FraudProtectionSettingsPage } from '../../client/admin-settings/settings-page';
import { settingsStore } from '../../client/admin-settings/data/store';

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

jest.mock( '@wordpress/icons', () => ( {
	error: 'error-icon',
} ) );

jest.mock( '@wordpress/components', () => ( {
	Button: ( {
		__next40pxDefaultSize,
		isBusy,
		...props
	}: React.ButtonHTMLAttributes< HTMLButtonElement > & {
		__next40pxDefaultSize?: boolean;
		isBusy?: boolean;
	} ) => (
		<button
			{ ...props }
			data-busy={ isBusy ? 'true' : 'false' }
			data-next-40px-size={ __next40pxDefaultSize ? 'true' : 'false' }
		/>
	),
	Card: ( { children }: React.PropsWithChildren ) => (
		<section>{ children }</section>
	),
	CardBody: ( {
		children,
		size,
	}: React.PropsWithChildren< { size?: string } > ) => (
		<div data-testid="settings-card-body" data-size={ size }>
			{ children }
		</div>
	),
	CardHeader: ( {
		children,
		isBorderless,
		size,
	}: React.PropsWithChildren< {
		isBorderless?: boolean;
		size?: string;
	} > ) => (
		<header
			data-testid="settings-card-header"
			data-borderless={ isBorderless ? 'true' : 'false' }
			data-size={ size }
		>
			{ children }
		</header>
	),
	CheckboxControl: ( {
		label,
		checked,
		disabled,
		onChange,
	}: {
		label: string;
		checked: boolean;
		disabled: boolean;
		onChange: ( checked: boolean ) => void;
	} ) => (
		<label htmlFor="automatic-protection-test">
			{ label }
			<input
				id="automatic-protection-test"
				type="checkbox"
				checked={ checked }
				disabled={ disabled }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
		</label>
	),
	Icon: ( {
		className,
		icon,
		size,
	}: {
		className?: string;
		icon: string;
		size: number;
	} ) => (
		<span
			className={ className }
			data-testid="settings-error-icon"
			data-icon={ icon }
			data-size={ size }
		/>
	),
	Notice: ( {
		children,
		className,
	}: React.PropsWithChildren< { className?: string } > ) => (
		<div className={ className } role="alert">
			{ children }
		</div>
	),
	Spinner: () => <span data-testid="settings-spinner" />,
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const dispatchBeforeUnload = () => {
	const event = new Event( 'beforeunload', { cancelable: true } );
	const result = window.dispatchEvent( event );

	return { event, result };
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

	it( 'disables controls while loading and renders the disabled value', async () => {
		let resolveLoad: ( response: {
			automatic_protection: boolean;
		} ) => void = () => {};
		mockedApiFetch.mockReturnValueOnce(
			new Promise< { automatic_protection: boolean } >( ( resolve ) => {
				resolveLoad = resolve;
			} )
		);
		renderSettings();

		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( screen.queryByRole( 'checkbox' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'settings-spinner' ) ).toBeInTheDocument();
		expect( save ).toBeDisabled();
		expect( save ).toHaveAttribute( 'data-next-40px-size', 'true' );
		expect( screen.getByTestId( 'settings-card-header' ) ).toHaveAttribute(
			'data-borderless',
			'true'
		);
		expect( screen.getByTestId( 'settings-card-header' ) ).toHaveAttribute(
			'data-size',
			'none'
		);
		expect( screen.getByTestId( 'settings-card-body' ) ).toHaveAttribute(
			'data-size',
			'none'
		);
		await waitFor( () => {
			expect( mockedApiFetch ).toHaveBeenCalledWith( {
				path: '/wc-fraud-protection/v1/settings',
			} );
		} );

		await act( async () => {
			resolveLoad( { automatic_protection: false } );
		} );
		const checkbox = await screen.findByRole( 'checkbox' );
		expect( checkbox ).not.toBeChecked();
		expect( checkbox ).toBeEnabled();
		expect(
			screen.queryByTestId( 'settings-spinner' )
		).not.toBeInTheDocument();

		fireEvent.click( save );
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'loads the enabled value', async () => {
		mockedApiFetch.mockResolvedValueOnce( { automatic_protection: true } );
		renderSettings();

		await waitFor( () => {
			expect( screen.getByRole( 'checkbox' ) ).toBeChecked();
			expect( screen.getByRole( 'checkbox' ) ).toBeEnabled();
		} );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'shows an error and keeps controls disabled when loading fails', async () => {
		mockedApiFetch.mockRejectedValueOnce(
			new Error( 'Check your connection and try again.' )
		);
		renderSettings();

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'The Fraud Protection settings could not be loaded. Check your connection and try again.'
		);
		expect( screen.getByRole( 'checkbox' ) ).toBeDisabled();
		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( save ).toBeDisabled();

		fireEvent.click( save );
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'updates the unload warning through repeated dirty transitions', async () => {
		mockedApiFetch.mockResolvedValueOnce( { automatic_protection: false } );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		fireEvent.click( checkbox );
		const legacyHandler = jest.fn();
		window.onbeforeunload = legacyHandler;
		const firstDirty = dispatchBeforeUnload();

		expect( firstDirty.result ).toBe( false );
		expect( firstDirty.event.defaultPrevented ).toBe( true );
		expect( window.onbeforeunload ).toBe( legacyHandler );

		fireEvent.click( checkbox );
		const firstClean = dispatchBeforeUnload();

		expect( window.onbeforeunload ).toBe( legacyHandler );
		expect( firstClean.result ).toBe( true );
		expect( firstClean.event.defaultPrevented ).toBe( false );

		fireEvent.click( checkbox );
		const secondDirty = dispatchBeforeUnload();

		expect( secondDirty.result ).toBe( false );
		expect( secondDirty.event.defaultPrevented ).toBe( true );

		fireEvent.click( checkbox );
		const secondClean = dispatchBeforeUnload();

		expect( secondClean.result ).toBe( true );
		expect( secondClean.event.defaultPrevented ).toBe( false );
	} );

	it( 'removes the unload warning when unmounted while dirty', async () => {
		mockedApiFetch.mockResolvedValueOnce( { automatic_protection: false } );
		const { unmount } = renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		fireEvent.click( checkbox );
		unmount();
		const afterUnmount = dispatchBeforeUnload();

		expect( afterUnmount.result ).toBe( true );
		expect( afterUnmount.event.defaultPrevented ).toBe( false );
	} );

	it( 'clears the unload warning after a successful save', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( { automatic_protection: false } )
			.mockResolvedValueOnce( { automatic_protection: true } );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		fireEvent.click( checkbox );
		const legacyHandler = jest.fn();
		window.onbeforeunload = legacyHandler;
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => {
			expect( mockCreateSuccessNotice ).toHaveBeenCalled();
			expect( window.onbeforeunload ).toBe( legacyHandler );
			expect( dispatchBeforeUnload().result ).toBe( true );
		} );
	} );

	it( 'saves a changed Boolean and queues the success Snackbar', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( { automatic_protection: false } )
			.mockResolvedValueOnce( { automatic_protection: true } );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await waitFor( () => expect( checkbox ).toBeEnabled() );
		fireEvent.click( checkbox );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

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
				{ type: 'snackbar' }
			);
		} );
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
			.mockResolvedValueOnce( { automatic_protection: false } )
			.mockReturnValueOnce( pendingSave );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await waitFor( () => expect( checkbox ).toBeEnabled() );
		fireEvent.click( checkbox );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		fireEvent.click( save );

		await waitFor( () => {
			expect( save ).toBeDisabled();
			expect( save ).toHaveAttribute( 'data-busy', 'true' );
			expect( checkbox ).toBeDisabled();
		} );

		resolveSave( { automatic_protection: true } );
		await waitFor( () => {
			expect( mockCreateSuccessNotice ).toHaveBeenCalled();
		} );
	} );

	it( 'shows an inline Notice when saving fails', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( { automatic_protection: true } )
			.mockRejectedValueOnce( new Error( 'Try again later.' ) )
			.mockResolvedValueOnce( { automatic_protection: false } );
		renderSettings();

		const checkbox = await screen.findByRole( 'checkbox' );
		await waitFor( () => expect( checkbox ).toBeEnabled() );
		fireEvent.click( checkbox );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		fireEvent.click( save );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'The Fraud Protection setting could not be saved. Try again later.'
		);
		expect( screen.getByRole( 'alert' ) ).toHaveClass(
			'wc-fraud-protection-settings__error-notice'
		);
		expect( screen.getByTestId( 'settings-error-icon' ) ).toHaveAttribute(
			'data-icon',
			'error-icon'
		);
		expect( screen.getByTestId( 'settings-error-icon' ) ).toHaveAttribute(
			'data-size',
			'24'
		);
		expect( screen.getByTestId( 'settings-error-icon' ) ).toHaveClass(
			'wc-fraud-protection-settings__error-icon'
		);
		expect(
			screen.getByTestId( 'settings-error-icon' ).parentElement
		).toHaveClass( 'wc-fraud-protection-settings__error-content' );
		expect(
			screen.getByTestId( 'settings-error-icon' ).nextElementSibling
		).toHaveTextContent(
			'The Fraud Protection setting could not be saved. Try again later.'
		);
		expect( checkbox ).toBeEnabled();
		expect( save ).toBeEnabled();
		expect( dispatchBeforeUnload().result ).toBe( false );

		fireEvent.click( save );

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
		expect( screen.queryByRole( 'alert' ) ).not.toBeInTheDocument();
	} );
} );
