/**
 * @jest-environment jsdom
 */
/* eslint jsdoc/check-tag-names: off */

/**
 * Tests for paypal-express.js — Fetch interceptor for supported PayPal requests.
 *
 * paypal-express.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 *
 * @package
 */

let mockAcquireSessionId;
let originalFetch;
let fetchCalls;

const PROTECTED_ENDPOINTS = [
	'ppc-create-order',
	'ppc-create-setup-token',
	'ppc-vault-create-order',
];

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

function paypalResponse( ok = true, data = { success: true } ) {
	return {
		ok,
		clone: () => ( { json: () => Promise.resolve( data ) } ),
	};
}

function protectedFetch( endpoint, body = {} ) {
	return window.fetch( `https://store.test/?wc-ajax=${ endpoint }`, {
		body: JSON.stringify( body ),
	} );
}

describe( 'paypal-express fetch interceptor', () => {
	describe( 'interception', () => {
		it.each( PROTECTED_ENDPOINTS )(
			'intercepts exact %s requests and injects session_id',
			async ( endpoint ) => {
				setupAndLoad();

				await protectedFetch( endpoint, {
					nonce: 'abc',
					context: 'product',
				} );

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

		it.each( [
			{ name: 'Request', makeResource: ( url ) => new Request( url ) },
			{ name: 'URL', makeResource: ( url ) => new URL( url ) },
		] )( 'handles $name resources', async ( { makeResource } ) => {
			setupAndLoad();
			const resource = makeResource(
				'https://store.test/?wc-ajax=ppc-create-order'
			);

			await window.fetch( resource, { body: JSON.stringify( {} ) } );

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

			// The shared init script can attach the API after this interceptor loads.
			// The interceptor must resolve the API when each request starts.
			window.wcFraudProtection.acquireSessionId = mockAcquireSessionId;
			window.wcFraudProtection.reset = jest.fn();

			await protectedFetch( 'ppc-create-order', { nonce: 'abc' } );

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

			const response = await protectedFetch( 'ppc-create-order' );

			expect( response.ok ).toBe( true );
			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does nothing when wcFraudProtection is not available', async () => {
			const savedFetch = window.fetch;

			jest.isolateModules( () => {
				require( '../../assets/js/paypal-express' );
			} );

			expect( window.fetch ).toBe( savedFetch );
		} );
	} );

	describe( 'reset', () => {
		it.each( PROTECTED_ENDPOINTS )(
			'keeps the session after a successful %s request',
			async ( endpoint ) => {
				setupAndLoad();

				await protectedFetch( endpoint );

				expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
			}
		);

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

			await expect( protectedFetch( 'ppc-create-order' ) ).rejects.toThrow(
				'Network error'
			);

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it.each( [
			[ false, { success: true } ],
			[ true, { success: false } ],
		] )( 'resets after a confirmed failed response', async ( ok, data ) => {
			const response = paypalResponse( ok, data );
			originalFetch.mockResolvedValueOnce( response );
			setupAndLoad();

			const result = await protectedFetch( 'ppc-create-setup-token' );

			expect( result ).toBe( response );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resets before a later protected PayPal request after success', async () => {
			const events = [];
			mockAcquireSessionId
				.mockImplementationOnce( () => {
					events.push( 'acquire:session-1' );
					return Promise.resolve( 'session-1' );
				} )
				.mockImplementationOnce( () => {
					events.push( 'acquire:session-2' );
					return Promise.resolve( 'session-2' );
				} );
			setupAndLoad();
			window.wcFraudProtection.reset.mockImplementation( () => {
				events.push( 'reset' );
			} );

			await protectedFetch( 'ppc-create-order' );
			await protectedFetch( 'ppc-vault-create-order' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
			expect( events ).toEqual( [
				'acquire:session-1',
				'reset',
				'acquire:session-2',
			] );
		} );

		it( 'resets before a later protected PayPal request after an unreadable response', async () => {
			const events = [];
			mockAcquireSessionId
				.mockImplementationOnce( () => {
					events.push( 'acquire:S1' );
					return Promise.resolve( 'S1' );
				} )
				.mockImplementationOnce( () => {
					events.push( 'acquire:S2' );
					return Promise.resolve( 'S2' );
				} );
			originalFetch.mockResolvedValueOnce( {
				ok: true,
				clone: () => ( {
					json: () => Promise.reject( new Error( 'Unreadable' ) ),
				} ),
			} );
			setupAndLoad();
			window.wcFraudProtection.reset.mockImplementation( () => {
				events.push( 'reset' );
			} );

			await protectedFetch( 'ppc-create-order' );
			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
			expect( events ).toEqual( [ 'acquire:S1' ] );

			await protectedFetch( 'ppc-create-setup-token' );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
			expect( events ).toEqual( [ 'acquire:S1', 'reset', 'acquire:S2' ] );
		} );

		it( 'does not reset twice when retrying after a confirmed failure', async () => {
			originalFetch.mockResolvedValueOnce(
				paypalResponse( false, { success: false } )
			);
			setupAndLoad();

			await protectedFetch( 'ppc-create-order' );
			await protectedFetch( 'ppc-create-order' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
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

			const result = await protectedFetch( 'ppc-create-order' );

			expect( result ).toBe( response );
			expect( response.clone ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
