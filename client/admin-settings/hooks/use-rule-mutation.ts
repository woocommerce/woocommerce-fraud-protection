import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import type { CreateRuleRequest, Rule } from '../data/rules-store';
import { rulesStore } from '../data/rules-store';

export const DUPLICATE_RULE_ERROR =
	'woocommerce_fraud_protection_duplicate_rule';

export type RuleMutationError = {
	code: string | null;
	message: string;
	ruleId: number | null;
};

const getError = (
	error: unknown,
	fallbackMessage: string
): RuleMutationError => {
	const apiError = error as {
		code?: unknown;
		message?: unknown;
		data?: { rule_id?: unknown };
	};

	return {
		code: typeof apiError?.code === 'string' ? apiError.code : null,
		message:
			typeof apiError?.message === 'string'
				? apiError.message
				: fallbackMessage,
		ruleId:
			typeof apiError?.data?.rule_id === 'number'
				? apiError.data.rule_id
				: null,
	};
};

export function useRuleMutation() {
	const { createRule } = useDispatch( rulesStore ) as {
		createRule: ( request: CreateRuleRequest ) => Promise< Rule >;
	};
	const { createSuccessNotice, createErrorNotice } =
		useDispatch( noticesStore );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState< RuleMutationError | null >(
		null
	);

	const clearSaveError = useCallback( () => setSaveError( null ), [] );
	const saveRule = useCallback(
		async ( request: CreateRuleRequest ): Promise< Rule | null > => {
			if ( isSaving ) {
				return null;
			}

			setSaveError( null );
			setIsSaving( true );
			try {
				const rule = await createRule( request );
				createSuccessNotice(
					__(
						'Rule created successfully',
						'woocommerce-fraud-protection'
					),
					{ type: 'snackbar' }
				);
				return rule;
			} catch ( error ) {
				const mutationError = getError(
					error,
					__(
						'The rule could not be created.',
						'woocommerce-fraud-protection'
					)
				);
				setSaveError( mutationError );
				if ( mutationError.code !== DUPLICATE_RULE_ERROR ) {
					createErrorNotice( mutationError.message, {
						type: 'snackbar',
					} );
				}
				return null;
			} finally {
				setIsSaving( false );
			}
		},
		[ createErrorNotice, createRule, createSuccessNotice, isSaving ]
	);

	return { clearSaveError, isSaving, saveError, saveRule };
}
