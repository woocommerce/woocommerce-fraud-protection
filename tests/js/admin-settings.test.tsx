import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';

import { AutomaticProtectionSettings } from '../../client/admin-settings';

jest.mock( '@wordpress/api-fetch', () => ( {
	__esModule: true,
	default: jest.fn(),
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
	Notice: ( { children }: React.PropsWithChildren ) => (
		<div role="alert">{ children }</div>
	),
	Snackbar: ( { children }: React.PropsWithChildren ) => (
		<div role="status">{ children }</div>
	),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'AutomaticProtectionSettings', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
	} );

	it( 'keeps an absent effective default unchanged until the checkbox changes', async () => {
		mockedApiFetch.mockResolvedValueOnce( { automatic_protection: false } );
		render( <AutomaticProtectionSettings /> );

		const checkbox = await screen.findByRole( 'checkbox' );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( checkbox ).not.toBeChecked();
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
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );

		fireEvent.click( save );
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'disables settings and shows a Notice when loading fails', async () => {
		mockedApiFetch.mockRejectedValueOnce( new Error( 'failed' ) );
		render( <AutomaticProtectionSettings /> );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'The Fraud Protection settings could not be loaded.'
		);
		const checkbox = screen.getByRole( 'checkbox' );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		expect( checkbox ).toBeDisabled();
		expect( save ).toBeDisabled();

		fireEvent.click( save );
		expect( mockedApiFetch ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'saves a changed Boolean and shows the success Snackbar', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( { automatic_protection: false } )
			.mockResolvedValueOnce( { automatic_protection: true } );
		render( <AutomaticProtectionSettings /> );

		const checkbox = await screen.findByRole( 'checkbox' );
		fireEvent.click( checkbox );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => {
			expect( mockedApiFetch ).toHaveBeenLastCalledWith( {
				path: '/wc-fraud-protection/v1/settings',
				method: 'POST',
				data: { automatic_protection: true },
			} );
		} );
		expect( await screen.findByRole( 'status' ) ).toHaveTextContent(
			'Settings saved.'
		);
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
		render( <AutomaticProtectionSettings /> );

		const checkbox = await screen.findByRole( 'checkbox' );
		fireEvent.click( checkbox );
		const save = screen.getByRole( 'button', { name: 'Save' } );
		fireEvent.click( save );

		await waitFor( () => {
			expect( save ).toBeDisabled();
			expect( save ).toHaveAttribute( 'data-busy', 'true' );
			expect( checkbox ).toBeDisabled();
		} );

		resolveSave( { automatic_protection: true } );
		await screen.findByRole( 'status' );
	} );

	it( 'shows an inline Notice when saving fails', async () => {
		mockedApiFetch
			.mockResolvedValueOnce( { automatic_protection: true } )
			.mockRejectedValueOnce( new Error( 'failed' ) );
		render( <AutomaticProtectionSettings /> );

		const checkbox = await screen.findByRole( 'checkbox' );
		fireEvent.click( checkbox );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'The Fraud Protection setting could not be saved.'
		);
	} );
} );
