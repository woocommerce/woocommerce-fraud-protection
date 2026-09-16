import { useCallback, useState } from '@wordpress/element';

type DrawerIntent =
	| { mode: 'closed' }
	| { mode: 'create' }
	| { mode: 'edit'; ruleId: number };

export function useRuleFormDrawer() {
	const [ intent, setIntent ] = useState< DrawerIntent >( {
		mode: 'closed',
	} );

	const openCreateRule = useCallback(
		() => setIntent( { mode: 'create' } ),
		[]
	);
	const openEditRule = useCallback(
		( ruleId: number ) => setIntent( { mode: 'edit', ruleId } ),
		[]
	);
	const closeRuleForm = useCallback(
		() => setIntent( { mode: 'closed' } ),
		[]
	);

	return {
		isOpen: intent.mode !== 'closed',
		ruleId: intent.mode === 'edit' ? intent.ruleId : undefined,
		openCreateRule,
		openEditRule,
		closeRuleForm,
	};
}
