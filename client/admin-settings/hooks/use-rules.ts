import { useDispatch, useSelect } from '@wordpress/data';

import { rulesStore } from '../data/rules-store';

export function useRules() {
	const state = useSelect( ( select ) => {
		const store = select( rulesStore );

		return {
			error: store.getError(),
			isLoading: store.isLoading(),
			query: store.getQuery(),
			rules: store.getRules(),
			totalItems: store.getTotalItems(),
			totalPages: store.getTotalPages(),
		};
	}, [] );
	const { createRule, deleteRule, requestRule, requestRules, updateRule } =
		useDispatch( rulesStore );

	return {
		...state,
		createRule,
		deleteRule,
		requestRule,
		requestRules,
		updateRule,
	};
}
