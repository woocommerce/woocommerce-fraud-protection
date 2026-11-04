/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for express-checkout.js — session ID in WooPayments and Stripe
 * express checkout extension data.
 *
 * express-checkout.js is an IIFE. We test it by loading blackbox-init.js
 * with a minimal SDK for the real wcFraudProtection.addSessionIdToExtensions(),
 * replacing the prepared-session methods with mocks, requiring the file
 * (which executes the IIFE), and applying the filters the way the gateways do.
 */

const WOOPAYMENTS_FILTER =
	'wcpay.express-checkout.cart-place-order-extension-data';
const STRIPE_FILTER =
	'wcstripe.express-checkout.cart-place-order-extension-data';
const NAMESPACE = 'woocommerce/fraud-protection';

let filters;
let mockPrepareSessionId;
let mockTakePreparedSessionId;

beforeEach( () => {
	delete window.Blackbox;
	delete window.wcFraudProtection;
	delete window.wcFraudProtectionExpressCheckout;
	delete window.wp;

	filters = {};
	mockPrepareSessionId = jest.fn();
	mockTakePreparedSessionId = jest.fn( () => 'prepared-session-id' );
} );

function setupAndLoad( { sdk = true } = {} ) {
	window.wp = {
		hooks: {
			addFilter: jest.fn( ( name, namespace, callback ) => {
				filters[ name ] = filters[ name ] || [];
				filters[ name ].push( callback );
			} ),
		},
	};
	window.wcFraudProtection = {
		config: { sessionIdField: 'wc_fraud_protection_session_id' },
	};
	if ( sdk ) {
		window.Blackbox = { configure: jest.fn(), init: jest.fn() };
	}

	jest.isolateModules( () => {
		require( '../../assets/js/blackbox-init' );
	} );
	if ( sdk ) {
		window.wcFraudProtection.prepareSessionId = mockPrepareSessionId;
		window.wcFraudProtection.takePreparedSessionId =
			mockTakePreparedSessionId;
	}

	jest.isolateModules( () => {
		require( '../../assets/js/express-checkout' );
	} );
}

function applyFilter( name, value ) {
	return ( filters[ name ] || [] ).reduce(
		( current, callback ) => callback( current ),
		value
	);
}

