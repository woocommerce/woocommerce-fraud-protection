import apiFetch from '@wordpress/api-fetch';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Icon,
	Notice,
	SnackbarList,
} from '@wordpress/components';
import { createRoot, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { error as errorIcon } from '@wordpress/icons';

import './style.scss';

type SettingsResponse = {
	automatic_protection: boolean;
};

type AutomaticProtectionSettingsProps = {
	initialAutomaticProtection: boolean;
};

export function AutomaticProtectionSettings( {
	initialAutomaticProtection,
}: AutomaticProtectionSettingsProps ) {
	const [ savedValue, setSavedValue ] = useState(
		initialAutomaticProtection
	);
	const [ value, setValue ] = useState( initialAutomaticProtection );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ showSuccess, setShowSuccess ] = useState( false );
	const successNotices = showSuccess
		? [
				{
					id: 'settings-saved',
					content: __(
						'Settings saved.',
						'woocommerce-fraud-protection'
					),
				},
		  ]
		: [];

	const save = async () => {
		if ( savedValue === value || isSaving ) {
			return;
		}

		setIsSaving( true );
		setError( null );
		setShowSuccess( false );

		try {
			const response = await apiFetch< SettingsResponse >( {
				path: '/wc-fraud-protection/v1/settings',
				method: 'POST',
				data: { automatic_protection: value },
			} );
			setSavedValue( response.automatic_protection );
			setValue( response.automatic_protection );
			setShowSuccess( true );
		} catch {
			setError(
				__(
					'The Fraud Protection setting could not be saved.',
					'woocommerce-fraud-protection'
				)
			);
		} finally {
			setIsSaving( false );
		}
	};

	const updateValue = ( newValue: boolean ) => {
		setValue( newValue );
		setError( null );
		setShowSuccess( false );
	};

	return (
		<div className="wc-fraud-protection-settings__content">
			{ error && (
				<Notice
					className="wc-fraud-protection-settings__error-notice"
					status="error"
					isDismissible={ false }
				>
					<div className="wc-fraud-protection-settings__error-content">
						<Icon
							className="wc-fraud-protection-settings__error-icon"
							icon={ errorIcon }
							size={ 16 }
						/>
						<span>{ error }</span>
					</div>
				</Notice>
			) }
			<Card className="wc-fraud-protection-settings__card">
				<CardHeader
					className="wc-fraud-protection-settings__header"
					isBorderless
					size="none"
				>
					<div>
						<h2>
							{ __(
								'Automatic protection',
								'woocommerce-fraud-protection'
							) }
						</h2>
						<p>
							{ __(
								'Fraud prevention scans checkout attempts for potentially automated or malicious shopper behavior. Flagged checkout attempts are recorded by default and are only blocked when automatic blocking is turned on.',
								'woocommerce-fraud-protection'
							) }
						</p>
					</div>
				</CardHeader>
				<CardBody
					className="wc-fraud-protection-settings__body"
					size="none"
				>
					<CheckboxControl
						label={ __(
							'Automatically block checkout attempts flagged by fraud prevention.',
							'woocommerce-fraud-protection'
						) }
						checked={ value }
						disabled={ isSaving }
						onChange={ updateValue }
					/>
				</CardBody>
			</Card>
			<div className="wc-fraud-protection-settings__actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					type="button"
					isBusy={ isSaving }
					disabled={ savedValue === value || isSaving }
					onClick={ save }
				>
					{ __( 'Save', 'woocommerce-fraud-protection' ) }
				</Button>
			</div>
			<SnackbarList
				className="wc-fraud-protection-settings__snackbar-list"
				notices={ successNotices }
				onRemove={ () => setShowSuccess( false ) }
			/>
		</div>
	);
}

const mount = document.getElementById( 'wc-fraud-protection-settings' );

if ( mount ) {
	createRoot( mount ).render(
		<AutomaticProtectionSettings
			initialAutomaticProtection={
				mount.dataset.automaticProtection === 'true'
			}
		/>
	);
}
