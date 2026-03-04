/**
 * @jest-environment jsdom
 */

/**
 * Tests for paypal-express.js — Fetch interceptor for PayPal CreateOrder AJAX.
 *
 * paypal-express.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 *
 * @package WooCommerce\FraudProtection
 */

let mockAcquireSessionId;
let originalFetch;
let fetchCalls;

beforeEach( () => {
	delete window.wcFraudProtection;

	fetchCalls = [];
	originalFetch = jest.fn( ( resource, init ) => {
		fetchCalls.push( { resource, init } );
		return Promise.resolve( { ok: true, json: () => Promise.resolve( {} ) } );
	} );
	window.fetch = originalFetch;

	mockAcquireSessionId = jest.fn( () => Promise.resolve( 'test-session-abc' ) );
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

describe( 'paypal-express fetch interceptor', () => {
	describe( 'interception', () => {
		it( 'intercepts ppc-create-order requests and injects session_id', async () => {
			setupAndLoad();

			const body = JSON.stringify( { nonce: 'abc', context: 'product' } );
			await window.fetch( 'https://store.test/?wc-ajax=ppc-create-order', { body } );

			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
			expect( fetchCalls ).toHaveLength( 1 );

			const sentBody = JSON.parse( fetchCalls[ 0 ].init.body );
			expect( sentBody.wc_fraud_protection_session_id ).toBe( 'test-session-abc' );
			expect( sentBody.nonce ).toBe( 'abc' );
		} );

		it( 'does not intercept non-PayPal fetch calls', async () => {
			setupAndLoad();

			await window.fetch( 'https://store.test/wp-json/wc/store/v1/checkout', { body: '{}' } );

			expect( mockAcquireSessionId ).not.toHaveBeenCalled();
			expect( fetchCalls ).toHaveLength( 1 );
			expect( fetchCalls[ 0 ].init.body ).toBe( '{}' );
		} );

		it( 'handles Request objects with url property', async () => {
			setupAndLoad();

			const request = { url: 'https://store.test/?wc-ajax=ppc-create-order' };
			await window.fetch( request, { body: JSON.stringify( {} ) } );

			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'fail-open', () => {
		it( 'sends request without session_id when body is not valid JSON', async () => {
			setupAndLoad();

			await window.fetch( 'https://store.test/?wc-ajax=ppc-create-order', { body: 'not-json' } );

			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
			expect( fetchCalls ).toHaveLength( 1 );
			// Body is unchanged since JSON.parse failed.
			expect( fetchCalls[ 0 ].init.body ).toBe( 'not-json' );
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
		it( 'calls reset after intercepted CreateOrder fetch returns', async () => {
			setupAndLoad();

			await window.fetch( 'https://store.test/?wc-ajax=ppc-create-order', {
				body: JSON.stringify( {} ),
			} );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does not call reset for non-PayPal fetch calls', async () => {
			setupAndLoad();

			await window.fetch( 'https://store.test/wp-json/wc/store/v1/checkout', {
				body: '{}',
			} );

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'calls reset even when fetch rejects', async () => {
			originalFetch.mockImplementationOnce( () => Promise.reject( new Error( 'Network error' ) ) );
			setupAndLoad();

			await expect(
				window.fetch( 'https://store.test/?wc-ajax=ppc-create-order', {
					body: JSON.stringify( {} ),
				} )
			).rejects.toThrow( 'Network error' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );
	} );
} );
