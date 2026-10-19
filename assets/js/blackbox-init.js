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
	const PREPARATION_RETRY_DELAY_MS = 2000;
	const MAX_PREPARATION_RETRIES = 2;
	// The shopper's next activity. A click on a cross-origin express button
	// iframe only reaches this page as a window blur.
	const ACTIVITY_EVENTS = [
		[ document, 'pointerdown' ],
		[ document, 'keydown' ],
		[ document, 'touchstart' ],
		[ document, 'visibilitychange' ],
		[ window, 'blur' ],
		[ window, 'focus' ],
	];
	const handedOutSessionIds = new Set();
	let preparedSessionId = '';
	let preparedAt = 0;
	let isPreparing = false;
	// Whether a session ID is on its way to the prepared slot.
	let isFetching = false;
	let preparation = 0;
	let refreshTimer = 0;
	let retryTimer = 0;
	let preparationRetries = 0;
	let isWaitingForActivity = false;

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
	 * Fetch the current session ID ahead of time.
	 *
	 * Each try gets the same timeout as acquireSessionId(), so a session ID
	 * that never arrives is tried again instead of keeping the slot waiting.
	 */
	function prepareSessionId() {
		if ( ! isPreparing || ! window.Blackbox.getSessionId ) {
			isFetching = false;
			return;
		}

		const current = ++preparation;
		let timeoutTimer = 0;
		isFetching = true;
		Promise.resolve()
			.then( function () {
				return Promise.race( [
					window.Blackbox.getSessionId(),
					new Promise( function ( _, reject ) {
						timeoutTimer = setTimeout( function () {
							reject( new Error( 'timeout' ) );
						}, fraudProtection.config.timeout );
					} ),
				] );
			} )
			.then( function ( result ) {
				clearTimeout( timeoutTimer );
				if ( current !== preparation ) {
					return;
				}

				if ( typeof result !== 'string' || ! result ) {
					retryPreparation( current );
					return;
				}

				isFetching = false;

				// Asking again returns the same ID. The next attempt that finds
				// the slot empty starts a new session.
				if ( handedOutSessionIds.has( result ) ) {
					return;
				}

				preparationRetries = 0;
				preparedSessionId = result;
				preparedAt = Date.now();
				clearTimeout( refreshTimer );
				refreshTimer = setTimeout(
					expirePreparedSessionId,
					PREPARED_SESSION_MAX_AGE_MS
				);
			} )
			.catch( function () {
				clearTimeout( timeoutTimer );
				retryPreparation( current );
			} );
	}

	/**
	 * Try a failed preparation again, a limited number of times.
	 *
	 * @param {number} current The preparation that failed.
	 */
	function retryPreparation( current ) {
		if ( current !== preparation ) {
			return;
		}

		if ( preparationRetries >= MAX_PREPARATION_RETRIES ) {
			isFetching = false;
			return;
		}

		preparationRetries++;
		clearTimeout( retryTimer );
		retryTimer = setTimeout( function () {
			if ( current === preparation ) {
				prepareSessionId();
			}
		}, PREPARATION_RETRY_DELAY_MS );
	}

	/**
	 * Drop an unused prepared session ID that got too old.
	 */
	function expirePreparedSessionId() {
		if ( ! preparedSessionId ) {
			return;
		}

		markHandedOut( preparedSessionId );
		prepareAgainOnActivity();
	}

	/**
	 * Start a new session for the prepared slot on the shopper's next activity.
	 *
	 * Waiting for activity means a page left open without activity does not
	 * keep starting sessions.
	 */
	function prepareAgainOnActivity() {
		whenShopperIsActive( function () {
			// Another reset may have prepared a new ID in the meantime.
			if ( ! preparedSessionId && ! isFetching ) {
				fraudProtection.reset();
			}
		} );
	}

	/**
	 * Run a callback once, on the shopper's next activity on a visible page.
	 *
	 * @param {Function} callback Callback.
	 */
	function whenShopperIsActive( callback ) {
		if ( isWaitingForActivity ) {
			return;
		}

		isWaitingForActivity = true;

		const onActivity = function () {
			if ( document.visibilityState === 'hidden' ) {
				return;
			}

			ACTIVITY_EVENTS.forEach( function ( [ target, type ] ) {
				target.removeEventListener( type, onActivity, true );
			} );
			isWaitingForActivity = false;
			callback();
		};

		ACTIVITY_EVENTS.forEach( function ( [ target, type ] ) {
			target.addEventListener( type, onActivity, true );
		} );
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
		const current = ++preparation;
		isFetching = false;
		preparationRetries = 0;
		clearTimeout( refreshTimer );
		clearTimeout( retryTimer );

		if ( window.Blackbox && window.Blackbox.reset ) {
			try {
				isFetching = isPreparing;
				Promise.resolve( window.Blackbox.reset() )
					.then( prepareSessionId )
					.catch( function () {
						if ( current === preparation ) {
							isFetching = false;
						}
					} );
			} catch {
				// Fail open.
				isFetching = false;
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
			// Have one ready for the next attempt. Another payment flow may
			// have taken the prepared ID, for example a cancelled PayPal
			// payment. Refilling only here adds no session after other
			// payments that succeed.
			if ( isPreparing && ! isFetching ) {
				prepareAgainOnActivity();
			}
			return '';
		}

		// A stale ID is not sent, and must not come back after the reset either.
		const isFresh = Date.now() - preparedAt <= PREPARED_SESSION_MAX_AGE_MS;
		markHandedOut( sessionId );
		fraudProtection.reset();

		return isFresh ? sessionId : '';
	};
} )();
