import '@testing-library/jest-dom';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import apiFetch from '@wordpress/api-fetch';

import { AutomaticProtectionSettings } from '../../client/admin-settings';

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
	SnackbarList: ( {
		notices,
		onRemove,
	}: {
		notices: Array< { id: string; content: string } >;
		onRemove: ( id: string ) => void;
	} ) => (
		<div>
			{ notices.map( ( notice ) => (
				<div key={ notice.id } role="status">
					{ notice.content }
					<button onClick={ () => onRemove( notice.id ) }>
						Dismiss
					</button>
				</div>
			) ) }
		</div>
	),
} ) );

const mockedApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'AutomaticProtectionSettings', () => {
	beforeEach( () => {
		mockedApiFetch.mockReset();
	} );

	it( 'renders the hydrated disabled value without a request', () => {
		render(
			<AutomaticProtectionSettings initialAutomaticProtection={ false } />
		);

		const checkbox = screen.getByRole( 'checkbox' );
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
		expect( mockedApiFetch ).not.toHaveBeenCalled();

		fireEvent.click( save );
		expect( mockedApiFetch ).not.toHaveBeenCalled();
	} );

	it( 'renders the hydrated enabled value without a request', () => {
		render(
			<AutomaticProtectionSettings initialAutomaticProtection={ true } />
		);

		expect( screen.getByRole( 'checkbox' ) ).toBeChecked();
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		expect( mockedApiFetch ).not.toHaveBeenCalled();
	} );

	it( 'saves a changed Boolean and shows the success Snackbar', async () => {
		mockedApiFetch.mockResolvedValueOnce( {
			automatic_protection: true,
		} );
		render(
			<AutomaticProtectionSettings initialAutomaticProtection={ false } />
		);

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
		fireEvent.click( screen.getByRole( 'button', { name: 'Dismiss' } ) );
		await waitFor( () => {
			expect( screen.queryByRole( 'status' ) ).not.toBeInTheDocument();
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
		mockedApiFetch.mockReturnValueOnce( pendingSave );
		render(
			<AutomaticProtectionSettings initialAutomaticProtection={ false } />
		);

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
		mockedApiFetch.mockRejectedValueOnce( new Error( 'failed' ) );
		render(
			<AutomaticProtectionSettings initialAutomaticProtection={ true } />
		);

		const checkbox = await screen.findByRole( 'checkbox' );
		fireEvent.click( checkbox );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'The Fraud Protection setting could not be saved.'
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
			'16'
		);
	} );
} );
