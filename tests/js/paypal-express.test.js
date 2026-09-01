/**
 * @jest-environment jsdom
 */
/* eslint jsdoc/check-tag-names: off */

/**
 * Tests for paypal-express.js — Fetch interceptor for supported PayPal artifact requests.
 *
 * paypal-express.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 *
 * @package
 */

let mockAcquireSessionId;
let originalFetch;
let fetchCalls;

beforeEach( () => {
	delete window.wcFraudProtection;

	fetchCalls = [];
	originalFetch = jest.fn( ( resource, init ) => {
		fetchCalls.push( { resource, init } );
		return Promise.resolve( {
			ok: true,
			clone: jest.fn( () => ( {
				json: () => Promise.resolve( { success: true } ),
			} ) ),
		} );
	} );
	window.fetch = originalFetch;

	mockAcquireSessionId = jest.fn( () =>
		Promise.resolve( 'test-session-abc' )
	);
} );

function setupAndLoad( config ) {
	window.wcFraudProtection = {
		config: config || { sessionIdField: 'wc_fraud_protection_session_id' },
		acquireSessionId: mockAcquireSessionId,
		reset: jest.fn(),
	};

	jest.isolateModules( () => {
		require( '../../assets/js/paypal-express' );
	} );
}

function artifactResponse( ok = true, data = { success: true } ) {
	return {
		ok,
		clone: () => ( { json: () => Promise.resolve( data ) } ),
	};
}

