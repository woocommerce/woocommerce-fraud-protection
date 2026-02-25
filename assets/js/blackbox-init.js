/**
 * Woo Fraud Protection - Blackbox Initialization
 *
 * Configures the Blackbox JS SDK and exposes shared utilities on
 * window.wcFraudProtection for checkout integration scripts.
 * Only set when the SDK is present and configured.
 */
( function () {
	'use strict';

	const TIMEOUT_MS = 5000;

	const config = window.wcBlackboxConfig;
	if ( ! config ) {
		return;
	}

	if ( ! window.Blackbox || ! window.Blackbox.configure ) {
		return;
	}

	window.Blackbox.configure( {
		apiKey: config.apiKey,
	} );

	window.wcFraudProtection = {
		/**
		 * Acquire a Blackbox session ID (fail-open: empty string on timeout/error).
		 *
		 * @return {Promise<string>} Session ID or empty string.
		 */
		acquireSessionId() {
			if ( ! window.Blackbox || ! window.Blackbox.getSessionId ) {
				return Promise.resolve( '' );
			}

			const timeout = new Promise( function ( resolve ) {
				setTimeout( function () {
					resolve( '' );
				}, TIMEOUT_MS );
			} );

			return Promise.race( [
				window.Blackbox.getSessionId(),
				timeout,
			] ).catch( function () {
				return '';
			} );
		},

		/**
		 * Reset Blackbox state. Silently no-ops if reset is unavailable.
		 */
		reset() {
			if ( window.Blackbox && window.Blackbox.reset ) {
				window.Blackbox.reset().catch( function () {} );
			}
		},
	};
} )();
