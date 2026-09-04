import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';

export type Settings = {
	automatic_protection: boolean;
};

export type Performance = {
	recommended_for_blocking: number;
	blocked_automatically: number;
	allowed_by_rules: number;
	blocked_by_rules: number;
};

type SettingsResponse = Settings & {
	performance: Performance;
};

export type SettingsError = {
	message: string | null;
	operation: 'load' | 'save';
} | null;

type State = {
	current: Settings | null;
	error: SettingsError;
	isSaving: boolean;
	performance: Performance | null;
	saved: Settings | null;
};

type Action =
	| { type: 'RECEIVE_SETTINGS'; settings: Settings }
	| { type: 'RECEIVE_SETTINGS_RESPONSE'; response: SettingsResponse }
	| { type: 'SET_AUTOMATIC_PROTECTION'; value: boolean }
	| { type: 'SET_ERROR'; error: SettingsError }
	| { type: 'SET_IS_SAVING'; isSaving: boolean };

const DEFAULT_STATE: State = {
	current: null,
	error: null,
	isSaving: false,
	performance: null,
	saved: null,
};

const getApiErrorMessage = ( error: unknown ): string | null => {
	if (
		typeof error !== 'object' ||
		error === null ||
		! ( 'message' in error ) ||
		typeof error.message !== 'string'
	) {
		return null;
	}

	const message = error.message.trim();
	return message === '' ? null : message;
};

const reducer = ( state = DEFAULT_STATE, action: Action ): State => {
	switch ( action.type ) {
		case 'RECEIVE_SETTINGS':
			return {
				...state,
				current: action.settings,
				error: null,
				saved: action.settings,
			};
		case 'RECEIVE_SETTINGS_RESPONSE': {
			const settings = {
				automatic_protection: action.response.automatic_protection,
			};

			return {
				...state,
				current: settings,
				error: null,
				performance: action.response.performance,
				saved: settings,
			};
		}
		case 'SET_AUTOMATIC_PROTECTION':
			return {
				...state,
				current: state.current
					? {
							...state.current,
							automatic_protection: action.value,
					  }
					: null,
				error: null,
			};
		case 'SET_ERROR':
			return { ...state, error: action.error };
		case 'SET_IS_SAVING':
			return { ...state, isSaving: action.isSaving };
		default:
			return state;
	}
};

const actions = {
	receiveSettings( settings: Settings ): Action {
		return { type: 'RECEIVE_SETTINGS', settings };
	},
	receiveSettingsResponse( response: SettingsResponse ): Action {
		return { type: 'RECEIVE_SETTINGS_RESPONSE', response };
	},
	setAutomaticProtection( value: boolean ): Action {
		return { type: 'SET_AUTOMATIC_PROTECTION', value };
	},
	setError( error: SettingsError ): Action {
		return { type: 'SET_ERROR', error };
	},
	setIsSaving( isSaving: boolean ): Action {
		return { type: 'SET_IS_SAVING', isSaving };
	},
	saveSettings:
		() =>
		async ( { dispatch, select }: StoreCallback ) => {
			const settings = select.getSettings();

			if ( ! settings || select.isSaving() || ! select.isDirty() ) {
				return false;
			}

			dispatch.setIsSaving( true );
			dispatch.setError( null );

			try {
				const response = await apiFetch< Settings >( {
					path: '/wc-fraud-protection/v1/settings',
					method: 'POST',
					data: settings,
				} );
				dispatch.receiveSettings( response );
				return true;
			} catch ( error ) {
				dispatch.setError( {
					message: getApiErrorMessage( error ),
					operation: 'save',
				} );
				return false;
			} finally {
				dispatch.setIsSaving( false );
			}
		},
};

const selectors = {
	getSettings( state: State ): Settings | null {
		return state.current;
	},
	getError( state: State ): SettingsError {
		return state.error;
	},
	getPerformance( state: State ): Performance | null {
		return state.performance;
	},
	isSaving( state: State ): boolean {
		return state.isSaving;
	},
	isDirty( state: State ): boolean {
		return (
			state.saved !== null &&
			state.current !== null &&
			state.saved.automatic_protection !==
				state.current.automatic_protection
		);
	},
};

type StoreSelectors = {
	getSettings: () => Settings | null;
	getError: () => SettingsError;
	getPerformance: () => Performance | null;
	isSaving: () => boolean;
	isDirty: () => boolean;
};

type StoreActions = {
	receiveSettings: ( settings: Settings ) => void;
	receiveSettingsResponse: ( response: SettingsResponse ) => void;
	setAutomaticProtection: ( value: boolean ) => void;
	setError: ( error: SettingsError ) => void;
	setIsSaving: ( isSaving: boolean ) => void;
};

type StoreCallback = {
	dispatch: StoreActions;
	select: StoreSelectors;
};

const resolvers = {
	getSettings:
		() =>
		async ( { dispatch }: StoreCallback ) => {
			try {
				const response = await apiFetch< SettingsResponse >( {
					path: '/wc-fraud-protection/v1/settings',
				} );
				dispatch.receiveSettingsResponse( response );
			} catch ( error ) {
				dispatch.setError( {
					message: getApiErrorMessage( error ),
					operation: 'load',
				} );
			}
		},
};

export const settingsStore = createReduxStore( 'wc-fraud-protection/settings', {
	reducer,
	actions,
	selectors,
	resolvers,
} );

register( settingsStore );