describe( 'paypal-express fetch interceptor', () => {
	describe( 'interception', () => {
		it.each( [
			'ppc-create-order',
			'ppc-create-setup-token',
			'ppc-vault-create-order',
		] )(
			'intercepts exact %s requests and injects session_id',
			async ( endpoint ) => {
				setupAndLoad();

				const body = JSON.stringify( {
					nonce: 'abc',
					context: 'product',
				} );
				await window.fetch(
					`https://store.test/?wc-ajax=${ endpoint }`,
					{ body }
				);

				expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
				expect( fetchCalls ).toHaveLength( 1 );

				const sentBody = JSON.parse( fetchCalls[ 0 ].init.body );
				expect( sentBody.wc_fraud_protection_session_id ).toBe(
					'test-session-abc'
				);
				expect( sentBody.nonce ).toBe( 'abc' );
			}
		);

		it.each( [
			'https://store.test/?wc-ajax=ppc-create-order-copy',
			'https://store.test/?other=ppc-create-order',
		] )( 'does not intercept endpoint lookalike %s', async ( url ) => {
			setupAndLoad();

			await window.fetch( url, { body: '{}' } );

			expect( mockAcquireSessionId ).not.toHaveBeenCalled();
		} );

		it( 'does not intercept non-PayPal fetch calls', async () => {
			setupAndLoad();

			await window.fetch(
				'https://store.test/wp-json/wc/store/v1/checkout',
				{ body: '{}' }
			);

			expect( mockAcquireSessionId ).not.toHaveBeenCalled();
			expect( fetchCalls ).toHaveLength( 1 );
			expect( fetchCalls[ 0 ].init.body ).toBe( '{}' );
		} );

		it( 'handles Request objects with url property', async () => {
			setupAndLoad();

			const request = new Request(
				'https://store.test/?wc-ajax=ppc-create-order'
			);
			await window.fetch( request, { body: JSON.stringify( {} ) } );

			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'handles URL objects', async () => {
			setupAndLoad();

			const url = new URL(
				'https://store.test/?wc-ajax=ppc-create-order'
			);
			await window.fetch( url, { body: JSON.stringify( {} ) } );

			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'fail-open', () => {
		it( 'runs the original request unchanged when session acquisition rejects', async () => {
			mockAcquireSessionId.mockRejectedValueOnce(
				new Error( 'Acquisition failed' )
			);
			setupAndLoad();
			const resource =
				'https://store.test/?wc-ajax=ppc-create-setup-token';
			const init = {
				method: 'POST',
				body: JSON.stringify( { nonce: 'abc' } ),
			};

			await window.fetch( resource, init );

			expect( originalFetch ).toHaveBeenCalledTimes( 1 );
			expect( originalFetch ).toHaveBeenCalledWith( resource, init );
			expect( fetchCalls[ 0 ].init ).toBe( init );
			expect( fetchCalls[ 0 ].init.body ).toBe(
				JSON.stringify( { nonce: 'abc' } )
			);
		} );

		it( 'sends request without session_id when body is not valid JSON', async () => {
			setupAndLoad();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: 'not-json' }
			);

			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
			expect( fetchCalls ).toHaveLength( 1 );
			// Body is unchanged since JSON.parse failed.
			expect( fetchCalls[ 0 ].init.body ).toBe( 'not-json' );
		} );

		it( 'passes ppc-create-order through untouched when acquireSessionId is missing', async () => {
			// wp_localize_script always prints the config object, even when the
			// Blackbox SDK never loaded and blackbox-init.js attached no methods.
			window.wcFraudProtection = {
				config: { sessionIdField: 'wc_fraud_protection_session_id' },
			};
			jest.isolateModules( () => {
				require( '../../assets/js/paypal-express' );
			} );

			const body = JSON.stringify( { nonce: 'abc' } );
			const response = await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body }
			);

			expect( response.ok ).toBe( true );
			expect( fetchCalls ).toHaveLength( 1 );
			expect( fetchCalls[ 0 ].init.body ).toBe( body );
		} );

		it( 'intercepts when acquireSessionId is attached after the interceptor loads', async () => {
			window.wcFraudProtection = {
				config: { sessionIdField: 'wc_fraud_protection_session_id' },
			};
			jest.isolateModules( () => {
				require( '../../assets/js/paypal-express' );
			} );

			// Simulate blackbox-init.js executing later (e.g. deferred by a
			// script optimizer) and attaching the API after this script ran.
			window.wcFraudProtection.acquireSessionId = mockAcquireSessionId;
			window.wcFraudProtection.reset = jest.fn();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: JSON.stringify( { nonce: 'abc' } ) }
			);

			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
			const sentBody = JSON.parse( fetchCalls[ 0 ].init.body );
			expect( sentBody.wc_fraud_protection_session_id ).toBe(
				'test-session-abc'
			);
		} );

		it( 'does not reject the CreateOrder fetch when reset is missing', async () => {
			window.wcFraudProtection = {
				config: { sessionIdField: 'wc_fraud_protection_session_id' },
				acquireSessionId: mockAcquireSessionId,
			};
			jest.isolateModules( () => {
				require( '../../assets/js/paypal-express' );
			} );

			const response = await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: JSON.stringify( {} ) }
			);

			expect( response.ok ).toBe( true );
			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does nothing when wcFraudProtection is not available', async () => {
			// Don't call setupAndLoad — wcFraudProtection is not set.
			const savedFetch = window.fetch;

			jest.isolateModules( () => {
				require( '../../assets/js/paypal-express' );
			} );

			// fetch should not have been replaced.
			expect( window.fetch ).toBe( savedFetch );
		} );
	} );

	describe( 'reset', () => {
		it( 'keeps the session after an intercepted CreateOrder succeeds', async () => {
			setupAndLoad();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{
					body: JSON.stringify( {} ),
				}
			);

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'does not call reset for non-PayPal fetch calls', async () => {
			setupAndLoad();

			await window.fetch(
				'https://store.test/wp-json/wc/store/v1/checkout',
				{
					body: '{}',
				}
			);

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'calls reset even when fetch rejects', async () => {
			originalFetch.mockImplementationOnce( () =>
				Promise.reject( new Error( 'Network error' ) )
			);
			setupAndLoad();

			await expect(
				window.fetch( 'https://store.test/?wc-ajax=ppc-create-order', {
					body: JSON.stringify( {} ),
				} )
			).rejects.toThrow( 'Network error' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it.each( [
			[ false, { success: true } ],
			[ true, { success: false } ],
		] )( 'resets after a confirmed failed response', async ( ok, data ) => {
			originalFetch.mockResolvedValueOnce( {
				ok,
				clone: jest.fn( () => ( {
					json: () => Promise.resolve( data ),
				} ) ),
			} );
			setupAndLoad();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-setup-token',
				{
					body: '{}',
				}
			);

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resets before a later protected artifact after success', async () => {
			mockAcquireSessionId
				.mockResolvedValueOnce( 'session-1' )
				.mockResolvedValueOnce( 'session-2' );
			setupAndLoad();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: '{}' }
			);
			await window.fetch(
				'https://store.test/?wc-ajax=ppc-vault-create-order',
				{ body: '{}' }
			);

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'serializes concurrent protected artifact requests', async () => {
			let resolveFirstFetch;
			mockAcquireSessionId
				.mockResolvedValueOnce( 'S1' )
				.mockResolvedValueOnce( 'S2' );
			originalFetch
				.mockImplementationOnce(
					() =>
						new Promise( ( resolve ) => {
							resolveFirstFetch = resolve;
						} )
				)
				.mockResolvedValueOnce( artifactResponse() );
			setupAndLoad();

			const first = window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: '{}' }
			);
			const second = window.fetch(
				'https://store.test/?wc-ajax=ppc-vault-create-order',
				{ body: '{}' }
			);
			await Promise.resolve();
			await Promise.resolve();

			expect( originalFetch ).toHaveBeenCalledTimes( 1 );
			resolveFirstFetch( artifactResponse() );
			await first;
			await second;

			expect( originalFetch ).toHaveBeenCalledTimes( 2 );
			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 2 );
			expect(
				JSON.parse( originalFetch.mock.calls[ 0 ][ 1 ].body )
					.wc_fraud_protection_session_id
			).toBe( 'S1' );
			expect(
				JSON.parse( originalFetch.mock.calls[ 1 ][ 1 ].body )
					.wc_fraud_protection_session_id
			).toBe( 'S2' );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resets before a later artifact after an unreadable response', async () => {
			originalFetch.mockResolvedValueOnce( {
				ok: true,
				clone: () => ( {
					json: () => Promise.reject( new Error( 'Unreadable' ) ),
				} ),
			} );
			setupAndLoad();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: '{}' }
			);
			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-setup-token',
				{ body: '{}' }
			);
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does not reset twice when retrying after a confirmed failure', async () => {
			originalFetch.mockResolvedValueOnce(
				artifactResponse( false, { success: false } )
			);
			setupAndLoad();

			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: '{}' }
			);
			await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: '{}' }
			);

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'continues when reset throws', async () => {
			originalFetch.mockResolvedValueOnce(
				artifactResponse( false, { success: false } )
			);
			setupAndLoad();
			window.wcFraudProtection.reset.mockImplementation( () => {
				throw new Error( 'Reset failed' );
			} );

			await expect(
				window.fetch( 'https://store.test/?wc-ajax=ppc-create-order', {
					body: '{}',
				} )
			).resolves.toMatchObject( { ok: false } );
		} );

		it( 'inspects a clone and preserves the original response', async () => {
			const response = {
				ok: true,
				clone: jest.fn( () => ( {
					json: () => Promise.resolve( { success: true } ),
				} ) ),
			};
			originalFetch.mockResolvedValueOnce( response );
			setupAndLoad();

			const result = await window.fetch(
				'https://store.test/?wc-ajax=ppc-create-order',
				{ body: '{}' }
			);

			expect( result ).toBe( response );
			expect( response.clone ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
