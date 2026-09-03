import { Button, Notice, Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

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
		<Stack
			className="wc-fraud-protection-settings__content"
			direction="column"
			gap="xl"
		>
			{ errorMessage && (
				<Notice.Root intent="error">
					<Notice.Description>
						{ errorMessage }
						{ errorDetail && ` ${ errorDetail }` }
					</Notice.Description>
				</Notice.Root>
			) }
			<AutomaticProtectionCard
				checked={ settings?.automatic_protection ?? false }
				disabled={ ! settings || isSaving }
				isLoading={ isLoading }
				onChange={ setAutomaticProtection }
			/>
			<Stack direction="row">
				<Button
					variant="solid"
					type="button"
					loading={ isSaving }
					disabled={ ! isDirty || isSaving }
					onClick={ save }
				>
					{ __( 'Save', 'woocommerce-fraud-protection' ) }
				</Button>
			</Stack>
		</Stack>
	);
}
