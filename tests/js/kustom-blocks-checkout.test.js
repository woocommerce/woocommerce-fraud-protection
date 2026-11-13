/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for kustom-blocks-checkout.js — session ID in the Checkout block's
 * extension data while Kustom is the selected payment method, and a new
 * session once a Kustom attempt sent it.
 *
 * The script is an IIFE. We test it by setting up global mocks and requiring
 * the file, with a small stand-in for the WooCommerce data stores.
 */

const NAMESPACE = 'woocommerce/fraud-protection';

let activeMethod;
let extensionData;
let cartData;
let serverCart;
let storeListeners;
let setExtensionData;
let sessionIdListener;
let windowListeners;

function setup( fraudProtection ) {
	window.wcFraudProtection = fraudProtection;
	jest.isolateModules( () => {
		require( '../../assets/js/kustom-blocks-checkout' );
	} );
}

function setupWithPreparedSessionIds() {
	setup( {
		prepareSessionId: jest.fn(),
		reset: jest.fn( () => sessionIdListener( '' ) ),
		onPreparedSessionIdChange: ( listener ) => {
			sessionIdListener = listener;
			listener( '' );
		},
	} );
}

function notify( store ) {
	( storeListeners[ store ] || [] ).forEach( ( listener ) => listener() );
}

function setSentSessionId( sessionId ) {
	cartData = {
		extensions: { [ NAMESPACE ]: { kustom_sent_session_id: sessionId } },
	};
	notify( 'wc/store/cart' );
}

/**
 * Move the focus into an iframe and back to the page.
 */
function payInIframeAndComeBack() {
	document.hasFocus.mockReturnValue( true );
	window.dispatchEvent( new Event( 'blur' ) );
	jest.runAllTimers();
	window.dispatchEvent( new Event( 'focus' ) );
}

async function flushPromises() {
	for ( let i = 0; i < 5; i++ ) {
		await Promise.resolve();
	}
}

beforeEach( () => {
	jest.useFakeTimers();
	// Leaving the tab or the window takes the focus from the document.
	jest.spyOn( document, 'hasFocus' ).mockReturnValue( false );
	activeMethod = 'kco';
	extensionData = {};
	cartData = { extensions: {} };
	serverCart = { extensions: {} };
	storeListeners = {};
	sessionIdListener = null;
	windowListeners = [];
	setExtensionData = jest.fn( ( namespace, data ) => {
		extensionData = { ...extensionData, [ namespace ]: data };
	} );

	const addEventListener = window.addEventListener.bind( window );
	jest.spyOn( window, 'addEventListener' ).mockImplementation(
		( type, listener, options ) => {
			windowListeners.push( [ type, listener, options ] );
			addEventListener( type, listener, options );
		}
	);

	window.wp = {
		data: {
			select: ( store ) => {
				if ( store === 'wc/store/payment' ) {
					return { getActivePaymentMethod: () => activeMethod };
				}
				if ( store === 'wc/store/cart' ) {
					return { getCartData: () => cartData };
				}
				return { getExtensionData: () => extensionData };
			},
			dispatch: jest.fn( () => ( { setExtensionData } ) ),
			subscribe: ( listener, store ) => {
				storeListeners[ store ] = [
					...( storeListeners[ store ] || [] ),
					listener,
				];
			},
		},
		apiFetch: jest.fn( () => Promise.resolve( serverCart ) ),
	};
} );

afterEach( () => {
	windowListeners.forEach( ( [ type, listener, options ] ) =>
		window.removeEventListener( type, listener, options )
	);
	jest.restoreAllMocks();
	jest.useRealTimers();
	delete window.wp;
	delete window.wcFraudProtection;
} );

