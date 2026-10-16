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

	// A session ID is valid for one payment attempt, for a limited time. These
	// track the IDs already handed out, and the one prepared for callers that
	// cannot wait for a Promise.
	const PREPARED_SESSION_MAX_AGE_MS = 10 * 60 * 1000;
	const handedOutSessionIds = new Set();
	let preparedSessionId = '';
	let preparedAt = 0;
	let isPreparing = false;
	let preparation = 0;
	let refreshTimer = 0;
	let isWaitingForVisibility = false;

	/**
	 * Record a session ID as handed out for a payment attempt.
	 *
	 * @param {string} sessionId Session ID.
	 */
	function markHandedOut( sessionId ) {
		if ( ! sessionId ) {
			return;
		}

		handedOutSessionIds.add( sessionId );
		if ( preparedSessionId === sessionId ) {
			preparedSessionId = '';
		}
	}

	/**
	 * Fetch the current session ID ahead of time, without a timeout.
	 */
	function prepareSessionId() {
		if ( ! isPreparing || ! window.Blackbox.getSessionId ) {
			return;
		}

		const current = ++preparation;
		Promise.resolve()
			.then( function () {
				return window.Blackbox.getSessionId();
			} )
			.then( function ( result ) {
				if (
					current === preparation &&
					typeof result === 'string' &&
					result &&
					! handedOutSessionIds.has( result )
				) {
					preparedSessionId = result;
					preparedAt = Date.now();
					clearTimeout( refreshTimer );
					refreshTimer = setTimeout(
						refreshPreparedSessionId,
						PREPARED_SESSION_MAX_AGE_MS
					);
				}
			} )
			.catch( function () {} );
	}

	/**
	 * Replace an unused prepared session ID before it gets too old.
	 *
	 * A hidden page waits until it is visible again.
	 */
	function refreshPreparedSessionId() {
		if ( ! preparedSessionId || isWaitingForVisibility ) {
			return;
		}

		if ( document.visibilityState === 'hidden' ) {
			isWaitingForVisibility = true;
			document.addEventListener(
				'visibilitychange',
				function () {
					isWaitingForVisibility = false;
					refreshPreparedSessionId();
				},
				{ once: true }
			);
			return;
		}

		fraudProtection.reset();
	}

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
				const sessionId = typeof result === 'string' ? result : '';
				markHandedOut( sessionId );
				return sessionId;
			} )
			.catch( function () {
				return '';
			} );
	};

	/**
	 * Reset Blackbox state. Silently no-ops if reset is unavailable.
	 */
	fraudProtection.reset = function () {
		preparedSessionId = '';
		++preparation;
		clearTimeout( refreshTimer );

		if ( window.Blackbox && window.Blackbox.reset ) {
			try {
				Promise.resolve( window.Blackbox.reset() )
					.then( prepareSessionId )
					.catch( function () {} );
			} catch {
				// Fail open.
			}
		}
	};

	/**
	 * Keep a session ID ready for takePreparedSessionId().
	 *
	 * Internal to the plugin's own scripts.
	 */
	fraudProtection.prepareSessionId = function () {
		if ( isPreparing ) {
			return;
		}

		isPreparing = true;
		prepareSessionId();
	};

	/**
	 * Hand out the prepared session ID for a payment attempt, at most once.
	 *
	 * For callers that must add the ID synchronously. A fresh session is
	 * started for the next attempt. Internal to the plugin's own scripts.
	 *
	 * @return {string} Session ID, or an empty string when none is ready.
	 */
	fraudProtection.takePreparedSessionId = function () {
		const sessionId = preparedSessionId;
		if ( ! sessionId ) {
			return '';
		}

		// A stale ID is not sent, and must not come back after the reset either.
		const isFresh = Date.now() - preparedAt <= PREPARED_SESSION_MAX_AGE_MS;
		markHandedOut( sessionId );
		fraudProtection.reset();

		return isFresh ? sessionId : '';
	};
} )();
