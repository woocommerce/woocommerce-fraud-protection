import { Button, Notice, Stack } from '@wordpress/ui';
import { __ } from '@wordpress/i18n';

import { AutomaticProtectionCard } from './components/automatic-protection-card';
import { PerformanceCard } from './components/performance-card';
import { RulesCard } from './components/rules-card';
import { useFraudProtectionSettings } from './hooks/use-fraud-protection-settings';
import { useUnsavedChangesGuard } from './hooks/use-unsaved-changes-guard';

export function FraudProtectionSettingsPage() {
	const {
		discardChanges,
		error,
		isDirty,
		isLoading,
		isOptingOut,
		isSaving,
		optOut,
		performance,
		save,
		savedSettings,
		settings,
		setAutomaticProtection,
	} = useFraudProtectionSettings();

	useUnsavedChangesGuard( isDirty, discardChanges );
	const controlsDisabled = ! settings || isSaving || isOptingOut;

	let errorMessage = null;
	if ( error?.operation === 'load' ) {
		errorMessage = __(
			'The fraud prevention settings could not be loaded.',
			'woocommerce-fraud-protection'
		);
	} else if ( error?.operation === 'save' ) {
		errorMessage = __(
			'The fraud prevention setting could not be saved.',
			'woocommerce-fraud-protection'
		);
	} else if ( error?.operation === 'opt_out' ) {
		errorMessage = __(
			'We could not opt you out of automatic blocking.',
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
				controlsDisabled={ controlsDisabled }
				isLoading={ isLoading }
				isOptingOut={ isOptingOut }
				onChange={ setAutomaticProtection }
				onOptOut={ optOut }
				optedOut={ settings?.automatic_protection_opted_out ?? true }
				flaggedByFraudPreventionCount={
					performance?.flagged_by_fraud_prevention ?? 0
				}
				savedAutomaticProtection={
					savedSettings?.automatic_protection ?? null
				}
			/>
			<PerformanceCard
				automaticProtection={
					savedSettings?.automatic_protection ?? false
				}
				isLoading={ isLoading }
				performance={ performance }
			/>
			<RulesCard />
			<Stack direction="row">
				<Button
					variant="solid"
					type="button"
					loading={ isSaving }
					disabled={ ! isDirty || isSaving || isOptingOut }
					onClick={ save }
				>
					{ __( 'Save', 'woocommerce-fraud-protection' ) }
				</Button>
			</Stack>
		</Stack>
	);
}
