import { useDispatch, useSelect } from '@wordpress/data';
import { useCallback, useMemo } from '@wordpress/element';

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

export function useRule( id?: number ) {
	const state = useSelect(
		( select ) => {
			if ( id === undefined ) {
				return {
					error: null,
					isLoading: false,
					rule: undefined,
				};
			}

			const store = select( rulesStore );
			const resolverArgs = [ id ];
			const resolutionError = store.getResolutionError(
				'getRule',
				resolverArgs
			);

			return {
				error: resolutionError
					? getRulesErrorMessage( resolutionError )
					: null,
				isLoading:
					store.isResolving( 'getRule', resolverArgs ) ||
					! store.hasFinishedResolution( 'getRule', resolverArgs ),
				rule: store.getRule( id ),
			};
		},
		[ id ]
	);
	const { invalidateResolution } = useDispatch( rulesStore );
	const retry = useCallback( () => {
		if ( id !== undefined ) {
			void invalidateResolution( 'getRule', [ id ] );
		}
	}, [ id, invalidateResolution ] );

	return { ...state, retry };
}
