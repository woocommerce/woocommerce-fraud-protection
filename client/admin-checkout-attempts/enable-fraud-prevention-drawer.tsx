import { Button, Checkbox, Drawer, Notice, Stack, Text } from '@wordpress/ui';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { settingsStore } from '../admin-settings/data/store';

// A right-side drawer that lets the merchant turn on automatic fraud prevention
// without leaving the checkout attempts list. It mirrors the settings card's
// enable control (description + "automatically block" checkbox) and saves
// through the shared settings store, so the list and the settings page both
// reflect the change without a reload. Opened from the list banner and the
// flagged-row action.

const CHECKBOX_ID = 'wc-fraud-protection-enable-drawer-checkbox';

export function EnableFraudPreventionDrawer( {
	open,
	onOpenChange,
}: {
	open: boolean;
	onOpenChange: ( open: boolean ) => void;
} ) {
	const [ checked, setChecked ] = useState( false );

	const { saveSettings, setError } = useDispatch( settingsStore );
	const { isSaving, error } = useSelect( ( select ) => {
		const store = select( settingsStore );
		return {
			isSaving: store.isSaving(),
			error: store.getError(),
		};
	}, [] );

	// Each time the drawer opens, start from the default (unchecked) and clear
	// any save error left from a previous attempt.
	useEffect( () => {
		if ( open ) {
			setChecked( false );
			setError( null );
		}
	}, [ open, setError ] );

	const save = async () => {
		const didSave = await saveSettings( { automatic_protection: checked } );
		if ( didSave ) {
			onOpenChange( false );
		}
	};

	const saveError =
		error?.operation === 'save'
			? error.message ||
			  __(
					'The setting could not be saved.',
					'woocommerce-fraud-protection'
			  )
			: null;

	return (
		<Drawer.Root
			open={ open }
			// Cancel any close request (background click, Escape) while the save
			// is in flight: the request would still complete, but the drawer would
			// look as if the save was canceled. `disablePointerDismissal` alone
			// would not stop Escape.
			onOpenChange={ ( nextOpen, eventDetails ) => {
				if ( ! nextOpen && isSaving ) {
					eventDetails.cancel();
					return;
				}
				onOpenChange( nextOpen );
			} }
			swipeDirection="right"
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
						{ saveError && (
							<Notice.Root intent="error">
								<Notice.Description>
									{ saveError }
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
