/**
 * @jest-environment jsdom
 */
/* eslint jsdoc/check-tag-names: off */

/**
 * Tests for paypal-express.js — Fetch interceptor for supported PayPal requests.
 *
 * paypal-express.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
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
	delete window.jQuery;
	delete window.wp;

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

function fetchPayPalEndpoint( endpoint, body = {} ) {
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

				await fetchPayPalEndpoint( endpoint, {
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

	describe( 'missing session ID reason', () => {
		it( 'sends the reason next to an empty session ID', async () => {
			setupAndLoad( {
				sessionIdField: 'wc_fraud_protection_session_id',
				sessionIdErrorField: 'wc_fraud_protection_session_id_error',
			} );
			window.wcFraudProtection.acquireSessionIdResult = jest.fn( () =>
				Promise.resolve( { sessionId: '', error: 'timeout' } )
			);

			await fetchPayPalEndpoint( 'ppc-create-order', { nonce: 'abc' } );

			expect( mockAcquireSessionId ).not.toHaveBeenCalled();
			expect( JSON.parse( fetchCalls[ 0 ].init.body ) ).toEqual( {
				nonce: 'abc',
				wc_fraud_protection_session_id: '',
				wc_fraud_protection_session_id_error: 'timeout',
			} );
		} );

		it( 'does not send a reason with a session ID', async () => {
			setupAndLoad( {
				sessionIdField: 'wc_fraud_protection_session_id',
				sessionIdErrorField: 'wc_fraud_protection_session_id_error',
			} );
			window.wcFraudProtection.acquireSessionIdResult = jest.fn( () =>
				Promise.resolve( { sessionId: 'sess-result', error: '' } )
			);

			await fetchPayPalEndpoint( 'ppc-create-order', { nonce: 'abc' } );

			expect( JSON.parse( fetchCalls[ 0 ].init.body ) ).toEqual( {
				nonce: 'abc',
				wc_fraud_protection_session_id: 'sess-result',
			} );
		} );

		it( 'reports a missing SDK when only acquireSessionIdResult is available', async () => {
			window.wcFraudProtection = {
				config: {
					sessionIdField: 'wc_fraud_protection_session_id',
					sessionIdErrorField: 'wc_fraud_protection_session_id_error',
				},
				acquireSessionIdResult: () =>
					Promise.resolve( {
						sessionId: '',
						error: 'sdk_unavailable',
					} ),
			};
			jest.isolateModules( () => {
				require( '../../assets/js/paypal-express' );
			} );

			await fetchPayPalEndpoint( 'ppc-create-order', { nonce: 'abc' } );

			expect( JSON.parse( fetchCalls[ 0 ].init.body ) ).toEqual( {
				nonce: 'abc',
				wc_fraud_protection_session_id: '',
				wc_fraud_protection_session_id_error: 'sdk_unavailable',
			} );
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
			// wp_localize_script always prints the config object, even when
			// blackbox-init.js attached no methods.
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

			await fetchPayPalEndpoint( 'ppc-create-order', { nonce: 'abc' } );

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

			const response = await fetchPayPalEndpoint( 'ppc-create-order' );

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

	describe( 'another gateway after a successful request', () => {
		let $;

		function renderClassicCheckout( selectedGateway ) {
			document.body.innerHTML = `
				<form class="checkout">
					<input type="radio" name="payment_method" value="ppcp-gateway" />
					<input type="radio" name="payment_method" value="ppcp-credit-card-gateway" />
					<input type="radio" name="payment_method" value="cod" />
				</form>`;
			selectGateway( selectedGateway );
		}

		function selectGateway( gateway ) {
			$( 'input[name="payment_method"]' )
				.filter( `[value="${ gateway }"]` )
				.prop( 'checked', true );
		}

		function setupBlocksStore( initialGateway ) {
			let gateway = initialGateway;
			const listeners = [];
			window.wp = {
				data: {
					select: () => ( {
						getActivePaymentMethod: () => gateway,
					} ),
					subscribe: ( listener ) => listeners.push( listener ),
				},
			};

			return ( nextGateway ) => {
				gateway = nextGateway;
				listeners.forEach( ( listener ) => listener() );
			};
		}

		beforeEach( () => {
			jest.isolateModules( () => {
				$ = require( 'jquery' );
			} );
			window.jQuery = $;
		} );

		afterEach( () => {
			$( document.body ).off();
			document.body.innerHTML = '';
		} );

		it( 'resets when the shopper selects another classic gateway', async () => {
			renderClassicCheckout( 'ppcp-gateway' );
			setupAndLoad();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			selectGateway( 'cod' );
			$( 'input[value="cod"]' ).trigger( 'change' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resets on a place order for another classic gateway', async () => {
			renderClassicCheckout( 'cod' );
			setupAndLoad();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			const result = $( 'form.checkout' ).triggerHandler(
				'checkout_place_order'
			);

			expect( result ).toBeUndefined();
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'keeps the session for the classic PayPal gateways', async () => {
			renderClassicCheckout( 'cod' );
			selectGateway( 'ppcp-gateway' );
			setupAndLoad();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			$( 'form.checkout' ).triggerHandler( 'checkout_place_order' );
			selectGateway( 'ppcp-credit-card-gateway' );
			$( 'input[value="ppcp-credit-card-gateway"]' ).trigger( 'change' );

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'resets once, and again only after another PayPal request', async () => {
			renderClassicCheckout( 'cod' );
			setupAndLoad();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			$( 'form.checkout' ).triggerHandler( 'checkout_place_order' );
			$( 'form.checkout' ).triggerHandler( 'checkout_place_order' );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );

			await fetchPayPalEndpoint( 'ppc-create-order' );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );

			$( 'form.checkout' ).triggerHandler( 'checkout_place_order' );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 2 );
		} );

		it( 'does not reset for another gateway before a PayPal request succeeds', async () => {
			const setGateway = setupBlocksStore( 'ppcp-gateway' );
			renderClassicCheckout( 'cod' );
			setupAndLoad();

			$( 'form.checkout' ).triggerHandler( 'checkout_place_order' );
			setGateway( 'stripe' );

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'resets when the Checkout block changes to another gateway', async () => {
			const setGateway = setupBlocksStore( 'ppcp-gateway' );
			setupAndLoad();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			setGateway( 'ppcp-gateway' );
			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();

			setGateway( 'stripe' );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does not reset while the Checkout block keeps another gateway active', async () => {
			const setGateway = setupBlocksStore( 'stripe' );
			setupAndLoad();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			setGateway( 'stripe' );

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'keeps the session when the Checkout block moves between PayPal gateways', async () => {
			const setGateway = setupBlocksStore( 'ppcp-gateway' );
			setupAndLoad();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			setGateway( 'ppcp-credit-card-gateway' );

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'does not fail the request when the checkout scripts are unavailable', async () => {
			delete window.jQuery;
			window.wp = {
				data: {
					select: () => {
						throw new Error( 'No store' );
					},
					subscribe: jest.fn(),
				},
			};
			setupAndLoad();

			const response = await fetchPayPalEndpoint( 'ppc-create-order' );

			expect( response.ok ).toBe( true );
		} );

		it( 'gets a new session for the other gateway with the real session API', async () => {
			const flushMock = jest
				.fn()
				.mockResolvedValueOnce( {
					sessionId: 'paypal-id',
					outcome: 'ok',
				} )
				.mockResolvedValueOnce( {
					sessionId: 'paypal-id',
					outcome: 'ok',
				} )
				.mockResolvedValueOnce( {
					sessionId: 'new-id',
					outcome: 'ok',
				} );
			window.Blackbox = {
				configure: jest.fn(),
				init: jest.fn(),
				flush: flushMock,
				reset: jest.fn( () => Promise.resolve() ),
			};
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};
			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
				require( '../../assets/js/paypal-express' );
			} );

			await fetchPayPalEndpoint( 'ppc-create-order' );
			const result =
				await window.wcFraudProtection.acquireSessionIdResult();

			expect(
				JSON.parse( fetchCalls[ 0 ].init.body )
					.wc_fraud_protection_session_id
			).toBe( 'paypal-id' );
			expect( result ).toEqual( { sessionId: 'new-id', error: '' } );
			delete window.Blackbox;
		} );
	} );

	describe( 'reset', () => {
		it.each( PROTECTED_ENDPOINTS )(
			'keeps the session after a successful %s request',
			async ( endpoint ) => {
				setupAndLoad();

				await fetchPayPalEndpoint( endpoint );

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
			window.wcFraudProtection.reset.mockImplementation( () => {
				throw new Error( 'Reset failed' );
			} );

			await expect(
				fetchPayPalEndpoint( 'ppc-create-order' )
			).rejects.toThrow( 'Network error' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it.each( [
			[ false, { success: true } ],
			[ true, { success: false } ],
		] )( 'resets after a confirmed failed response', async ( ok, data ) => {
			const response = paypalResponse( ok, data );
			originalFetch.mockResolvedValueOnce( response );
			setupAndLoad();
			window.wcFraudProtection.reset.mockImplementation( () => {
				throw new Error( 'Reset failed' );
			} );

			const result = await fetchPayPalEndpoint(
				'ppc-create-setup-token'
			);

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

			await fetchPayPalEndpoint( 'ppc-create-order' );
			await fetchPayPalEndpoint( 'ppc-vault-create-order' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
			expect( events ).toEqual( [
				'acquire:session-1',
				'reset',
				'acquire:session-2',
			] );
		} );

		it( 'continues a later protected request when reset throws', async () => {
			setupAndLoad();

			await fetchPayPalEndpoint( 'ppc-create-order' );
			window.wcFraudProtection.reset.mockImplementation( () => {
				throw new Error( 'Reset failed' );
			} );

			const response = await fetchPayPalEndpoint(
				'ppc-vault-create-order'
			);

			expect( response.ok ).toBe( true );
			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 2 );
			expect( originalFetch ).toHaveBeenCalledTimes( 2 );
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

			await fetchPayPalEndpoint( 'ppc-create-order' );
			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
			expect( events ).toEqual( [ 'acquire:S1' ] );

			await fetchPayPalEndpoint( 'ppc-create-setup-token' );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
			expect( events ).toEqual( [ 'acquire:S1', 'reset', 'acquire:S2' ] );
		} );

		it( 'does not reset twice when retrying after a confirmed failure', async () => {
			originalFetch.mockResolvedValueOnce(
				paypalResponse( false, { success: false } )
			);
			setupAndLoad();

			await fetchPayPalEndpoint( 'ppc-create-order' );
			await fetchPayPalEndpoint( 'ppc-create-order' );

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

			const result = await fetchPayPalEndpoint( 'ppc-create-order' );

			expect( result ).toBe( response );
			expect( response.clone ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'session ID for the checkout that completes the request', () => {
		function setupWithHandOver() {
			setupAndLoad();
			window.wcFraudProtection.handOverSessionId = jest.fn();
			return window.wcFraudProtection.handOverSessionId;
		}

		it.each( [ 'ppc-create-setup-token', 'ppc-vault-create-order' ] )(
			'is handed over after a successful %s request',
			async ( endpoint ) => {
				const handOverSessionId = setupWithHandOver();

				await fetchPayPalEndpoint( endpoint );

				expect( handOverSessionId ).toHaveBeenCalledTimes( 1 );
				expect( handOverSessionId ).toHaveBeenCalledWith(
					'test-session-abc'
				);
			}
		);

		it( 'is not handed over after a create-order request', async () => {
			const handOverSessionId = setupWithHandOver();

			await fetchPayPalEndpoint( 'ppc-create-order' );

			expect( handOverSessionId ).not.toHaveBeenCalled();
		} );

		it.each( [
			[ 'a failed', paypalResponse( true, { success: false } ) ],
			[
				'an unreadable',
				{
					ok: true,
					clone: () => ( {
						json: () => Promise.reject( new Error( 'Unreadable' ) ),
					} ),
				},
			],
		] )( 'is not handed over after %s response', async ( _, response ) => {
			originalFetch.mockResolvedValueOnce( response );
			const handOverSessionId = setupWithHandOver();

			await fetchPayPalEndpoint( 'ppc-vault-create-order' );

			expect( handOverSessionId ).not.toHaveBeenCalled();
		} );

		it( 'is not handed over when the request sent no session ID', async () => {
			mockAcquireSessionId.mockResolvedValueOnce( '' );
			const handOverSessionId = setupWithHandOver();

			await fetchPayPalEndpoint( 'ppc-vault-create-order' );

			expect( handOverSessionId ).not.toHaveBeenCalled();
		} );

		it( 'does not fail the request when the hand-over throws', async () => {
			const handOverSessionId = setupWithHandOver();
			handOverSessionId.mockImplementation( () => {
				throw new Error( 'Hand-over failed' );
			} );

			const response = await fetchPayPalEndpoint(
				'ppc-vault-create-order'
			);

			expect( response.ok ).toBe( true );
		} );

		it( 'is sent once by the checkout with the real session API', async () => {
			const flushMock = jest
				.fn()
				.mockResolvedValueOnce( {
					sessionId: 'vault-id',
					outcome: 'ok',
				} )
				.mockResolvedValueOnce( {
					sessionId: 'vault-id',
					outcome: 'ok',
				} )
				.mockResolvedValueOnce( {
					sessionId: 'vault-id',
					outcome: 'ok',
				} )
				.mockResolvedValueOnce( {
					sessionId: 'new-id',
					outcome: 'ok',
				} );
			window.Blackbox = {
				configure: jest.fn(),
				init: jest.fn(),
				flush: flushMock,
				reset: jest.fn( () => Promise.resolve() ),
			};
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};
			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
				require( '../../assets/js/paypal-express' );
			} );

			await fetchPayPalEndpoint( 'ppc-vault-create-order' );
			const checkout =
				await window.wcFraudProtection.acquireSessionIdResult();
			const retry =
				await window.wcFraudProtection.acquireSessionIdResult();

			expect(
				JSON.parse( fetchCalls[ 0 ].init.body )
					.wc_fraud_protection_session_id
			).toBe( 'vault-id' );
			expect( checkout ).toEqual( { sessionId: 'vault-id', error: '' } );
			expect( retry ).toEqual( { sessionId: 'new-id', error: '' } );
			delete window.Blackbox;
		} );
	} );

	describe( 'request while the Checkout block processes an order', () => {
		function setupCheckoutStore( {
			isProcessing = true,
			sessionId = 'checkout-id',
		} = {} ) {
			window.wp = {
				data: {
					select: ( store ) =>
						'wc/store/checkout' === store
							? {
									isProcessing: () => isProcessing,
									getExtensionData: () => ( {
										'woocommerce/fraud-protection': {
											blackbox_session_id: sessionId,
										},
									} ),
							  }
							: { getActivePaymentMethod: () => '' },
					subscribe: jest.fn(),
				},
			};
		}

		function sentSessionId( call = 0 ) {
			return JSON.parse( fetchCalls[ call ].init.body )
				.wc_fraud_protection_session_id;
		}

		it.each( PROTECTED_ENDPOINTS )(
			'sends the checkout session ID with %s',
			async ( endpoint ) => {
				setupCheckoutStore();
				setupAndLoad();

				await fetchPayPalEndpoint( endpoint );

				expect( mockAcquireSessionId ).not.toHaveBeenCalled();
				expect( sentSessionId() ).toBe( 'checkout-id' );
			}
		);

		it.each( [
			[ 'is not processing', { isProcessing: false } ],
			[ 'has no session ID', { sessionId: '' } ],
		] )(
			'acquires a session ID when the checkout %s',
			async ( _, state ) => {
				setupCheckoutStore( state );
				setupAndLoad();

				await fetchPayPalEndpoint( 'ppc-create-setup-token' );

				expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
				expect( sentSessionId() ).toBe( 'test-session-abc' );
			}
		);

		it( 'does not reset or hand over the checkout session ID', async () => {
			setupCheckoutStore( { isProcessing: false } );
			setupAndLoad();
			window.wcFraudProtection.handOverSessionId = jest.fn();
			await fetchPayPalEndpoint( 'ppc-create-order' );

			setupCheckoutStore();
			await fetchPayPalEndpoint( 'ppc-create-setup-token' );

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
			expect(
				window.wcFraudProtection.handOverSessionId
			).not.toHaveBeenCalled();
			expect( sentSessionId( 1 ) ).toBe( 'checkout-id' );
		} );

		it( 'shares the checkout session ID with the real session API', async () => {
			const flushMock = jest.fn().mockResolvedValue( {
				sessionId: 'checkout-id',
				outcome: 'ok',
			} );
			window.Blackbox = {
				configure: jest.fn(),
				init: jest.fn(),
				flush: flushMock,
				reset: jest.fn( () => Promise.resolve() ),
			};
			window.wcFraudProtection = {
				config: {
					apiKey: 'test-key',
					timeout: 3000,
					sessionIdField: 'wc_fraud_protection_session_id',
				},
			};
			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
				require( '../../assets/js/paypal-express' );
			} );

			const checkout =
				await window.wcFraudProtection.acquireSessionIdResult();
			setupCheckoutStore( { sessionId: checkout.sessionId } );
			await fetchPayPalEndpoint( 'ppc-create-setup-token' );

			expect( sentSessionId() ).toBe( 'checkout-id' );
			expect( flushMock ).toHaveBeenCalledTimes( 1 );
			expect( window.Blackbox.reset ).not.toHaveBeenCalled();
			delete window.Blackbox;
		} );
	} );
} );
