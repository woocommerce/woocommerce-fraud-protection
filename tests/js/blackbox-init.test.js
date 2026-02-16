/**
 * @jest-environment jsdom
 */

/**
 * Tests for blackbox-init.js — Blackbox SDK configuration.
 *
 * blackbox-init.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and asserting on mocks.
 *
 * @package WooCommerce\FraudProtection
 */

let mockConfigure;

beforeEach( () => {
	delete window.Blackbox;
	delete window.wcBlackboxConfig;

	mockConfigure = jest.fn();
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
} );
