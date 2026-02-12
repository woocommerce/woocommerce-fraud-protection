/**
 * @jest-environment jsdom
 */

/**
 * Tests for blackbox-init.js — configuration and getSessionId polyfill.
 *
 * blackbox-init.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 *
 * @package WooCommerce\FraudProtection
 */

const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

let mockConfigure;
let mockCollect;
let mockReset;

beforeEach( () => {
	delete window.Blackbox;
	delete window.wcBlackboxConfig;

	mockConfigure = jest.fn();
	mockCollect = jest.fn( () =>
		Promise.resolve( { data: { session_id: 'sess-abc' } } )
	);
	mockReset = jest.fn( () => Promise.resolve() );
} );

describe( 'blackbox-init', () => {
	describe( 'configure', () => {
		it( 'calls Blackbox.configure with the apiKey from config', () => {
			window.wcBlackboxConfig = { apiKey: 'test-key-123' };
			window.Blackbox = { configure: mockConfigure };

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( mockConfigure ).toHaveBeenCalledWith( {
				apiKey: 'test-key-123',
			} );
		} );

		it( 'does not error when wcBlackboxConfig is missing', () => {
			window.Blackbox = { configure: mockConfigure };

			expect( () => {
				jest.isolateModules( () => {
					require( '../../assets/js/blackbox-init' );
				} );
			} ).not.toThrow();

			expect( mockConfigure ).not.toHaveBeenCalled();
		} );

		it( 'does not error when Blackbox is missing', () => {
			window.wcBlackboxConfig = { apiKey: 'test-key' };

			expect( () => {
				jest.isolateModules( () => {
					require( '../../assets/js/blackbox-init' );
				} );
			} ).not.toThrow();
		} );
	} );

	describe( 'getSessionId polyfill', () => {
		it( 'adds getSessionId when SDK does not have it', () => {
			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( typeof window.Blackbox.getSessionId ).toBe( 'function' );
		} );

		it( 'does not overwrite native getSessionId', () => {
			const nativeGetSessionId = jest.fn( () =>
				Promise.resolve( 'native-id' )
			);
			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
				getSessionId: nativeGetSessionId,
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			expect( window.Blackbox.getSessionId ).toBe( nativeGetSessionId );
		} );

		it( 'first call collects without reset and returns session_id', async () => {
			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			const result = await window.Blackbox.getSessionId();

			expect( result ).toBe( 'sess-abc' );
			expect( mockCollect ).toHaveBeenCalledTimes( 1 );
			expect( mockReset ).not.toHaveBeenCalled();
		} );

		it( 'subsequent calls reset then collect', async () => {
			const callOrder = [];
			mockReset.mockImplementation( () => {
				callOrder.push( 'reset' );
				return Promise.resolve();
			} );
			mockCollect.mockImplementation( () => {
				callOrder.push( 'collect' );
				return Promise.resolve( {
					data: { session_id: 'sess-new' },
				} );
			} );

			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			// First call.
			await window.Blackbox.getSessionId();
			// Second call.
			const result = await window.Blackbox.getSessionId();

			expect( result ).toBe( 'sess-new' );
			expect( callOrder ).toEqual( [ 'collect', 'reset', 'collect' ] );
		} );

		it( 'returns empty string when collect returns no session_id', async () => {
			mockCollect.mockReturnValue( Promise.resolve( {} ) );

			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			const result = await window.Blackbox.getSessionId();
			expect( result ).toBe( '' );
		} );

		it( 'returns empty string when collect rejects (fail-open)', async () => {
			mockCollect.mockReturnValue(
				Promise.reject( new Error( 'collect failed' ) )
			);

			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			const result = await window.Blackbox.getSessionId();
			expect( result ).toBe( '' );
		} );

		it( 'still collects when reset fails on subsequent calls', async () => {
			mockReset.mockReturnValue(
				Promise.reject( new Error( 'reset failed' ) )
			);

			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			// First call (no reset).
			await window.Blackbox.getSessionId();
			// Second call (reset fails, still collects).
			const result = await window.Blackbox.getSessionId();

			expect( result ).toBe( 'sess-abc' );
			expect( mockCollect ).toHaveBeenCalledTimes( 2 );
		} );

		it( 'wraps frozen Blackbox object and still works', async () => {
			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = Object.freeze( {
				configure: mockConfigure,
				collect: mockCollect,
				reset: mockReset,
			} );

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			// getSessionId should be available despite frozen object.
			expect( typeof window.Blackbox.getSessionId ).toBe( 'function' );

			const result = await window.Blackbox.getSessionId();
			expect( result ).toBe( 'sess-abc' );

			// Original methods still accessible via prototype chain.
			expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'works when reset is not available', async () => {
			window.wcBlackboxConfig = { apiKey: 'key' };
			window.Blackbox = {
				configure: mockConfigure,
				collect: mockCollect,
				// No reset method.
			};

			jest.isolateModules( () => {
				require( '../../assets/js/blackbox-init' );
			} );

			// First call.
			await window.Blackbox.getSessionId();
			// Second call — no reset available, should still collect.
			const result = await window.Blackbox.getSessionId();

			expect( result ).toBe( 'sess-abc' );
			expect( mockCollect ).toHaveBeenCalledTimes( 2 );
		} );
	} );
} );
