/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for the example gateway's express button script: the prepared session
 * ID is added synchronously to the request data the gateway builds.
 */

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

let filters;
let mockPrepareSessionId;
let mockTakePreparedSessionId;
let mockFetch;

beforeEach( () => {
	delete window.wcFraudProtection;
	delete window.wp;
	delete window.fpExampleExpress;
	document.body.innerHTML =
		'<button type="button" class="fp-example-express-button">Express pay</button>';

	filters = {};
	mockPrepareSessionId = jest.fn();
	mockTakePreparedSessionId = jest.fn( () => 'prepared-session-id' );
	mockFetch = jest.fn( () =>
		Promise.resolve( {
			json: () => Promise.resolve( { success: false, data: {} } ),
		} )
	);
	window.fetch = mockFetch;
	window.alert = jest.fn();
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.fetch;
	delete window.alert;
} );

function setupAndLoad( { fraudProtection = true } = {} ) {
	window.wp = {
		hooks: {
			addFilter: jest.fn( ( name, namespace, callback ) => {
				filters[ name ] = filters[ name ] || [];
				filters[ name ].push( callback );
			} ),
			applyFilters: jest.fn( ( name, value ) =>
				( filters[ name ] || [] ).reduce(
					( current, callback ) => callback( current ),
					value
				)
			),
		},
	};
	window.fpExampleExpress = {
		url: 'https://example.test/?wc-ajax=fp_example_express_pay',
		nonce: 'nonce-123',
	};
	if ( fraudProtection ) {
		window.wcFraudProtection = {
			config: { sessionIdField: SESSION_ID_FIELD },
			acquireSessionId: jest.fn(),
			reset: jest.fn(),
			prepareSessionId: mockPrepareSessionId,
			takePreparedSessionId: mockTakePreparedSessionId,
		};
	}

	jest.isolateModules( () => {
		require( '../../examples/fraud-protection-example-gateway/assets/express' );
	} );
}

function sentBody() {
	expect( mockFetch ).toHaveBeenCalledTimes( 1 );
	return Object.fromEntries( mockFetch.mock.calls[ 0 ][ 1 ].body.entries() );
}

describe( 'example gateway express button', () => {
	it( 'prepares a session ID once when the button is set up', () => {
		setupAndLoad();

		expect( mockPrepareSessionId ).toHaveBeenCalledTimes( 1 );
		expect( mockTakePreparedSessionId ).not.toHaveBeenCalled();
	} );

	it( 'adds the prepared session ID to the request when the button is clicked', () => {
		setupAndLoad();

		document.querySelector( '.fp-example-express-button' ).click();

		expect( mockTakePreparedSessionId ).toHaveBeenCalledTimes( 1 );
		expect( sentBody() ).toEqual( {
			security: 'nonce-123',
			fp_example_wallet: 'apple_pay',
			[ SESSION_ID_FIELD ]: 'prepared-session-id',
		} );
	} );

	it( 'sends the request without a session ID when none is ready', () => {
		mockTakePreparedSessionId.mockReturnValue( '' );
		setupAndLoad();

		document.querySelector( '.fp-example-express-button' ).click();

		expect( sentBody() ).toEqual( {
			security: 'nonce-123',
			fp_example_wallet: 'apple_pay',
		} );
	} );

	it( 'works without Fraud Protection on the page', () => {
		setupAndLoad( { fraudProtection: false } );

		document.querySelector( '.fp-example-express-button' ).click();

		expect( sentBody() ).toEqual( {
			security: 'nonce-123',
			fp_example_wallet: 'apple_pay',
		} );
	} );
} );
