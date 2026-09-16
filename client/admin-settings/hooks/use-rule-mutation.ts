import { useCallback, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { useDispatch } from '@wordpress/data';
import { store as noticesStore } from '@wordpress/notices';

import type {
	CreateRuleRequest,
	Rule,
	UpdateRuleRequest,
} from '../data/rules-store';
import { rulesStore } from '../data/rules-store';

export const DUPLICATE_RULE_ERROR =
	'woocommerce_fraud_protection_duplicate_rule';

export type RuleMutationError = {
	code: string | null;
	message: string;
	ruleId: number | null;
};

type RuleMutationResponse = Rule | null;

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
	const { createRule, updateRule } = useDispatch( rulesStore ) as {
		createRule: ( request: CreateRuleRequest ) => Promise< Rule >;
		updateRule: (
			id: number,
			request: UpdateRuleRequest
		) => Promise< Rule >;
	};
	const { createSuccessNotice } = useDispatch( noticesStore );
	const [ isSaving, setIsSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState< RuleMutationError | null >(
		null
	);

	const clearSaveError = useCallback( () => setSaveError( null ), [] );
	const saveRule = useCallback(
		async (
			ruleId: number | undefined,
			request: CreateRuleRequest | UpdateRuleRequest
		): Promise< RuleMutationResponse > => {
			if ( isSaving ) {
				return null;
			}

			setSaveError( null );
			setIsSaving( true );
			try {
				const rule =
					ruleId === undefined
						? await createRule( request as CreateRuleRequest )
						: await updateRule(
								ruleId,
								request as UpdateRuleRequest
						  );
				createSuccessNotice(
					ruleId === undefined
						? __(
								'Rule created successfully',
								'woocommerce-fraud-protection'
						  )
						: __(
								'Rule updated successfully',
								'woocommerce-fraud-protection'
						  ),
					{ type: 'snackbar' }
				);
				return rule;
			} catch ( error ) {
				const mutationError = getError(
					error,
					ruleId === undefined
						? __(
								'The rule could not be created.',
								'woocommerce-fraud-protection'
						  )
						: __(
								'The rule could not be updated.',
								'woocommerce-fraud-protection'
						  )
				);
				setSaveError( mutationError );
				return null;
			} finally {
				setIsSaving( false );
			}
		},
		[ createRule, createSuccessNotice, isSaving, updateRule ]
	);

	return { clearSaveError, isSaving, saveError, saveRule };
}
