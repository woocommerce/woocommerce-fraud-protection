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

	// A session ID is valid for one payment attempt, for a limited time. These
	// track the IDs already handed out, and the one prepared for callers that
	// cannot wait for a Promise.
	const PREPARED_SESSION_MAX_AGE_MS = 10 * 60 * 1000;
	const PREPARATION_RETRY_DELAY_MS = 2000;
	const MAX_PREPARATION_RETRIES = 2;
	// flush() enforces the timeout itself. This margin only bounds an SDK
	// that breaks that contract.
	const FLUSH_GRACE_MS = 500;
	// The shopper may need a while to complete a challenge, but a payment
	// attempt must not wait on the SDK forever.
	const MAX_CHALLENGE_WAIT_MS = 5 * 60 * 1000;
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
	 * Uses getSessionId(), not flush(): ahead of the payment attempt there is
	 * no newer data to send, and flush() could show a challenge before the
	 * shopper starts a payment.
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
	 * Convert a flush() result into a session ID result.
	 *
	 * @param {*} flushed Value that flush() resolved with.
	 * @return {{result: {sessionId: string, error: string}, challengePending: boolean}} Result, and whether a challenge is active.
	 */
	function fromFlushResult( flushed ) {
		if ( ! flushed || typeof flushed !== 'object' ) {
			return {
				result: withoutSessionId( 'sdk_error' ),
				challengePending: false,
			};
		}

		// flush() keeps a valid session ID on a timeout or error.
		const sessionId =
			typeof flushed.sessionId === 'string' ? flushed.sessionId : '';
		let result = { sessionId, error: '' };
		if ( ! sessionId ) {
			result = withoutSessionId(
				flushed.outcome === 'timeout' ? 'timeout' : 'sdk_error'
			);
		}

		return { result, challengePending: flushed.challengePending === true };
	}

	/**
	 * Send the current session data with flush(), within the timeout.
	 *
	 * Never rejects.
	 *
	 * @return {Promise<{result: {sessionId: string, error: string}, challengePending: boolean}>} Result, and whether a challenge is active.
	 */
	function flushOnce() {
		let flushed;
		try {
			flushed = Promise.resolve(
				window.Blackbox.flush( {
					deadlineMs: fraudProtection.config.timeout,
				} )
			).then( fromFlushResult, function () {
				return fromFlushResult( null );
			} );
		} catch {
			return Promise.resolve( fromFlushResult( null ) );
		}

		let timer;
		const timeout = new Promise( function ( resolve ) {
			timer = setTimeout( function () {
				resolve( {
					result: withoutSessionId( 'timeout' ),
					challengePending: false,
				} );
			}, fraudProtection.config.timeout + FLUSH_GRACE_MS );
		} );

		return Promise.race( [ flushed, timeout ] ).then( function ( outcome ) {
			clearTimeout( timer );
			return outcome;
		} );
	}

	/**
	 * Wait until the active challenge ends, or for at most MAX_CHALLENGE_WAIT_MS.
	 *
	 * Never rejects.
	 *
	 * @return {Promise<void>} Resolves when the wait ends.
	 */
	function waitForChallengeEnd() {
		return new Promise( function ( resolve ) {
			let isDone = false;
			let unsubscribe;
			const finish = function () {
				if ( isDone ) {
					return;
				}

				isDone = true;
				clearTimeout( timer );
				if ( typeof unsubscribe === 'function' ) {
					try {
						unsubscribe();
					} catch {
						// The wait is over either way.
					}
				}
				resolve();
			};
			const timer = setTimeout( finish, MAX_CHALLENGE_WAIT_MS );

			if ( typeof window.Blackbox.subscribe !== 'function' ) {
				finish();
				return;
			}

			try {
				// The listener is called immediately with the current state.
				unsubscribe = window.Blackbox.subscribe( function ( state ) {
					if ( ! state || state.status !== 'challenge' ) {
						finish();
					}
				} );
			} catch {
				finish();
				return;
			}

			if ( isDone && typeof unsubscribe === 'function' ) {
				try {
					unsubscribe();
				} catch {
					// The wait is over either way.
				}
			}
		} );
	}

	/**
	 * Acquire a session ID with flush(), after any challenge it reports.
	 *
	 * After a challenge, a second flush() gets the session ID that is current
	 * then, without waiting for another challenge.
	 *
	 * @return {Promise<{sessionId: string, error: string}>} Session ID and error.
	 */
	function acquireByFlush() {
		return flushOnce().then( function ( first ) {
			if ( ! first.challengePending ) {
				return first.result;
			}

			return waitForChallengeEnd()
				.then( flushOnce )
				.then( function ( second ) {
					return second.result;
				} );
		} );
	}

	/**
	 * Acquire a session ID with getSessionId(), for SDK versions without flush().
	 *
	 * @return {Promise<{sessionId: string, error: string}>} Session ID and error.
	 */
	function acquireByGetSessionId() {
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
	}

	/**
	 * Acquire a Blackbox session ID, or the reason why there is none.
	 *
	 * Uses flush(), so the session has the shopper's latest data when the
	 * payment is verified. When flush() reports an active challenge, waits
	 * for it to end before resolving. Falls back to getSessionId() when the
	 * SDK has no flush().
	 *
	 * Fail-open: never rejects. The error is `timeout` when the SDK does not
	 * answer in time, `sdk_error` when it fails or returns no session ID, and
	 * `sdk_unavailable` when its API is missing. Internal to the plugin's own
	 * scripts.
	 *
	 * @return {Promise<{sessionId: string, error: string}>} Session ID and error.
	 */
	fraudProtection.acquireSessionIdResult = function () {
		let request;
		if ( window.Blackbox && typeof window.Blackbox.flush === 'function' ) {
			request = acquireByFlush();
		} else if ( window.Blackbox && window.Blackbox.getSessionId ) {
			request = acquireByGetSessionId();
		} else {
			return Promise.resolve( withoutSessionId( 'sdk_unavailable' ) );
		}

		return request.then( function ( result ) {
			// The payment flows get their session ID here, so record it before
			// the prepared slot can hand it out again.
			markHandedOut( result.sessionId );
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
		preparedSessionId = '';
		const current = ++preparation;
		isFetching = false;
		preparationRetries = 0;
		clearTimeout( refreshTimer );
		clearTimeout( retryTimer );

		if ( window.Blackbox && window.Blackbox.reset ) {
			let timeoutTimer = 0;
			try {
				isFetching = isPreparing;
				// Like each preparation, a reset that never settles must not
				// keep the slot waiting.
				Promise.race( [
					Promise.resolve( window.Blackbox.reset() ),
					new Promise( function ( _, reject ) {
						timeoutTimer = setTimeout( function () {
							reject( new Error( 'timeout' ) );
						}, fraudProtection.config.timeout );
					} ),
				] )
					.then( function () {
						clearTimeout( timeoutTimer );
						// A later reset prepares its own session.
						if ( current === preparation ) {
							prepareSessionId();
						}
					} )
					.catch( function () {
						clearTimeout( timeoutTimer );
						if ( current === preparation ) {
							isFetching = false;
						}
					} );
			} catch {
				clearTimeout( timeoutTimer );
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
