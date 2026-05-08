/**
 * @jest-environment jsdom
 */

/**
 * Tests for blackbox-init.js — Blackbox SDK configuration and
 * wcFraudProtection utility registration.
 *
 * blackbox-init.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 *
 * @package WooCommerce\FraudProtection
 */

const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

let mockConfigure;
let mockGetSessionId;
let mockReset;

beforeEach( () => {
	delete window.Blackbox;
	delete window.wcFraudProtection;

	jest.useFakeTimers();

	mockConfigure = jest.fn();
	mockGetSessionId = jest.fn( () => Promise.resolve( 'test-session-id' ) );
	mockReset = jest.fn( () => Promise.resolve() );
} );

afterEach( () => {
	jest.useRealTimers();
} );

function setupAndLoad() {
	window.wcFraudProtection = { config: { apiKey: 'test-key', timeout: 3000, sessionIdField: 'wc_fraud_protection_session_id' } };
	window.Blackbox = {
		configure: mockConfigure,
		getSessionId: mockGetSessionId,
		reset: mockReset,
	};

	jest.isolateModules( () => {
		require( '../../assets/js/blackbox-init' );
	} );
}

describe( 'blackbox-init', () => {
	describe( 'configure', () => {
		it( 'calls Blackbox.configure with the apiKey from config', () => {
			setupAndLoad();

			expect( mockConfigure ).toHaveBeenCalledWith( {
				apiKey: 'test-key',
			} );
		} );

		it( 'does not error when config is missing', () => {
			window.Blackbox = { configure: mockConfigure };

			expect( () => {
				jest.isolateModules( () => {
					require( '../../assets/js/blackbox-init' );
				} );
			} ).not.toThrow();

			expect( mockConfigure ).not.toHaveBeenCalled();
		} );

		it( 'does not error when Blackbox is missing', () => {
			window.wcFraudProtection = { config: { apiKey: 'test-key', timeout: 3000, sessionIdField: 'wc_fraud_protection_session_id' } };

			expect( () => {
				jest.isolateModules( () => {
					require( '../../assets/js/blackbox-init' );
				} );
			} ).not.toThrow();
		} );
	} );

	describe( 'wcFraudProtection availability', () => {
		it( 'is set when SDK and config are both present', () => {
			setupAndLoad();

			expect( window.wcFraudProtection ).toBeDefined();
			expect( window.wcFraudProtection.acquireSessionId ).toBeInstanceOf( Function );
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
			window.wcFraudProtection = { config: { apiKey: 'test-key', timeout: 3000, sessionIdField: 'wc_fraud_protection_session_id' } };

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( window.wcFraudProtection.acquireSessionId ).toBeUndefined();
		} );
	} );

	describe( 'acquireSessionId', () => {
		it( 'resolves with session ID from getSessionId()', async () => {
			setupAndLoad();

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( 'test-session-id' );
			expect( mockGetSessionId ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resolves with empty string after the configured timeout when getSessionId never resolves', async () => {
			mockGetSessionId.mockReturnValue( new Promise( () => {} ) );
			setupAndLoad();

			const resultPromise = window.wcFraudProtection.acquireSessionId();

			const result = await jest.advanceTimersByTimeAsync( 3000 ).then( () => resultPromise );

			expect( result ).toBe( '' );
		} );

		it( 'resolves with empty string when getSessionId rejects', async () => {
			mockGetSessionId.mockReturnValue( Promise.reject( new Error( 'SDK error' ) ) );
			setupAndLoad();

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( '' );
		} );

		it( 'resolves with empty string when getSessionId resolves with non-string', async () => {
			mockGetSessionId.mockReturnValue( Promise.resolve( { message: 'Failed to fetch' } ) );
			setupAndLoad();

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( '' );
		} );

		it( 'resolves with empty string when getSessionId is missing', async () => {
			setupAndLoad();
			delete window.Blackbox.getSessionId;

			const sessionId = await window.wcFraudProtection.acquireSessionId();

			expect( sessionId ).toBe( '' );
		} );
	} );

	describe( 'reset', () => {
		it( 'calls Blackbox.reset() when available', () => {
			setupAndLoad();

			window.wcFraudProtection.reset();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'swallows reset() rejection', async () => {
			mockReset.mockReturnValue( Promise.reject( new Error( 'reset error' ) ) );
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
	} );
} );
