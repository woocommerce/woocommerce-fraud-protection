import { useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { DataForm } from '@wordpress/dataviews/wp';
import { Button, Dialog, Notice, Stack } from '@wordpress/ui';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import type { Rule, UpdateRuleRequest } from '../data/rules-store';
import { rulesStore } from '../data/rules-store';
import { getRuleFormFields, ruleForm } from './rule-form-drawer';
import type { RuleFormData } from './rule-form-drawer';

type RuleDeleteDialogProps = {
	rule?: Rule;
	origin?: UpdateRuleRequest[ 'origin' ];
	onClose: () => void;
	onSuccess?: () => void;
};

export function RuleDeleteDialog( {
	rule,
	origin = 'rules',
	onClose,
	onSuccess,
}: RuleDeleteDialogProps ) {
	const [ deleteError, setDeleteError ] = useState< string | null >( null );
	const [ isDeleting, setIsDeleting ] = useState( false );
	const { deleteRule } = useDispatch( rulesStore );
	const { createSuccessNotice } = useDispatch( noticesStore );
	const ruleData = useMemo< RuleFormData | undefined >(
		() =>
			rule
				? {
						action: rule.action,
						type: rule.type,
						value: rule.value,
				  }
				: undefined,
		[ rule ]
	);
	const fields = useMemo(
		() =>
			ruleData
				? getRuleFormFields( {
						type: ruleData.type,
						disabled: true,
				  } )
				: [],
		[ ruleData ]
	);

	const close = () => {
		setDeleteError( null );
		onClose();
	};

	return (
		<Dialog.Root
			open={ Boolean( rule ) }
			onOpenChange={ ( open ) => {
				if ( ! open && ! isDeleting ) {
					close();
				}
			} }
		>
			<Dialog.Popup
				size="small"
				portal={
					<Dialog.Portal className="wc-fraud-protection-rules__dialog-portal" />
				}
			>
				<Dialog.Header>
					<Dialog.Title>
						{ __( 'Delete rule', 'woocommerce-fraud-protection' ) }
					</Dialog.Title>
					<Dialog.CloseIcon
						label={ __( 'Close', 'woocommerce-fraud-protection' ) }
						disabled={ isDeleting }
					/>
				</Dialog.Header>
				<Dialog.Content>
					<Stack direction="column" gap="xl">
						<Dialog.Description>
							{ __(
								'This rule will no longer apply to future checkout attempts. Past attempts won’t be affected.',
								'woocommerce-fraud-protection'
							) }
						</Dialog.Description>
						{ ruleData && (
							<DataForm< RuleFormData >
								data={ ruleData }
								fields={ fields }
								form={ ruleForm }
								onChange={ () => undefined }
							/>
						) }
						{ deleteError && (
							<Notice.Root intent="error">
								<Notice.Description>
									{ deleteError }
								</Notice.Description>
							</Notice.Root>
						) }
					</Stack>
				</Dialog.Content>
				<Dialog.Footer>
					<Button
						variant="minimal"
						disabled={ isDeleting }
						onClick={ close }
					>
						{ __( 'Cancel', 'woocommerce-fraud-protection' ) }
					</Button>
					<Button
						className="wc-fraud-protection-rules__delete-button"
						variant="solid"
						loading={ isDeleting }
						disabled={ isDeleting }
						onClick={ async () => {
							if ( ! rule ) {
								return;
							}
							setDeleteError( null );
							setIsDeleting( true );
							try {
								await deleteRule( rule.id, origin );
								close();
								createSuccessNotice(
									__(
										'Rule deleted.',
										'woocommerce-fraud-protection'
									),
									{ type: 'snackbar' }
								);
								onSuccess?.();
							} catch ( caughtError ) {
								setDeleteError(
									typeof caughtError === 'object' &&
										caughtError !== null &&
										'message' in caughtError &&
										typeof caughtError.message === 'string'
										? caughtError.message
										: __(
												'The rule could not be deleted.',
												'woocommerce-fraud-protection'
										  )
								);
							} finally {
								setIsDeleting( false );
							}
						} }
					>
						{ __( 'Delete', 'woocommerce-fraud-protection' ) }
					</Button>
				</Dialog.Footer>
			</Dialog.Popup>
		</Dialog.Root>
	);
}
