/**
 * @jest-environment jsdom
 */

/**
 * Tests for blocks checkout Blackbox integration.
 *
 * blocks-checkout.js is an IIFE — nothing is exported. We test it by:
 * 1. Setting up global mocks (window.Blackbox, window.wp.data, window.wc)
 * 2. Requiring the file (which executes the IIFE)
 * 3. Asserting the mocks were called correctly
 *
 * @package WooCommerce\FraudProtection
 */

let mockSetExtensionData;
let mockGetSessionId;
let mockReset;
let mockOnCheckoutValidation;
let mockOnCheckoutSuccess;
let mockOnCheckoutFail;

beforeEach( () => {
	delete window.Blackbox;
	delete window.wp;
	delete window.wc;

	jest.useFakeTimers();

	mockSetExtensionData = jest.fn();
	mockGetSessionId = jest.fn( () => Promise.resolve( 'test-session-id' ) );
	mockReset = jest.fn( () => Promise.resolve() );
	mockOnCheckoutValidation = jest.fn();
	mockOnCheckoutSuccess = jest.fn();
	mockOnCheckoutFail = jest.fn();

	window.Blackbox = { getSessionId: mockGetSessionId, reset: mockReset };
	window.wp = {
		data: {
			dispatch: jest.fn( () => ( {
				setExtensionData: mockSetExtensionData,
			} ) ),
		},
	};
	window.wc = {
		blocksCheckoutEvents: {
			checkoutEvents: {
				onCheckoutValidation: mockOnCheckoutValidation,
				onCheckoutSuccess: mockOnCheckoutSuccess,
				onCheckoutFail: mockOnCheckoutFail,
			},
		},
	};
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'blocks-checkout', () => {
	describe( 'onCheckoutValidation gate', () => {
		it( 'registers an onCheckoutValidation callback', () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			expect( mockOnCheckoutValidation ).toHaveBeenCalledTimes( 1 );
			expect( mockOnCheckoutValidation ).toHaveBeenCalledWith( expect.any( Function ) );
		} );

		it( 'acquires session ID via getSessionId and sets extension data', async () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
			expect( mockGetSessionId ).toHaveBeenCalledTimes( 1 );
			expect( mockSetExtensionData ).toHaveBeenCalledWith(
				'woocommerce/fraud-protection',
				{ blackbox_session_id: 'test-session-id' },
				true
			);
		} );

		it( 'does not set extension data when getSessionId returns empty string', async () => {
			mockGetSessionId.mockReturnValue( Promise.resolve( '' ) );

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
			expect( mockGetSessionId ).toHaveBeenCalledTimes( 1 );
			expect( mockSetExtensionData ).not.toHaveBeenCalled();
		} );

		it( 'returns true when Blackbox is missing (fail-open)', async () => {
			delete window.Blackbox;

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
		} );

		it( 'returns true when getSessionId is missing (fail-open)', async () => {
			window.Blackbox = { reset: mockReset };

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
		} );

		it( 'swallows getSessionId rejection (fail-open)', async () => {
			mockGetSessionId.mockReturnValue( Promise.reject( new Error( 'SDK error' ) ) );

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
			expect( mockSetExtensionData ).not.toHaveBeenCalled();
		} );

		it( 'swallows setExtensionData error (fail-open)', async () => {
			mockSetExtensionData.mockImplementation( () => {
				throw new Error( 'store error' );
			} );

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];

			// Should not throw — setExtensionData error is caught by the
			// promise chain and checkout proceeds.
			await expect( validationCallback() ).resolves.toBe( true );
			expect( mockSetExtensionData ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'resolves on timeout when getSessionId takes too long', async () => {
			mockGetSessionId.mockReturnValue( new Promise( () => {} ) ); // never resolves

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const resultPromise = validationCallback();

			// Advance timers and flush microtasks in one step (Jest 29.5+).
			const result = await jest.advanceTimersByTimeAsync( 5000 ).then( () => resultPromise );

			expect( result ).toBe( true );
		} );
	} );

	describe( 'reset after checkout', () => {
		it( 'registers onCheckoutSuccess and onCheckoutFail callbacks', () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			expect( mockOnCheckoutSuccess ).toHaveBeenCalledTimes( 1 );
			expect( mockOnCheckoutSuccess ).toHaveBeenCalledWith( expect.any( Function ) );
			expect( mockOnCheckoutFail ).toHaveBeenCalledTimes( 1 );
			expect( mockOnCheckoutFail ).toHaveBeenCalledWith( expect.any( Function ) );
		} );

		it( 'calls reset on checkout success', () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const successCallback = mockOnCheckoutSuccess.mock.calls[ 0 ][ 0 ];
			successCallback();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'calls reset on checkout failure', () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const failCallback = mockOnCheckoutFail.mock.calls[ 0 ][ 0 ];
			failCallback();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'swallows reset() failure (fail-open)', async () => {
			// Drain the microtask queue so we can assert on rejected promises.
			const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

			mockReset.mockReturnValue( Promise.reject( new Error( 'reset error' ) ) );

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const successCallback = mockOnCheckoutSuccess.mock.calls[ 0 ][ 0 ];
			successCallback();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );

			// Let reset() rejection settle — should not throw.
			await flushPromises();
		} );

		it( 'does not call reset when Blackbox is missing', () => {
			delete window.Blackbox;

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			const successCallback = mockOnCheckoutSuccess.mock.calls[ 0 ][ 0 ];
			successCallback();

			expect( mockReset ).not.toHaveBeenCalled();
		} );
	} );

	it( 'does not error when wc.blocksCheckoutEvents is missing (fail-open)', () => {
		delete window.wc;

		expect( () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );
		} ).not.toThrow();
	} );
} );
