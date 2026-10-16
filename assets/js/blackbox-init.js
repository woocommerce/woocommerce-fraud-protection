/**
 * Woo Fraud Protection - Blackbox Initialization
 *
 * Configures the Blackbox JS SDK and exposes shared utilities on
 * window.wcFraudProtection for checkout integration scripts.
 * Only set when the SDK is present and configured. When the SDK is
 * missing, only acquireSessionIdResult() is set, so the plugin's own
 * scripts can still report why there is no session ID.
 */
( function () {
	'use strict';

	const fraudProtection = window.wcFraudProtection;
	if ( ! fraudProtection || ! fraudProtection.config ) {
		return;
	}

	/**
	 * Build the result of a session ID request without a session ID.
	 *
	 * @param {string} error Why there is no session ID.
	 * @return {{sessionId: string, error: string}} Result.
	 */
	function withoutSessionId( error ) {
		return { sessionId: '', error };
	}

	if (
		! window.Blackbox ||
		! window.Blackbox.configure ||
		! window.Blackbox.init
	) {
		fraudProtection.acquireSessionIdResult = function () {
			return Promise.resolve( withoutSessionId( 'sdk_unavailable' ) );
		};
		return;
	}

	// Must match the format that the server generates and accepts.
	const IDENTITY_PATTERN = /^[a-f0-9]{32}$/;

	/**
	 * Read the identity from its cookie, or create a session cookie with a new one.
	 *
	 * @param {Object} cookie Cookie settings: name, path, and domain.
	 * @return {string} Identity, or an empty string when it is unavailable.
	 */
	function getIdentity( cookie ) {
		if ( ! cookie || typeof cookie.name !== 'string' || ! cookie.name ) {
			return '';
		}

		try {
			const prefix = cookie.name + '=';
			const existing = document.cookie
				.split( ';' )
				.map( function ( part ) {
					return part.trim();
				} )
				.find( function ( part ) {
					return part.indexOf( prefix ) === 0;
				} );
			const existingIdentity = existing
				? existing.substring( prefix.length )
				: '';
			if ( IDENTITY_PATTERN.test( existingIdentity ) ) {
				return existingIdentity;
			}

			const bytes = new Uint8Array( 16 );
			window.crypto.getRandomValues( bytes );
			const identity = Array.prototype.map
				.call( bytes, function ( byte ) {
					return ( '0' + byte.toString( 16 ) ).slice( -2 );
				} )
				.join( '' );

			let attributes =
				'; path=' + ( cookie.path || '/' ) + '; SameSite=Lax';
			if ( cookie.domain ) {
				attributes += '; domain=' + cookie.domain;
			}
			if ( window.location.protocol === 'https:' ) {
				attributes += '; Secure';
			}
			document.cookie = prefix + identity + attributes;

			return identity;
		} catch {
			return '';
		}
	}

	const identity = getIdentity( fraudProtection.config.identityCookie );

	window.Blackbox.configure( {
		apiKey: fraudProtection.config.apiKey,
		identityKey: identity || undefined,
	} );

	// Fire-and-forget: the SDK wraps errors into a returned BlackboxError rather than
	// throwing, and downstream consumers already fail open on empty session IDs.
	window.Blackbox.init();

	/**
	 * Acquire a Blackbox session ID, or the reason why there is none.
	 *
	 * Fail-open: never rejects. The error is `timeout` when the SDK does not
	 * answer in time, `sdk_error` when it fails or returns no session ID, and
	 * `sdk_unavailable` when its API is missing. Internal to the plugin's own
	 * scripts.
	 *
	 * @return {Promise<{sessionId: string, error: string}>} Session ID and error.
	 */
	fraudProtection.acquireSessionIdResult = function () {
		if ( ! window.Blackbox || ! window.Blackbox.getSessionId ) {
			return Promise.resolve( withoutSessionId( 'sdk_unavailable' ) );
		}

		let timer;
		const timeout = new Promise( function ( resolve ) {
			timer = setTimeout( function () {
				resolve( withoutSessionId( 'timeout' ) );
			}, fraudProtection.config.timeout );
		} );

		let session;
		try {
			session = Promise.resolve( window.Blackbox.getSessionId() ).then(
				function ( result ) {
					return typeof result === 'string' && result
						? { sessionId: result, error: '' }
						: withoutSessionId( 'sdk_error' );
				},
				function () {
					return withoutSessionId( 'sdk_error' );
				}
			);
		} catch {
			session = Promise.resolve( withoutSessionId( 'sdk_error' ) );
		}

		return Promise.race( [ session, timeout ] ).then( function ( result ) {
			clearTimeout( timer );
			return result;
		} );
	};

	/**
	 * Acquire a Blackbox session ID (fail-open: empty string on timeout/error).
	 *
	 * @return {Promise<string>} Session ID or empty string.
	 */
	fraudProtection.acquireSessionId = function () {
		return fraudProtection
			.acquireSessionIdResult()
			.then( function ( result ) {
				return result.sessionId;
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
