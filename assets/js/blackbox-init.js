/**
 * Woo Fraud Protection - Blackbox Initialization
 *
 * Configures the Blackbox JS SDK and exposes shared utilities on
 * window.wcFraudProtection for checkout integration scripts.
 * Only set when the SDK is present and configured.
 */
( function () {
	'use strict';

	const fraudProtection = window.wcFraudProtection;
	if ( ! fraudProtection || ! fraudProtection.config ) {
		return;
	}

	if (
		! window.Blackbox ||
		! window.Blackbox.configure ||
		! window.Blackbox.init
	) {
		return;
	}

	window.Blackbox.configure( {
		apiKey: fraudProtection.config.apiKey,
		identityKey: fraudProtection.config.identityKey,
	} );

	// Fire-and-forget: the SDK wraps errors into a returned BlackboxError rather than
	// throwing, and downstream consumers already fail open on empty session IDs.
	window.Blackbox.init();

	/**
	 * Acquire a Blackbox session ID (fail-open: empty string on timeout/error).
	 *
	 * @return {Promise<string>} Session ID or empty string.
	 */
	fraudProtection.acquireSessionId = function () {
		if ( ! window.Blackbox || ! window.Blackbox.getSessionId ) {
			return Promise.resolve( '' );
		}

		const timeout = new Promise( function ( resolve ) {
			setTimeout( function () {
				resolve( '' );
			}, fraudProtection.config.timeout );
		} );

		return Promise.race( [ window.Blackbox.getSessionId(), timeout ] )
			.then( function ( result ) {
				return typeof result === 'string' ? result : '';
			} )
			.catch( function () {
				return '';
			} );
	};

	/**
	 * Reset Blackbox state. Silently no-ops if reset is unavailable.
	 */
	fraudProtection.reset = function () {
		if ( window.Blackbox && window.Blackbox.reset ) {
			window.Blackbox.reset().catch( function () {} );
		}
	};
} )();