describe( 'kustom-blocks-checkout', () => {
	it( 'prepares a session ID when loaded', () => {
		setupWithPreparedSessionIds();

		expect(
			window.wcFraudProtection.prepareSessionId
		).toHaveBeenCalledTimes( 1 );
	} );

	it( 'reports not_ready until the session ID is ready', () => {
		setupWithPreparedSessionIds();

		expect( extensionData[ NAMESPACE ] ).toEqual( {
			blackbox_session_id: '',
			blackbox_session_id_error: 'not_ready',
		} );
	} );

	it( 'adds the session ID when it is ready', () => {
		setupWithPreparedSessionIds();

		sessionIdListener( 'sess-1' );

		expect( extensionData[ NAMESPACE ] ).toEqual( {
			blackbox_session_id: 'sess-1',
		} );
	} );

	it( 'withdraws a session ID that is no longer prepared', () => {
		setupWithPreparedSessionIds();
		sessionIdListener( 'sess-1' );

		sessionIdListener( '' );

		expect( extensionData[ NAMESPACE ] ).toEqual( {
			blackbox_session_id: '',
			blackbox_session_id_error: 'not_ready',
		} );
	} );

	it( 'leaves the data alone while another payment method is selected', () => {
		activeMethod = 'stripe';
		setupWithPreparedSessionIds();

		sessionIdListener( 'sess-1' );

		expect( setExtensionData ).not.toHaveBeenCalled();
	} );

	it( 'adds the session ID when the shopper selects Kustom', () => {
		activeMethod = 'stripe';
		setupWithPreparedSessionIds();
		sessionIdListener( 'sess-1' );

		activeMethod = 'kco';
		notify( 'wc/store/payment' );

		expect( extensionData[ NAMESPACE ] ).toEqual( {
			blackbox_session_id: 'sess-1',
		} );
	} );

	it( 'does not write unchanged data again', () => {
		setupWithPreparedSessionIds();
		sessionIdListener( 'sess-1' );
		setExtensionData.mockClear();

		notify( 'wc/store/payment' );
		sessionIdListener( 'sess-1' );

		expect( setExtensionData ).not.toHaveBeenCalled();
	} );

	describe( 'session ID that a Kustom attempt sent', () => {
		it( 'starts a new session when the cart shows the prepared session ID', () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			setSentSessionId( 'sess-1' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
			expect( extensionData[ NAMESPACE ] ).toEqual( {
				blackbox_session_id: '',
				blackbox_session_id_error: 'not_ready',
			} );
		} );

		it( 'starts a new session while another payment method is selected', () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );
			activeMethod = 'cod';

			setSentSessionId( 'sess-1' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'starts a new session when the prepared session ID was already sent', () => {
			cartData = {
				extensions: {
					[ NAMESPACE ]: { kustom_sent_session_id: 'sess-1' },
				},
			};
			setupWithPreparedSessionIds();

			sessionIdListener( 'sess-1' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'keeps a session ID that was not sent', () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-2' );

			setSentSessionId( 'sess-1' );
			setSentSessionId( '' );

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
			expect( extensionData[ NAMESPACE ] ).toEqual( {
				blackbox_session_id: 'sess-2',
			} );
		} );

		it( 'starts a new session only once for the same session ID', () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );
			setSentSessionId( 'sess-1' );

			sessionIdListener( 'sess-1' );
			notify( 'wc/store/cart' );

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'gets the cart when the shopper comes back from the iframe', async () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			payInIframeAndComeBack();
			await flushPromises();

			expect( window.wp.apiFetch ).toHaveBeenCalledWith( {
				path: '/wc/store/v1/cart',
				method: 'GET',
				cache: 'no-store',
			} );
		} );

		it( 'starts a new session when the fetched cart shows the prepared session ID', async () => {
			serverCart = {
				extensions: {
					[ NAMESPACE ]: { kustom_sent_session_id: 'sess-1' },
				},
			};
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			payInIframeAndComeBack();
			await flushPromises();

			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
			expect( window.wp.data.dispatch ).not.toHaveBeenCalledWith(
				'wc/store/cart'
			);
		} );

		it( 'keeps the session when the fetched cart shows another session ID', async () => {
			serverCart = {
				extensions: {
					[ NAMESPACE ]: { kustom_sent_session_id: 'sess-0' },
				},
			};
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			payInIframeAndComeBack();
			await flushPromises();

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'gets the cart when the shopper leaves the window from the iframe and comes back', async () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			document.hasFocus.mockReturnValue( true );
			window.dispatchEvent( new Event( 'blur' ) );
			jest.runAllTimers();
			document.hasFocus.mockReturnValue( false );
			window.dispatchEvent( new Event( 'blur' ) );
			jest.runAllTimers();
			window.dispatchEvent( new Event( 'focus' ) );
			await flushPromises();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'gets the cart only once for each time the shopper comes back', async () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			payInIframeAndComeBack();
			await flushPromises();
			// The shopper then goes to another tab and comes back.
			document.hasFocus.mockReturnValue( false );
			window.dispatchEvent( new Event( 'blur' ) );
			jest.runAllTimers();
			window.dispatchEvent( new Event( 'focus' ) );
			await flushPromises();

			expect( window.wp.apiFetch ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'does not get the cart when the shopper comes back from another tab or window', async () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			window.dispatchEvent( new Event( 'blur' ) );
			jest.runAllTimers();
			window.dispatchEvent( new Event( 'focus' ) );
			await flushPromises();

			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		} );

		it( 'does not get the cart while another payment method is selected', async () => {
			activeMethod = 'cod';
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			payInIframeAndComeBack();
			await flushPromises();

			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		} );

		it( 'does not get the cart without a prepared session ID', async () => {
			setupWithPreparedSessionIds();

			payInIframeAndComeBack();
			await flushPromises();

			expect( window.wp.apiFetch ).not.toHaveBeenCalled();
		} );

		it( 'fails open when getting the cart fails', async () => {
			window.wp.apiFetch.mockReturnValue(
				Promise.reject( new Error( 'offline' ) )
			);
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			expect( () => payInIframeAndComeBack() ).not.toThrow();
			await flushPromises();

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'fails open without apiFetch', async () => {
			delete window.wp.apiFetch;
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );

			expect( () => payInIframeAndComeBack() ).not.toThrow();
			await flushPromises();

			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );

		it( 'fails open without the cart store', () => {
			setupWithPreparedSessionIds();
			sessionIdListener( 'sess-1' );
			cartData = undefined;

			expect( () => notify( 'wc/store/cart' ) ).not.toThrow();
			expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
		} );
	} );

	it( 'reports sdk_unavailable without the SDK', () => {
		setup( {} );

		expect( extensionData[ NAMESPACE ] ).toEqual( {
			blackbox_session_id: '',
			blackbox_session_id_error: 'sdk_unavailable',
		} );
	} );

	it( 'fails open when the stores are unavailable', () => {
		window.wp.data.select = () => undefined;

		expect( () => setupWithPreparedSessionIds() ).not.toThrow();
		expect( () => sessionIdListener( 'sess-1' ) ).not.toThrow();
	} );

	it( 'does nothing without the data module', () => {
		delete window.wp;

		expect( () => setupWithPreparedSessionIds() ).not.toThrow();
		expect( sessionIdListener ).toBeNull();
	} );
} );
