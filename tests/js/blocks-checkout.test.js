/**
 * @jest-environment jsdom
 */

/**
 * Tests for blocks checkout fraud protection integration.
 *
 * blocks-checkout.js is an IIFE. We test it by:
 * 1. Setting up global mocks (window.wcFraudProtection, window.wp.data, window.wc)
 * 2. Loading blocks-checkout.js (which registers event callbacks)
 * 3. Invoking callbacks and asserting behavior
 *
 * acquireSessionId and reset are tested in blackbox-init.test.js.
 * Consumer tests mock wcFraudProtection directly.
 *
 * @package WooCommerce\FraudProtection
 */

let mockSetExtensionData;
let mockAcquireSessionId;
let mockReset;
let mockOnCheckoutValidation;
let mockOnCheckoutSuccess;
let mockOnCheckoutFail;

beforeEach( () => {
	delete window.wcFraudProtection;
	delete window.wp;
	delete window.wc;

	jest.useFakeTimers();

	mockSetExtensionData = jest.fn();
	mockAcquireSessionId = jest.fn( () => Promise.resolve( 'test-session-id' ) );
	mockReset = jest.fn();
	mockOnCheckoutValidation = jest.fn();
	mockOnCheckoutSuccess = jest.fn();
	mockOnCheckoutFail = jest.fn();

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
	delete window.wcFraudProtection;
	jest.useRealTimers();
} );

function setupFraudProtection() {
	window.wcFraudProtection = {
		acquireSessionId: mockAcquireSessionId,
		reset: mockReset,
	};
}

function loadScript() {
	jest.isolateModules( () => {
		require( '../../assets/js/blocks-checkout' );
	} );
}

describe( 'blocks-checkout', () => {
	describe( 'onCheckoutValidation gate', () => {
		it( 'acquires session ID and sets extension data', async () => {
			setupFraudProtection();
			loadScript();

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
			expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
			expect( mockSetExtensionData ).toHaveBeenCalledWith(
				'woocommerce/fraud-protection',
				{ blackbox_session_id: 'test-session-id' },
				true
			);
		} );

		it( 'does not set extension data when session ID is empty', async () => {
			mockAcquireSessionId.mockReturnValue( Promise.resolve( '' ) );
			setupFraudProtection();
			loadScript();

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
			expect( mockSetExtensionData ).not.toHaveBeenCalled();
		} );

		it( 'returns true when wcFraudProtection is missing (fail-open)', async () => {
			loadScript();

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
		} );

		it( 'swallows setExtensionData error (fail-open)', async () => {
			mockSetExtensionData.mockImplementation( () => {
				throw new Error( 'store error' );
			} );
			setupFraudProtection();
			loadScript();

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];

			await expect( validationCallback() ).resolves.toBe( true );
			expect( mockSetExtensionData ).toHaveBeenCalledTimes( 1 );
		} );
	} );

	describe( 'reset after checkout', () => {
		it( 'calls reset on checkout success', () => {
			setupFraudProtection();
			loadScript();

			const successCallback = mockOnCheckoutSuccess.mock.calls[ 0 ][ 0 ];
			successCallback();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'calls reset on checkout failure', () => {
			setupFraudProtection();
			loadScript();

			const failCallback = mockOnCheckoutFail.mock.calls[ 0 ][ 0 ];
			failCallback();

			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does not call reset when wcFraudProtection is missing', () => {
			loadScript();

			const successCallback = mockOnCheckoutSuccess.mock.calls[ 0 ][ 0 ];

			expect( () => {
				successCallback();
			} ).not.toThrow();

			expect( mockReset ).not.toHaveBeenCalled();
		} );
	} );

	it( 'does not error when wc.blocksCheckoutEvents is missing (fail-open)', () => {
		delete window.wc;

		expect( () => {
			loadScript();
		} ).not.toThrow();
	} );
} );
