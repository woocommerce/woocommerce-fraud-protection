/**
 * REST routes of the fraud prevention admin endpoints.
 *
 * Every request the app makes builds its path from these constants, so the
 * route namespace is defined in one place.
 */
const BASE_PATH = '/wc-admin/fraud-protection';

export const SETTINGS_PATH = `${ BASE_PATH }/settings`;
export const SETTINGS_OPT_OUT_PATH = `${ SETTINGS_PATH }/opt-out`;
export const RULES_PATH = `${ BASE_PATH }/rules`;
export const SESSIONS_PATH = `${ BASE_PATH }/sessions`;
export const SESSION_PAYMENT_METHODS_PATH = `${ SESSIONS_PATH }/payment-methods`;
