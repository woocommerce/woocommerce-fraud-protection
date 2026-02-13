/**
 * @jest-environment jsdom
 */

/**
 * Tests for blackbox-init.js — configuration, getSessionId and getNewSessionId polyfills.
 *
 * blackbox-init.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 *
 * @package WooCommerce\FraudProtection
 */

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

function loadWithBlackbox( overrides = {} ) {
	window.wcBlackboxConfig = { apiKey: 'key' };
	window.Blackbox = {
		configure: mockConfigure,
		collect: mockCollect,
		reset: mockReset,
		...overrides,
	};

	jest.isolateModules( () => {
		require( '../../assets/js/blackbox-init' );
	} );
}

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
			loadWithBlackbox();

			expect( typeof window.Blackbox.getSessionId ).toBe( 'function' );
		} );

		it( 'does not overwrite native getSessionId', () => {
			const nativeGetSessionId = jest.fn( () =>
				Promise.resolve( 'native-id' )
			);

			loadWithBlackbox( { getSessionId: nativeGetSessionId } );

			expect( window.Blackbox.getSessionId ).toBe( nativeGetSessionId );
		} );

		it( 'collects and returns session_id', async () => {
			loadWithBlackbox();

			const result = await window.Blackbox.getSessionId();

			expect( result ).toBe( 'sess-abc' );
			expect( mockCollect ).toHaveBeenCalledTimes( 1 );
			expect( mockReset ).not.toHaveBeenCalled();
		} );

		it( 'does not reset on subsequent calls', async () => {
			loadWithBlackbox();

			await window.Blackbox.getSessionId();
			await window.Blackbox.getSessionId();

			expect( mockCollect ).toHaveBeenCalledTimes( 2 );
			expect( mockReset ).not.toHaveBeenCalled();
		} );

		it( 'returns empty string when collect returns no session_id', async () => {
			mockCollect.mockReturnValue( Promise.resolve( {} ) );
			loadWithBlackbox();

			const result = await window.Blackbox.getSessionId();
			expect( result ).toBe( '' );
		} );

		it( 'returns empty string when collect rejects (fail-open)', async () => {
			mockCollect.mockReturnValue(
				Promise.reject( new Error( 'collect failed' ) )
			);
			loadWithBlackbox();

			const result = await window.Blackbox.getSessionId();
			expect( result ).toBe( '' );
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

			expect( typeof window.Blackbox.getSessionId ).toBe( 'function' );

			const result = await window.Blackbox.getSessionId();
			expect( result ).toBe( 'sess-abc' );
			expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'getNewSessionId polyfill', () => {
		it( 'adds getNewSessionId when SDK does not have it', () => {
			loadWithBlackbox();

			expect( typeof window.Blackbox.getNewSessionId ).toBe( 'function' );
		} );

		it( 'resets then collects and returns session_id', async () => {
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

			loadWithBlackbox();

			const result = await window.Blackbox.getNewSessionId();

			expect( result ).toBe( 'sess-new' );
			expect( callOrder ).toEqual( [ 'reset', 'collect' ] );
		} );

		it( 'still collects when reset fails', async () => {
			mockReset.mockReturnValue(
				Promise.reject( new Error( 'reset failed' ) )
			);
			loadWithBlackbox();

			const result = await window.Blackbox.getNewSessionId();

			expect( result ).toBe( 'sess-abc' );
			expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'still collects when reset is not available', async () => {
			loadWithBlackbox( { reset: undefined } );

			const result = await window.Blackbox.getNewSessionId();

			expect( result ).toBe( 'sess-abc' );
			expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'returns empty string when collect rejects (fail-open)', async () => {
			mockCollect.mockReturnValue(
				Promise.reject( new Error( 'collect failed' ) )
			);
			loadWithBlackbox();

			const result = await window.Blackbox.getNewSessionId();
			expect( result ).toBe( '' );
		} );
	} );
} );
