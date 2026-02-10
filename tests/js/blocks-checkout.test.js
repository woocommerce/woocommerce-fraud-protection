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

// Drain the microtask queue (all pending .then() handlers) so we can assert
// on side-effects of internal promise chains we can't reference directly.
// Uses the real setImmediate (bypasses fake timers) which fires after all
// microtasks have settled.
const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

let mockSetExtensionData;
let mockCollect;
let mockReset;
let mockOnCheckoutValidation;
let subscribeCallback;

beforeEach( () => {
	delete window.Blackbox;
	delete window.wp;
	delete window.wc;

	jest.useFakeTimers();

	mockSetExtensionData = jest.fn();
	mockCollect = jest.fn( () => Promise.resolve( { data: { session_id: 'test-session-id' } } ) );
	mockReset = jest.fn( () => Promise.resolve() );
	mockOnCheckoutValidation = jest.fn();
	subscribeCallback = null;

	window.Blackbox = { collect: mockCollect, reset: mockReset };
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
	window.wc = {
		blocksCheckoutEvents: {
			checkoutEvents: {
				onCheckoutValidation: mockOnCheckoutValidation,
			},
		},
	};
} );

afterEach( () => {
	jest.useRealTimers();
} );

describe( 'blocks-checkout', () => {
	it( 'collects session ID and sets extension data when store is available', async () => {
		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		// subscribe was called; invoke the callback to simulate store readiness.
		expect( subscribeCallback ).toBeInstanceOf( Function );
		subscribeCallback();

		await flushPromises();

		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		expect( mockSetExtensionData ).toHaveBeenCalledWith(
			'woocommerce/fraud-protection',
			{ blackbox_session_id: 'test-session-id' },
			true
		);
	} );

	it( 'does not set extension data when collect() returns no session_id (fail-open)', async () => {
		mockCollect.mockReturnValue( Promise.resolve( {} ) );

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		subscribeCallback();
		await flushPromises();

		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		expect( mockSetExtensionData ).not.toHaveBeenCalled();
	} );

	it( 'does not set extension data when collect() resolves to null (fail-open)', async () => {
		mockCollect.mockReturnValue( Promise.resolve( null ) );

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		subscribeCallback();
		await flushPromises();

		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		expect( mockSetExtensionData ).not.toHaveBeenCalled();
	} );

	it( 'swallows setExtensionData error (fail-open)', async () => {
		mockSetExtensionData.mockImplementation( () => {
			throw new Error( 'store error' );
		} );

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		subscribeCallback();
		await flushPromises();

		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		expect( mockSetExtensionData ).toHaveBeenCalledTimes( 1 );
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
		await flushPromises();

		expect( mockCollect ).toHaveBeenCalledTimes( 1 );
		expect( mockSetExtensionData ).not.toHaveBeenCalled();
	} );

	it( 're-collects with reset after checkout processing completes', async () => {
		const callOrder = [];
		mockReset.mockImplementation( () => {
			callOrder.push( 'reset' );
			return Promise.resolve();
		} );
		mockCollect.mockImplementation( () => {
			callOrder.push( 'collect' );
			return Promise.resolve( { data: { session_id: 'test-session-id' } } );
		} );

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

		// Clear the initial collect from the order log.
		callOrder.length = 0;

		// Third call: processing ends → reset then re-collect.
		processingState = false;
		subscribeCallback();
		await flushPromises();

		expect( mockReset ).toHaveBeenCalledTimes( 1 );
		expect( mockCollect ).toHaveBeenCalledTimes( 2 );
		expect( callOrder ).toEqual( [ 'reset', 'collect' ] );
	} );

	it( 'calls collect even when reset() fails (fail-open)', async () => {
		let processingState = false;
		window.wp.data.select.mockReturnValue( {
			isProcessing: () => processingState,
		} );
		mockReset.mockReturnValue( Promise.reject( new Error( 'reset error' ) ) );

		jest.isolateModules( () => {
			require( '../../assets/js/blocks-checkout' );
		} );

		// Initial collect.
		subscribeCallback();
		expect( mockCollect ).toHaveBeenCalledTimes( 1 );

		// Transition through processing.
		processingState = true;
		subscribeCallback();
		processingState = false;
		subscribeCallback();

		expect( mockReset ).toHaveBeenCalledTimes( 1 );

		// Let reset() rejection settle so catch handler fires collect.
		await flushPromises();
		await flushPromises();

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

	describe( 'onCheckoutValidation gate', () => {
		it( 'registers an onCheckoutValidation callback', () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			expect( mockOnCheckoutValidation ).toHaveBeenCalledTimes( 1 );
			expect( mockOnCheckoutValidation ).toHaveBeenCalledWith( expect.any( Function ) );
		} );

		it( 'resolves immediately when collect is already settled', async () => {
			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			// Trigger initial collect.
			subscribeCallback();
			await flushPromises();

			// Invoke the validation callback.
			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			const result = await validationCallback();

			expect( result ).toBe( true );
		} );

		it( 'waits for in-flight collect before returning', async () => {
			let resolveCollect;
			mockCollect.mockReturnValue(
				new Promise( ( resolve ) => {
					resolveCollect = resolve;
				} )
			);

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			// Trigger initial collect (still pending).
			subscribeCallback();

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];

			// Start validation while collect is still in-flight.
			const validationPromise = validationCallback();

			// Resolve the collect promise — the race settles from this leg.
			resolveCollect( { data: { session_id: 'test-session-id' } } );

			// await naturally flushes the microtask chain (no manual tick count).
			const result = await validationPromise;
			expect( result ).toBe( true );

			// The 5-second timeout is still pending, proving the collect leg won.
			expect( jest.getTimerCount() ).toBe( 1 );
		} );

		it( 'resolves on timeout when collect takes too long', async () => {
			mockCollect.mockReturnValue( new Promise( () => {} ) ); // never resolves

			jest.isolateModules( () => {
				require( '../../assets/js/blocks-checkout' );
			} );

			subscribeCallback();

			const validationCallback = mockOnCheckoutValidation.mock.calls[ 0 ][ 0 ];
			let result;
			validationCallback().then( ( r ) => {
				result = r;
			} );

			// Not resolved yet.
			await flushPromises();
			expect( result ).toBeUndefined();

			// Advance timers and flush microtasks in one step (Jest 29.5+).
			await jest.advanceTimersByTimeAsync( 5000 );

			expect( result ).toBe( true );
		} );

		it( 'does not error when wc.blocksCheckoutEvents is missing', () => {
			delete window.wc;

			expect( () => {
				jest.isolateModules( () => {
					require( '../../assets/js/blocks-checkout' );
				} );
			} ).not.toThrow();
		} );
	} );
} );
