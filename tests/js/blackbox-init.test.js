/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for blackbox-init.js — Blackbox SDK configuration and
 * wcFraudProtection utility registration.
 *
 * blackbox-init.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 */

const flushPromises = () =>
	new Promise( jest.requireActual( 'timers' ).setImmediate );

const EXISTING_IDENTITY = '0123456789abcdef0123456789abcdef';

let mockConfigure;
let mockInit;
let mockGetSessionId;
let mockFlush;
let mockReset;

function flushed( overrides = {} ) {
	return Promise.resolve( {
		sessionId: 'test-session-id',
		challengePending: false,
		outcome: 'ok',
		...overrides,
	} );
}

beforeEach( () => {
	delete window.Blackbox;
	delete window.wcFraudProtection;
	document.cookie.split( ';' ).forEach( ( part ) => {
		const name = part.split( '=' )[ 0 ].trim();
		if ( name ) {
			document.cookie = `${ name }=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT`;
		}
	} );

	jest.useFakeTimers();

	mockConfigure = jest.fn();
	mockInit = jest.fn( () => Promise.resolve() );
	mockGetSessionId = jest.fn( () => Promise.resolve( 'test-session-id' ) );
	mockFlush = jest.fn( () => flushed() );
	mockReset = jest.fn( () => Promise.resolve() );
} );

afterEach( () => {
	// Release activity listeners left armed by a test, without letting them
	// reach the next test's SDK mocks.
	delete window.Blackbox;
	document.dispatchEvent( new Event( 'pointerdown' ) );
	jest.useRealTimers();
	jest.restoreAllMocks();
} );

function setupAndLoad( configOverrides = {} ) {
	window.wcFraudProtection = {
		config: {
			apiKey: 'test-key',
			identityCookie: { name: 'wfp_id', path: '/', domain: '' },
			timeout: 3000,
			sessionIdField: 'wc_fraud_protection_session_id',
			...configOverrides,
		},
	};
	window.Blackbox = {
		configure: mockConfigure,
		init: mockInit,
		getSessionId: mockGetSessionId,
		flush: mockFlush,
		reset: mockReset,
	};

	jest.isolateModules( () => {
		require( '../../assets/js/blackbox-init' );
	} );
}

