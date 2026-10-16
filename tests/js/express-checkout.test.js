/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for express-checkout.js — session ID in WooPayments and Stripe
 * express checkout extension data.
 *
 * express-checkout.js is an IIFE. We test it by setting up global mocks,
 * requiring the file (which executes the IIFE), and applying the filters
 * the way the gateways do.
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
	delete window.wcFraudProtection;
	delete window.wp;

	filters = {};
	mockPrepareSessionId = jest.fn();
	mockTakePreparedSessionId = jest.fn( () => 'prepared-session-id' );
} );

function setupAndLoad() {
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
		acquireSessionId: jest.fn(),
		reset: jest.fn(),
		prepareSessionId: mockPrepareSessionId,
		takePreparedSessionId: mockTakePreparedSessionId,
	};

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

	it( 'removes an earlier session ID when no prepared session ID is ready', () => {
		mockTakePreparedSessionId.mockReturnValue( '' );
		setupAndLoad();

		const result = applyFilter( STRIPE_FILTER, {
			other: { value: 1 },
			[ NAMESPACE ]: { blackbox_session_id: 'used-session-id' },
		} );

		expect( result ).toEqual( { other: { value: 1 } } );
	} );

	it( 'leaves the data without a session ID when none is ready', () => {
		mockTakePreparedSessionId.mockReturnValue( '' );
		setupAndLoad();

		expect( applyFilter( WOOPAYMENTS_FILTER, {} ) ).toEqual( {} );
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

	it( 'returns the original data when taking the session ID throws', () => {
		mockTakePreparedSessionId.mockImplementation( () => {
			throw new Error( 'broken' );
		} );
		setupAndLoad();
		const original = { other: { value: 1 } };

		expect( applyFilter( WOOPAYMENTS_FILTER, original ) ).toBe( original );
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
