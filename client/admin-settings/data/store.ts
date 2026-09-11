import apiFetch from '@wordpress/api-fetch';
import { createReduxStore, register } from '@wordpress/data';

export type Settings = {
	automatic_protection: boolean;
	automatic_protection_opted_out: boolean;
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
	operation: 'load' | 'opt_out' | 'save';
} | null;

type State = {
	current: Settings | null;
	error: SettingsError;
	isSaving: boolean;
	isOptingOut: boolean;
	performance: Performance | null;
	saved: Settings | null;
};

type Action =
	| { type: 'DISCARD_CHANGES' }
	| { type: 'RECEIVE_SETTINGS'; settings: Settings }
	| { type: 'RECEIVE_SETTINGS_RESPONSE'; response: SettingsResponse }
	| { type: 'SET_AUTOMATIC_PROTECTION'; value: boolean }
	| { type: 'SET_ERROR'; error: SettingsError }
	| { type: 'SET_IS_OPTING_OUT'; isOptingOut: boolean }
	| { type: 'SET_IS_SAVING'; isSaving: boolean };

const DEFAULT_STATE: State = {
	current: null,
	error: null,
	isSaving: false,
	isOptingOut: false,
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
		case 'DISCARD_CHANGES':
			return {
				...state,
				current: state.saved,
				error: null,
			};
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
				automatic_protection_opted_out:
					action.response.automatic_protection_opted_out,
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
		case 'SET_IS_OPTING_OUT':
			return { ...state, isOptingOut: action.isOptingOut };
		case 'SET_IS_SAVING':
			return { ...state, isSaving: action.isSaving };
		default:
			return state;
	}
};

const actions = {
	discardChanges(): Action {
		return { type: 'DISCARD_CHANGES' };
	},
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
	setIsOptingOut( isOptingOut: boolean ): Action {
		return { type: 'SET_IS_OPTING_OUT', isOptingOut };
	},
	setIsSaving( isSaving: boolean ): Action {
		return { type: 'SET_IS_SAVING', isSaving };
	},
	saveSettings:
		() =>
		async ( { dispatch, select }: StoreCallback ) => {
			const settings = select.getSettings();

			if (
				! settings ||
				select.isSaving() ||
				select.isOptingOut() ||
				! select.isDirty()
			) {
				return false;
			}

			dispatch.setIsSaving( true );
			dispatch.setError( null );

			try {
				const response = await apiFetch< Settings >( {
					path: '/wc-fraud-protection/v1/settings',
					method: 'POST',
					data: {
						automatic_protection: settings.automatic_protection,
					},
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
	optOut:
		( source: 'inbox' | 'settings' ) =>
		async ( { dispatch, select }: StoreCallback ) => {
			const settings = select.getSettings();

			if (
				! settings ||
				settings.automatic_protection_opted_out ||
				select.isSaving() ||
				select.isOptingOut()
			) {
				return false;
			}

			dispatch.setIsOptingOut( true );
			dispatch.setError( null );

			try {
				const response = await apiFetch< Settings >( {
					path: '/wc-fraud-protection/v1/settings/opt-out',
					method: 'POST',
					data: { source },
				} );
				dispatch.receiveSettings( response );
				return true;
			} catch ( error ) {
				dispatch.setError( {
					message: getApiErrorMessage( error ),
					operation: 'opt_out',
				} );
				return false;
			} finally {
				dispatch.setIsOptingOut( false );
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
	isOptingOut( state: State ): boolean {
		return state.isOptingOut;
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
	isOptingOut: () => boolean;
	isDirty: () => boolean;
};

type StoreActions = {
	discardChanges: () => void;
	receiveSettings: ( settings: Settings ) => void;
	receiveSettingsResponse: ( response: SettingsResponse ) => void;
	setAutomaticProtection: ( value: boolean ) => void;
	setError: ( error: SettingsError ) => void;
	setIsOptingOut: ( isOptingOut: boolean ) => void;
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
