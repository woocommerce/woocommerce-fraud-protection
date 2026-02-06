/**
 * @jest-environment jsdom
 */

/**
 * Tests for blocks checkout Blackbox integration.
 *
 * blocks-checkout.js is an IIFE — nothing is exported. We test it by:
 * 1. Setting up global mocks (window.Blackbox, window.wp.data)
 * 2. Requiring the file (which executes the IIFE)
 * 3. Asserting the mocks were called correctly
 *
 * @package WooCommerce\FraudProtection
 */

let mockSetExtensionData;
let mockCollect;
let subscribeCallback;

beforeEach( () => {
	delete window.Blackbox;
	delete window.wp;

	mockSetExtensionData = jest.fn();
	mockCollect = jest.fn( () => Promise.resolve( 'test-session-id' ) );
	subscribeCallback = null;

	window.Blackbox = { collect: mockCollect };
	window.wp = {
		data: {
			subscribe: jest.fn( ( cb ) => {
				subscribeCallback = cb;
			} ),
			select: jest.fn( () => ( {
				isProcessing: () => false,
			} ) ),
			dispatch: jest.fn( () => ( {
				setExtensionData: mockSetExtensionData,
			} ) ),
		},
	};
} );

describe( 'blocks-checkout', () => {
	it( 'collects session ID and sets extension data when store is available', async () => {
		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		// subscribe was called; invoke the callback to simulate store readiness.
		expect( subscribeCallback ).toBeInstanceOf( Function );
		subscribeCallback();

		// Let the collect() promise resolve.
		await Promise.resolve();

		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		expect( mockSetExtensionData ).toHaveBeenCalledWith(
			'woocommerce/fraud-protection',
			{ blackbox_session_id: 'test-session-id' },
			true
		);
	} );

	it( 'does not error when Blackbox is missing (fail-open)', () => {
		delete window.Blackbox;

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		expect( window.wp.data.subscribe ).toHaveBeenCalled();
		subscribeCallback();

		expect( mockCollect ).not.toHaveBeenCalled();
	} );

	it( 'does not error when wp.data is missing (fail-open)', () => {
		delete window.wp;

		expect( () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );
		} ).not.toThrow();
	} );

	it( 'swallows collect() rejection (fail-open)', async () => {
		mockCollect.mockReturnValue( Promise.reject( new Error( 'SDK error' ) ) );

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		subscribeCallback();

		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		expect( mockSetExtensionData ).not.toHaveBeenCalled();
	} );

	it( 're-collects after checkout processing completes', async () => {
		let processingState = false;
		window.wp.data.select.mockReturnValue( {
			isProcessing: () => processingState,
		} );

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		// First call: store available, isProcessing=false → initial collect.
		subscribeCallback();
		expect( mockCollect ).toHaveBeenCalledTimes( 1 );

		// Second call: processing starts.
		processingState = true;
		subscribeCallback();
		expect( mockCollect ).toHaveBeenCalledTimes( 1 );

		// Third call: processing ends → re-collect.
		processingState = false;
		subscribeCallback();
		expect( mockCollect ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'does not collect before store is available', async () => {
		let callCount = 0;
		window.wp.data.select.mockImplementation( () => {
			callCount++;
			if ( callCount === 1 ) {
				return undefined;
			}
			return { isProcessing: () => false };
		} );

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		// First subscribe callback: store not available yet.
		subscribeCallback();
		expect( mockCollect ).not.toHaveBeenCalled();

		// Second subscribe callback: store now available.
		subscribeCallback();
		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
	} );
} );