describe( 'blackbox-init', () => {
	describe( 'configure', () => {
		it( 'calls Blackbox.configure with apiKey and the identity from the cookie', () => {
			document.cookie = `wfp_id=${ EXISTING_IDENTITY }; path=/`;
			setupAndLoad();

			expect( mockConfigure ).toHaveBeenCalledWith( {
				apiKey: 'test-key',
				identityKey: EXISTING_IDENTITY,
			} );
		} );

		it( 'creates a session cookie with a new identity when none exists', () => {
			const cookieSetter = jest.spyOn( document, 'cookie', 'set' );
			setupAndLoad();

			const identity = mockConfigure.mock.calls[ 0 ][ 0 ].identityKey;
			expect( identity ).toMatch( /^[a-f0-9]{32}$/ );
			expect( document.cookie ).toContain( `wfp_id=${ identity }` );
			expect( cookieSetter ).toHaveBeenCalledWith(
				`wfp_id=${ identity }; path=/; SameSite=Lax`
			);
		} );

		it.each( [
			[ 'invalid', 'not-a-valid-identity' ],
			[ 'Tracks-shaped', 'abcdefghijklmnopqrstuvwx' ],
		] )(
			'replaces an %s cookie value with a new identity',
			( _, value ) => {
				document.cookie = `wfp_id=${ value }; path=/`;
				setupAndLoad();

				const identity = mockConfigure.mock.calls[ 0 ][ 0 ].identityKey;
				expect( identity ).toMatch( /^[a-f0-9]{32}$/ );
				expect( document.cookie ).toContain( `wfp_id=${ identity }` );
			}
		);

		it( 'ignores the tk_ai cookie', () => {
			document.cookie = `tk_ai=${ EXISTING_IDENTITY }; path=/`;
			setupAndLoad();

			const identity = mockConfigure.mock.calls[ 0 ][ 0 ].identityKey;
			expect( identity ).toMatch( /^[a-f0-9]{32}$/ );
			expect( identity ).not.toBe( EXISTING_IDENTITY );
		} );

		it( 'configures without an identity when cookie settings are missing', () => {
			setupAndLoad( { identityCookie: undefined } );

			expect( document.cookie ).not.toContain( 'wfp_id=' );
			expect( mockConfigure ).toHaveBeenCalledWith( {
				apiKey: 'test-key',
				identityKey: undefined,
			} );
		} );

		it( 'calls Blackbox.init() after configure()', () => {
			setupAndLoad();

			expect( mockInit ).toHaveBeenCalledTimes( 1 );
			expect( mockInit ).toHaveBeenCalledWith();
			expect(
				mockConfigure.mock.invocationCallOrder[ 0 ] <
					mockInit.mock.invocationCallOrder[ 0 ]
			).toBe( true );
		} );

		it( 'does not error when config is missing', () => {
			window.Blackbox = { configure: mockConfigure, init: mockInit };

			expect( () => {
				jest.isolateModules( () => {
					require( '../../assets/js/blackbox-init' );
				} );
			} ).not.toThrow();

			expect( mockConfigure ).not.toHaveBeenCalled();
		} );

		it( 'does not error when Blackbox is missing', () => {
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					identityCookie: { name: 'wfp_id', path: '/', domain: '' },
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};

			expect( () => {
				jest.isolateModules( () => {
					require( '../../assets/js/blackbox-init' );
				} );
			} ).not.toThrow();
		} );

		it( 'does not call configure or init when Blackbox.init is missing', () => {
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					identityCookie: { name: 'wfp_id', path: '/', domain: '' },
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};
			window.Blackbox = { configure: mockConfigure };

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( mockConfigure ).not.toHaveBeenCalled();
			expect( mockInit ).not.toHaveBeenCalled();
		} );
	} );

	describe( 'wcFraudProtection availability', () => {
		it( 'is set when SDK and config are both present', () => {
			setupAndLoad();

			expect( window.wcFraudProtection ).toBeDefined();
			expect( window.wcFraudProtection.acquireSessionId ).toBeInstanceOf(
				Function
			);
			expect( window.wcFraudProtection.reset ).toBeInstanceOf( Function );
		} );

		it( 'is NOT set when config is missing', () => {
			window.Blackbox = { configure: jest.fn() };

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( window.wcFraudProtection ).toBeUndefined();
		} );

		it( 'is NOT set when SDK is missing', () => {
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( window.wcFraudProtection.acquireSessionId ).toBeUndefined();
		} );
	} );

	describe( 'acquireSessionId', () => {
		it( 'resolves with the session ID from flush()', async () => {
			setupAndLoad();

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( 'test-session-id' );
			expect( mockFlush ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resolves with empty string when flush() times out without a session ID', async () => {
			mockFlush.mockImplementation( () =>
				flushed( { sessionId: '', outcome: 'timeout' } )
			);
			setupAndLoad();

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( '' );
		} );

		it( 'resolves with empty string when flush() rejects', async () => {
			mockFlush.mockReturnValue(
				Promise.reject( new Error( 'SDK error' ) )
			);
			setupAndLoad();

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( '' );
		} );

		it( 'resolves with empty string when flush() is missing', async () => {
			setupAndLoad();
			delete window.Blackbox.flush;

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( '' );
		} );
	} );

	describe( 'acquireSessionIdResult', () => {
		it( 'resolves with the session ID from flush(), with the configured deadline', async () => {
			setupAndLoad();

			await expect(
				window.wcFraudProtection.acquireSessionIdResult()
			).resolves.toEqual( { sessionId: 'test-session-id', error: '' } );
			expect( mockFlush ).toHaveBeenCalledWith( { deadlineMs: 3000 } );
			expect( mockGetSessionId ).not.toHaveBeenCalled();
		} );

		it.each( [ 'timeout', 'error' ] )(
			'keeps the session ID that flush() returns with the %s outcome',
			async ( outcome ) => {
				mockFlush.mockImplementation( () => flushed( { outcome } ) );
				setupAndLoad();

				await expect(
					window.wcFraudProtection.acquireSessionIdResult()
				).resolves.toEqual( {
					sessionId: 'test-session-id',
					error: '',
				} );
			}
		);

		it.each( [
			[ 'timeout', 'timeout' ],
			[ 'error', 'sdk_error' ],
			[ 'ok', 'sdk_error' ],
		] )(
			'reports %s without a session ID as %s',
			async ( outcome, error ) => {
				mockFlush.mockImplementation( () =>
					flushed( { sessionId: '', outcome } )
				);
				setupAndLoad();

				await expect(
					window.wcFraudProtection.acquireSessionIdResult()
				).resolves.toEqual( { sessionId: '', error } );
			}
		);

		it.each( [
			[ 'rejects', () => Promise.reject( new Error( 'SDK error' ) ) ],
			[
				'throws',
				() => {
					throw new Error( 'SDK error' );
				},
			],
			[ 'resolves with a non-object', () => Promise.resolve( 'id' ) ],
			[
				'resolves with a non-string session ID',
				() => flushed( { sessionId: {} } ),
			],
		] )( 'reports an SDK error when flush() %s', async ( _, impl ) => {
			mockFlush.mockImplementation( impl );
			setupAndLoad();

			await expect(
				window.wcFraudProtection.acquireSessionIdResult()
			).resolves.toEqual( { sessionId: '', error: 'sdk_error' } );
			await expect(
				window.wcFraudProtection.acquireSessionId()
			).resolves.toBe( '' );
		} );

		it( 'does not wait for a challenge that flush() reports', async () => {
			mockFlush.mockImplementation( () =>
				flushed( { challengePending: true } )
			);
			setupAndLoad();

			await expect(
				window.wcFraudProtection.acquireSessionIdResult()
			).resolves.toEqual( { sessionId: 'test-session-id', error: '' } );
			expect( mockFlush ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'reports an unavailable SDK when flush() is missing', async () => {
			setupAndLoad();
			delete window.Blackbox.flush;

			await expect(
				window.wcFraudProtection.acquireSessionIdResult()
			).resolves.toEqual( { sessionId: '', error: 'sdk_unavailable' } );
			expect( mockGetSessionId ).not.toHaveBeenCalled();
		} );

		it( 'sets only the methods that work without the SDK when it is missing', async () => {
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( window.wcFraudProtection.acquireSessionId ).toBeUndefined();
			expect( window.wcFraudProtection.reset ).toBeUndefined();
			await expect(
				window.wcFraudProtection.acquireSessionIdResult()
			).resolves.toEqual( { sessionId: '', error: 'sdk_unavailable' } );
			await expect(
				window.wcFraudProtection.takeSessionId()
			).resolves.toBe( '' );
		} );
	} );

	describe( 'addSessionIdToExtensions', () => {
		const NAMESPACE = 'woocommerce/fraud-protection';

		it( 'adds the prepared session ID under the plugin namespace and starts a fresh session', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			const original = { 'woocommerce/order-attribution': { a: 1 } };

			const result =
				window.wcFraudProtection.addSessionIdToExtensions( original );

			expect( result ).toEqual( {
				'woocommerce/order-attribution': { a: 1 },
				[ NAMESPACE ]: { blackbox_session_id: 'test-session-id' },
			} );
			expect( original ).toEqual( {
				'woocommerce/order-attribution': { a: 1 },
			} );
			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'replaces an earlier session ID with not_ready when none is prepared', () => {
			setupAndLoad();

			expect(
				window.wcFraudProtection.addSessionIdToExtensions( {
					other: { a: 1 },
					[ NAMESPACE ]: { blackbox_session_id: 'used-session-id' },
				} )
			).toEqual( {
				other: { a: 1 },
				[ NAMESPACE ]: { blackbox_session_id_error: 'not_ready' },
			} );
		} );

		it.each( [
			[ 'undefined', undefined ],
			[ 'null', null ],
			[ 'an array', [ 'value' ] ],
			[ 'a string', 'value' ],
		] )( 'returns %s unchanged', ( _, value ) => {
			setupAndLoad();

			expect(
				window.wcFraudProtection.addSessionIdToExtensions( value )
			).toBe( value );
		} );

		it( 'is set with the prepared-session methods and reports sdk_unavailable when the SDK is missing', () => {
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( () =>
				window.wcFraudProtection.prepareSessionId()
			).not.toThrow();
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			expect(
				window.wcFraudProtection.addSessionIdToExtensions( {
					[ NAMESPACE ]: { blackbox_session_id: 'used-session-id' },
				} )
			).toEqual( {
				[ NAMESPACE ]: { blackbox_session_id_error: 'sdk_unavailable' },
			} );
		} );
	} );

	describe( 'takeSessionId', () => {
		it( 'resolves with the session ID and starts a fresh session', async () => {
			setupAndLoad();

			await expect(
				window.wcFraudProtection.takeSessionId()
			).resolves.toBe( 'test-session-id' );
			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resolves with an empty string without a reset when flush() rejects', async () => {
			mockFlush.mockReturnValue(
				Promise.reject( new Error( 'SDK error' ) )
			);
			setupAndLoad();

			await expect(
				window.wcFraudProtection.takeSessionId()
			).resolves.toBe( '' );
			expect( mockReset ).not.toHaveBeenCalled();
		} );

		it( 'resolves with an empty string without a reset when flush() times out without a session ID', async () => {
			mockFlush.mockImplementation( () =>
				flushed( { sessionId: '', outcome: 'timeout' } )
			);
			setupAndLoad();

			await expect(
				window.wcFraudProtection.takeSessionId()
			).resolves.toBe( '' );
			expect( mockReset ).not.toHaveBeenCalled();
		} );

		it( 'resolves with the session ID when the reset throws', async () => {
			setupAndLoad();
			window.wcFraudProtection.reset = () => {
				throw new Error( 'Reset failed' );
			};

			await expect(
				window.wcFraudProtection.takeSessionId()
			).resolves.toBe( 'test-session-id' );
		} );

		it( 'never lets takePreparedSessionId() hand out the same session ID', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			await expect(
				window.wcFraudProtection.takeSessionId()
			).resolves.toBe( 'test-session-id' );
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
		} );
	} );

	describe( 'reset', () => {
		it( 'calls Blackbox.reset() when available', () => {
			setupAndLoad();

			window.wcFraudProtection.reset();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'swallows reset() rejection', async () => {
			mockReset.mockReturnValue(
				Promise.reject( new Error( 'reset error' ) )
			);
			setupAndLoad();

			expect( async () => {
				window.wcFraudProtection.reset();
				await flushPromises();
			} ).not.toThrow();
		} );

		it( 'does nothing when Blackbox.reset is missing', () => {
			setupAndLoad();
			delete window.Blackbox.reset;

			expect( () => {
				window.wcFraudProtection.reset();
			} ).not.toThrow();
		} );

		it( 'swallows a reset() that throws or returns no Promise', () => {
			mockReset.mockImplementationOnce( () => {
				throw new Error( 'reset error' );
			} );
			mockReset.mockImplementationOnce( () => undefined );
			setupAndLoad();

			expect( () => {
				window.wcFraudProtection.reset();
				window.wcFraudProtection.reset();
			} ).not.toThrow();
		} );
	} );

	describe( 'prepared session ID', () => {
		it( 'is not fetched until a script asks for it', async () => {
			setupAndLoad();
			await flushPromises();

			expect( mockGetSessionId ).not.toHaveBeenCalled();
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
		} );

		it( 'is fetched with getSessionId(), not flush()', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			expect( mockGetSessionId ).toHaveBeenCalledTimes( 1 );
			expect( mockFlush ).not.toHaveBeenCalled();
		} );

		it( 'is handed out once, then a fresh session is started', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'test-session-id'
			);
			expect( mockReset ).toHaveBeenCalledTimes( 1 );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
		} );

		it( 'is replaced with the session started after a reset', async () => {
			mockGetSessionId
				.mockResolvedValueOnce( 'first-session-id' )
				.mockResolvedValueOnce( 'second-session-id' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			window.wcFraudProtection.takePreparedSessionId();
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'second-session-id'
			);
		} );

		it( 'is not handed out after acquireSessionId() returned it', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			await window.wcFraudProtection.acquireSessionId();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
		} );

		it( 'is not handed out after acquireSessionIdResult() returned it', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			const result =
				await window.wcFraudProtection.acquireSessionIdResult();

			expect( result.sessionId ).toBe( 'test-session-id' );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
		} );

		it( 'is not prepared again after acquireSessionId() returned it, until an attempt finds none', async () => {
			mockGetSessionId
				.mockResolvedValueOnce( 'first-session-id' )
				.mockResolvedValueOnce( 'second-session-id' );
			mockFlush.mockImplementation( () =>
				flushed( { sessionId: 'first-session-id' } )
			);
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			// Another payment flow, such as PayPal, takes the prepared session.
			await window.wcFraudProtection.acquireSessionId();
			window.dispatchEvent( new Event( 'focus' ) );
			await flushPromises();
			expect( mockReset ).not.toHaveBeenCalled();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			window.dispatchEvent( new Event( 'focus' ) );
			await flushPromises();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'second-session-id'
			);
		} );

		it( 'is prepared again on the next activity after an attempt finds it already handed out', async () => {
			let resolveRefill;
			mockGetSessionId
				.mockResolvedValueOnce( 'first-session-id' )
				.mockReturnValueOnce(
					new Promise( ( resolve ) => {
						resolveRefill = resolve;
					} )
				)
				.mockResolvedValueOnce( 'third-session-id' );
			mockFlush.mockImplementation( () =>
				flushed( { sessionId: 'second-session-id' } )
			);
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			window.wcFraudProtection.takePreparedSessionId();
			await flushPromises();

			// Another payment flow takes the new session before it reaches the slot.
			await window.wcFraudProtection.acquireSessionId();
			resolveRefill( 'second-session-id' );
			await flushPromises();
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);

			document.dispatchEvent( new Event( 'pointerdown' ) );
			await flushPromises();

			expect( mockReset ).toHaveBeenCalledTimes( 2 );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'third-session-id'
			);
		} );

		it( 'is prepared again on the next activity after an attempt found none', async () => {
			mockGetSessionId
				.mockRejectedValueOnce( new Error( 'SDK error' ) )
				.mockRejectedValueOnce( new Error( 'SDK error' ) )
				.mockRejectedValueOnce( new Error( 'SDK error' ) )
				.mockResolvedValueOnce( 'recovered-session-id' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			for ( let i = 0; i < 2; i++ ) {
				jest.advanceTimersByTime( 2000 );
				await flushPromises();
			}

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			expect( mockReset ).not.toHaveBeenCalled();

			window.dispatchEvent( new Event( 'blur' ) );
			await flushPromises();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'recovered-session-id'
			);
		} );

		it( 'does not start another session on activity while one is on its way', async () => {
			let resolveRefill;
			mockGetSessionId
				.mockResolvedValueOnce( 'first-session-id' )
				.mockReturnValueOnce(
					new Promise( ( resolve ) => {
						resolveRefill = resolve;
					} )
				);
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			window.wcFraudProtection.takePreparedSessionId();
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			document.dispatchEvent( new Event( 'pointerdown' ) );
			resolveRefill( 'second-session-id' );
			await flushPromises();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'second-session-id'
			);
		} );

		it( 'never repeats a session ID that was already handed out', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			window.wcFraudProtection.takePreparedSessionId();
			await flushPromises();

			expect( mockGetSessionId ).toHaveBeenCalledTimes( 2 );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
		} );

		it( 'is not handed out once it is too old, and a fresh session is started', async () => {
			mockGetSessionId
				.mockResolvedValueOnce( 'first-session-id' )
				.mockResolvedValueOnce( 'second-session-id' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			const now = Date.now();
			jest.spyOn( Date, 'now' ).mockReturnValue(
				now + 10 * 60 * 1000 + 1
			);

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			expect( mockReset ).toHaveBeenCalledTimes( 1 );

			await flushPromises();
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'second-session-id'
			);
		} );

		it( 'is dropped when it gets too old, without starting a session on an idle page', async () => {
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			jest.advanceTimersByTime( 10 * 60 * 1000 );
			await flushPromises();

			expect( mockReset ).not.toHaveBeenCalled();
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
		} );

		it.each( [
			[ 'a pointer press', document, 'pointerdown' ],
			[ 'a key press', document, 'keydown' ],
			[ 'a touch', document, 'touchstart' ],
			[ 'focus moving to an express button frame', window, 'blur' ],
		] )(
			'is replaced after it gets too old, on %s',
			async ( _, target, type ) => {
				mockGetSessionId
					.mockResolvedValueOnce( 'first-session-id' )
					.mockResolvedValueOnce( 'second-session-id' );
				setupAndLoad();
				window.wcFraudProtection.prepareSessionId();
				await flushPromises();
				jest.advanceTimersByTime( 10 * 60 * 1000 );

				target.dispatchEvent( new Event( type ) );
				target.dispatchEvent( new Event( type ) );
				await flushPromises();

				expect( mockReset ).toHaveBeenCalledTimes( 1 );
				expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
					'second-session-id'
				);
			}
		);

		it( 'is replaced on a hidden page only once it is visible and active again', async () => {
			const visibility = jest
				.spyOn( document, 'visibilityState', 'get' )
				.mockReturnValue( 'hidden' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			jest.advanceTimersByTime( 10 * 60 * 1000 );

			window.dispatchEvent( new Event( 'blur' ) );
			expect( mockReset ).not.toHaveBeenCalled();

			visibility.mockReturnValue( 'visible' );
			document.dispatchEvent( new Event( 'visibilitychange' ) );

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'is not replaced on activity when a reset already started a new session', async () => {
			mockGetSessionId
				.mockResolvedValueOnce( 'first-session-id' )
				.mockResolvedValueOnce( 'second-session-id' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			jest.advanceTimersByTime( 10 * 60 * 1000 );

			window.wcFraudProtection.reset();
			await flushPromises();
			document.dispatchEvent( new Event( 'pointerdown' ) );

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'is prepared again after a failed attempt', async () => {
			mockGetSessionId
				.mockRejectedValueOnce( new Error( 'SDK error' ) )
				.mockResolvedValueOnce( 'retried-session-id' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);

			jest.advanceTimersByTime( 2000 );
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'retried-session-id'
			);
		} );

		it( 'stops preparing after two failed retries', async () => {
			mockGetSessionId.mockRejectedValue( new Error( 'SDK error' ) );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			for ( let i = 0; i < 5; i++ ) {
				jest.advanceTimersByTime( 2000 );
				await flushPromises();
			}

			expect( mockGetSessionId ).toHaveBeenCalledTimes( 3 );
		} );

		it( 'is prepared again when getSessionId does not answer in time', async () => {
			mockGetSessionId
				.mockReturnValueOnce( new Promise( () => {} ) )
				.mockResolvedValueOnce( 'retried-session-id' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			jest.advanceTimersByTime( 3000 );
			await flushPromises();
			jest.advanceTimersByTime( 2000 );
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'retried-session-id'
			);
		} );

		it( 'starts a new session on the next activity when getSessionId never answers', async () => {
			mockGetSessionId.mockReturnValue( new Promise( () => {} ) );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			for ( let i = 0; i < 3; i++ ) {
				jest.advanceTimersByTime( 3000 );
				await flushPromises();
				jest.advanceTimersByTime( 2000 );
				await flushPromises();
			}
			expect( mockGetSessionId ).toHaveBeenCalledTimes( 3 );

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			document.dispatchEvent( new Event( 'pointerdown' ) );

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'ignores a session ID that resolves after a reset', async () => {
			let resolveFirst;
			mockGetSessionId
				.mockReturnValueOnce(
					new Promise( ( resolve ) => {
						resolveFirst = resolve;
					} )
				)
				.mockResolvedValueOnce( 'second-session-id' );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			window.wcFraudProtection.reset();
			await flushPromises();
			resolveFirst( 'first-session-id' );
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'second-session-id'
			);
		} );

		it( 'is prepared again on the next activity when Blackbox.reset() never settles', async () => {
			mockGetSessionId
				.mockResolvedValueOnce( 'first-session-id' )
				.mockResolvedValueOnce( 'second-session-id' );
			mockReset.mockReturnValueOnce( new Promise( () => {} ) );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			window.wcFraudProtection.takePreparedSessionId();

			jest.advanceTimersByTime( 3000 );
			await flushPromises();
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			document.dispatchEvent( new Event( 'pointerdown' ) );
			await flushPromises();

			expect( mockReset ).toHaveBeenCalledTimes( 2 );
			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				'second-session-id'
			);
		} );

		it( 'is prepared only by the latest of overlapping resets', async () => {
			let resolveFirstReset;
			mockReset
				.mockReturnValueOnce(
					new Promise( ( resolve ) => {
						resolveFirstReset = resolve;
					} )
				)
				.mockResolvedValueOnce( undefined );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();
			expect( mockGetSessionId ).toHaveBeenCalledTimes( 1 );

			window.wcFraudProtection.reset();
			window.wcFraudProtection.reset();
			await flushPromises();
			resolveFirstReset();
			await flushPromises();

			expect( mockGetSessionId ).toHaveBeenCalledTimes( 2 );
		} );

		it.each( [
			[ 'rejects', () => Promise.reject( new Error( 'SDK error' ) ) ],
			[ 'resolves with a non-string', () => Promise.resolve( {} ) ],
			[ 'resolves with an empty string', () => Promise.resolve( '' ) ],
		] )( 'is empty when getSessionId %s', async ( _, implementation ) => {
			mockGetSessionId.mockImplementation( implementation );
			setupAndLoad();
			window.wcFraudProtection.prepareSessionId();
			await flushPromises();

			expect( window.wcFraudProtection.takePreparedSessionId() ).toBe(
				''
			);
			expect( mockReset ).not.toHaveBeenCalled();
		} );
	} );
} );
