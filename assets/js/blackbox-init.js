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
