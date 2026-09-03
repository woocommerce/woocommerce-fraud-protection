import { Button, Icon, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { error as errorIcon } from '@wordpress/icons';

import { AutomaticProtectionCard } from './components/automatic-protection-card';
import { useFraudProtectionSettings } from './hooks/use-fraud-protection-settings';
import { useUnsavedChangesWarning } from './hooks/use-unsaved-changes-warning';

export function FraudProtectionSettingsPage() {
	const {
		error,
		isDirty,
		isLoading,
		isSaving,
		save,
		settings,
		setAutomaticProtection,
	} = useFraudProtectionSettings();

	useUnsavedChangesWarning( isDirty );

	let errorMessage = null;
	if ( error?.operation === 'load' ) {
		errorMessage = __(
			'The Fraud Protection settings could not be loaded.',
			'woocommerce-fraud-protection'
		);
	} else if ( error?.operation === 'save' ) {
		errorMessage = __(
			'The Fraud Protection setting could not be saved.',
			'woocommerce-fraud-protection'
		);
	}

	const errorDetail =
		error?.message && error.message !== errorMessage ? error.message : null;

	return (
		<div className="wc-fraud-protection-settings__content">
			{ errorMessage && (
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
						<span>
							{ errorMessage }
							{ errorDetail && ` ${ errorDetail }` }
						</span>
					</div>
				</Notice>
			) }
			<AutomaticProtectionCard
				checked={ settings?.automatic_protection ?? false }
				disabled={ ! settings || isSaving }
				isLoading={ isLoading }
				onChange={ setAutomaticProtection }
			/>
			<div className="wc-fraud-protection-settings__actions">
				<Button
					__next40pxDefaultSize
					variant="primary"
					type="button"
					isBusy={ isSaving }
					disabled={ ! isDirty || isSaving }
					onClick={ save }
				>
					{ __( 'Save', 'woocommerce-fraud-protection' ) }
				</Button>
			</div>
		</div>
	);
}