describe( 'express-checkout', () => {
	it( 'starts preparing a session ID on load', () => {
		setupAndLoad();

		expect( mockPrepareSessionId ).toHaveBeenCalledTimes( 1 );
	} );

	it.each( [
		[ 'WooPayments', WOOPAYMENTS_FILTER ],
		[ 'Stripe', STRIPE_FILTER ],
	] )(
		'adds the prepared session ID to the %s extension data',
		( _, filterName ) => {
			setupAndLoad();

			const result = applyFilter( filterName, {
				'woocommerce/order-attribution': { source: 'direct' },
			} );

			expect( result ).toEqual( {
				'woocommerce/order-attribution': { source: 'direct' },
				[ NAMESPACE ]: { blackbox_session_id: 'prepared-session-id' },
			} );
			expect( mockTakePreparedSessionId ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'handles only the gateways it is configured for', () => {
		window.wcFraudProtectionExpressCheckout = {
			gateways: [ 'woocommerce_payments', 'unknown_gateway' ],
		};
		setupAndLoad();
		const nativeData = {
			[ NAMESPACE ]: { blackbox_session_id: 'gateway-session-id' },
		};

		expect( window.wp.hooks.addFilter ).toHaveBeenCalledTimes( 1 );
		expect( applyFilter( STRIPE_FILTER, nativeData ) ).toBe( nativeData );
		expect( applyFilter( WOOPAYMENTS_FILTER, {} ) ).toEqual( {
			[ NAMESPACE ]: { blackbox_session_id: 'prepared-session-id' },
		} );
	} );

	it( 'handles both gateways when the gateway list is missing or invalid', () => {
		window.wcFraudProtectionExpressCheckout = { gateways: 'stripe' };
		setupAndLoad();

		expect( Object.keys( filters ).sort() ).toEqual(
			[ STRIPE_FILTER, WOOPAYMENTS_FILTER ].sort()
		);
	} );

	it( 'does not change the extension data object it receives', () => {
		setupAndLoad();
		const original = { other: { value: 1 } };

		applyFilter( STRIPE_FILTER, original );

		expect( original ).toEqual( { other: { value: 1 } } );
	} );

	it( 'replaces a session ID left in the data by an earlier checkout attempt', () => {
		setupAndLoad();

		const result = applyFilter( STRIPE_FILTER, {
			[ NAMESPACE ]: { blackbox_session_id: 'used-session-id' },
		} );

		expect( result ).toEqual( {
			[ NAMESPACE ]: { blackbox_session_id: 'prepared-session-id' },
		} );
	} );

	it( 'replaces an earlier session ID with not_ready when no prepared session ID is ready', () => {
		mockTakePreparedSessionId.mockReturnValue( '' );
		setupAndLoad();

		const result = applyFilter( STRIPE_FILTER, {
			other: { value: 1 },
			[ NAMESPACE ]: { blackbox_session_id: 'used-session-id' },
		} );

		expect( result ).toEqual( {
			other: { value: 1 },
			[ NAMESPACE ]: { blackbox_session_id_error: 'not_ready' },
		} );
	} );

	it.each( [
		[ 'WooPayments', WOOPAYMENTS_FILTER ],
		[ 'Stripe', STRIPE_FILTER ],
	] )(
		'reports not_ready in the %s extension data when no session ID is ready',
		( _, filterName ) => {
			mockTakePreparedSessionId.mockReturnValue( '' );
			setupAndLoad();

			expect( applyFilter( filterName, {} ) ).toEqual( {
				[ NAMESPACE ]: { blackbox_session_id_error: 'not_ready' },
			} );
		}
	);

	it( 'reports sdk_unavailable when the SDK did not load', () => {
		setupAndLoad( { sdk: false } );

		expect(
			applyFilter( STRIPE_FILTER, {
				[ NAMESPACE ]: { blackbox_session_id: 'used-session-id' },
			} )
		).toEqual( {
			[ NAMESPACE ]: { blackbox_session_id_error: 'sdk_unavailable' },
		} );
	} );

	it.each( [
		[ 'undefined', undefined ],
		[ 'null', null ],
		[ 'an array', [ 'value' ] ],
		[ 'a string', 'value' ],
	] )( 'returns %s extension data unchanged', ( _, value ) => {
		setupAndLoad();

		expect( applyFilter( WOOPAYMENTS_FILTER, value ) ).toBe( value );
		expect( mockTakePreparedSessionId ).not.toHaveBeenCalled();
	} );

	it( 'replaces an earlier session ID with not_ready when taking the session ID throws', () => {
		mockTakePreparedSessionId.mockImplementation( () => {
			throw new Error( 'broken' );
		} );
		setupAndLoad();

		const result = applyFilter( WOOPAYMENTS_FILTER, {
			other: { value: 1 },
			[ NAMESPACE ]: { blackbox_session_id: 'used-session-id' },
		} );

		expect( result ).toEqual( {
			other: { value: 1 },
			[ NAMESPACE ]: { blackbox_session_id_error: 'not_ready' },
		} );
	} );

	it( 'does not register filters when wp.hooks is missing', () => {
		window.wcFraudProtection = {
			prepareSessionId: mockPrepareSessionId,
			takePreparedSessionId: mockTakePreparedSessionId,
		};

		expect( () => {
			jest.isolateModules( () => {
				require( '../../assets/js/express-checkout' );
			} );
		} ).not.toThrow();
		expect( mockPrepareSessionId ).not.toHaveBeenCalled();
	} );

	it( 'does not register filters when the Fraud Protection API is missing', () => {
		window.wp = { hooks: { addFilter: jest.fn() } };

		jest.isolateModules( () => {
			require( '../../assets/js/express-checkout' );
		} );

		expect( window.wp.hooks.addFilter ).not.toHaveBeenCalled();
	} );
} );
