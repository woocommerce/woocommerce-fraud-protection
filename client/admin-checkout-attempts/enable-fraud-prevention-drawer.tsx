import apiFetch from '@wordpress/api-fetch';
import { Button, Checkbox, Drawer, Notice, Stack, Text } from '@wordpress/ui';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

// A right-side drawer that lets the merchant turn on automatic fraud prevention
// without leaving the checkout attempts list. It mirrors the settings card's
// enable control (description + "automatically block" checkbox) and saves to the
// same settings endpoint. Opened from the list banner and the flagged-row action.

const SETTINGS_PATH = '/wc-fraud-protection/v1/settings';
const CHECKBOX_ID = 'wc-fraud-protection-enable-drawer-checkbox';

export function EnableFraudPreventionDrawer( {
	open,
	onOpenChange,
	onEnabled,
}: {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
	onEnabled: () => void;
} ) {
	const [ checked, setChecked ] = useState( false );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	// Each time the drawer opens, start from the default (unchecked) and clear
	// any error left from a previous attempt.
	useEffect( () => {
		if ( open ) {
			setChecked( false );
			setError( null );
		}
	}, [ open ] );

	const save = async () => {
		if ( isSaving ) {
			return;
		}

		setIsSaving( true );
		setError( null );

		try {
			await apiFetch( {
				path: SETTINGS_PATH,
				method: 'POST',
				data: { automatic_protection: checked },
			} );

			if ( checked ) {
				onEnabled();
			}
			onOpenChange( false );
		} catch ( saveError: unknown ) {
			setError(
				saveError instanceof Error && saveError.message
					? saveError.message
					: __(
							'The setting could not be saved.',
							'woocommerce-fraud-protection'
					  )
			);
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<Drawer.Root
			open={ open }
			onOpenChange={ onOpenChange }
			swipeDirection="right"
			disablePointerDismissal={ isSaving }
		>
			<Drawer.Popup className="wc-fraud-protection-checkout-attempts__enable-drawer">
				<Drawer.Header>
					<Drawer.Title>
						{ __(
							'Enable fraud prevention',
							'woocommerce-fraud-protection'
						) }
					</Drawer.Title>
					<Drawer.CloseIcon disabled={ isSaving } />
				</Drawer.Header>
				<Drawer.Content>
					<Stack direction="column" gap="lg">
						<Drawer.Description>
							{ __(
								'Fraud prevention scans checkout attempts for potentially automated or malicious shopper behavior. Flagged checkout attempts are recorded by default and are only blocked when automatic blocking is turned on.',
								'woocommerce-fraud-protection'
							) }
						</Drawer.Description>
						{ error && (
							<Notice.Root intent="error">
								<Notice.Description>
									{ error }
								</Notice.Description>
							</Notice.Root>
						) }
						<Stack direction="row" align="center" gap="sm">
							<Checkbox
								id={ CHECKBOX_ID }
								checked={ checked }
								disabled={ isSaving }
								onCheckedChange={ setChecked }
							/>
							<label htmlFor={ CHECKBOX_ID }>
								<Text variant="body-md">
									{ __(
										'Automatically block checkout attempts flagged by fraud prevention.',
										'woocommerce-fraud-protection'
									) }
								</Text>
							</label>
						</Stack>
					</Stack>
				</Drawer.Content>
				<Drawer.Footer>
					<Button
						variant="solid"
						type="button"
						loading={ isSaving }
						// The drawer always opens with the box unchecked (automatic
						// fraud prevention is off), so "the setting changed" means the
						// box is now checked. Keep Save disabled until then, mirroring
						// the settings page's dirty check.
						disabled={ ! checked || isSaving }
						onClick={ save }
					>
						{ __( 'Save', 'woocommerce-fraud-protection' ) }
					</Button>
				</Drawer.Footer>
			</Drawer.Popup>
		</Drawer.Root>
	);
}
