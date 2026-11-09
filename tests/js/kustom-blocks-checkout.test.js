/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for kustom-blocks-checkout.js — session ID in the Checkout block's
 * extension data while Kustom is the selected payment method.
 *
 * The script is an IIFE. We test it by setting up global mocks and requiring
 * the file, with a small stand-in for the two WooCommerce data stores.
 */

const NAMESPACE = 'woocommerce/fraud-protection';

let activeMethod;
let extensionData;
let storeListener;
let setExtensionData;
let sessionIdListener;

function setup( fraudProtection ) {
	window.wcFraudProtection = fraudProtection;
	jest.isolateModules( () => {
		require( '../../assets/js/kustom-blocks-checkout' );
	} );
}

function setupWithPreparedSessionIds() {
	setup( {
		prepareSessionId: jest.fn(),
		onPreparedSessionIdChange: ( listener ) => {
			sessionIdListener = listener;
			listener( '' );
		},
	} );
}

beforeEach( () => {
	activeMethod = 'kco';
	extensionData = {};
	storeListener = null;
	sessionIdListener = null;
	setExtensionData = jest.fn( ( namespace, data ) => {
		extensionData = { ...extensionData, [ namespace ]: data };
	} );

	window.wp = {
		data: {
			select: ( store ) =>
				store === 'wc/store/payment'
					? { getActivePaymentMethod: () => activeMethod }
					: { getExtensionData: () => extensionData },
			dispatch: () => ( { setExtensionData } ),
			subscribe: ( listener ) => {
				storeListener = listener;
			},
		},
	};
} );

afterEach( () => {
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
		storeListener();

		expect( extensionData[ NAMESPACE ] ).toEqual( {
			blackbox_session_id: 'sess-1',
		} );
	} );

	it( 'does not write unchanged data again', () => {
		setupWithPreparedSessionIds();
		sessionIdListener( 'sess-1' );
		setExtensionData.mockClear();

		storeListener();
		sessionIdListener( 'sess-1' );

		expect( setExtensionData ).not.toHaveBeenCalled();
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
