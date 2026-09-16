import { useSelect } from '@wordpress/data';
import { useMemo } from '@wordpress/element';

import {
	getRulesErrorMessage,
	normalizeRulesQuery,
	rulesStore,
	type RulesQuery,
} from '../data/rules-store';

export function useRules( query: RulesQuery ) {
	const normalizedQuery = useMemo(
		() => normalizeRulesQuery( query ),
		[ query ]
	);

	return useSelect(
		( select ) => {
			const store = select( rulesStore );
			const resolverArgs = [ normalizedQuery ];
			const resolutionError = store.getResolutionError(
				'getRules',
				resolverArgs
			);

			return {
				error: resolutionError
					? getRulesErrorMessage( resolutionError )
					: null,
				isLoading:
					store.isResolving( 'getRules', resolverArgs ) ||
					! store.hasFinishedResolution( 'getRules', resolverArgs ),
				rules: store.getRules( normalizedQuery ),
				totalItems: store.getTotalItems( normalizedQuery ),
				totalPages: store.getTotalPages( normalizedQuery ),
			};
		},
		[ normalizedQuery ]
	);
}
