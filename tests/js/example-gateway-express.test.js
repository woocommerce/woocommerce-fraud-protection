/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for the example gateway's "Express pay" button script: the order is
 * placed with a Store API checkout request, and the prepared session ID is
 * added synchronously to the extension data the gateway builds.
 */

const NAMESPACE = 'woocommerce/fraud-protection';
const ADDRESS = {
	first_name: 'Sam',
	last_name: 'Example',
	address_1: '60 29th Street',
	city: 'San Francisco',
	state: 'CA',
	postcode: '94110',
	country: 'US',
	email: 'sam@example.com',
};

let filters;
let mockPrepareSessionId;
let mockAddSessionIdToExtensions;
let mockFetch;

beforeEach( () => {
	delete window.wcFraudProtection;
	delete window.wp;
	delete window.fpExampleExpress;
	document.body.innerHTML =
		'<button type="button" class="fp-example-express-button">Express pay</button>';

	filters = {};
	mockPrepareSessionId = jest.fn();
	mockAddSessionIdToExtensions = jest.fn( ( extensions ) =>
		Object.assign( {}, extensions, {
			[ NAMESPACE ]: { blackbox_session_id: 'prepared-session-id' },
		} )
	);
	mockFetch = jest.fn( () =>
		Promise.resolve( {
			json: () => Promise.resolve( { message: 'Error' } ),
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
		checkoutUrl: 'https://example.test/wp-json/wc/store/v1/checkout',
		nonce: 'store-api-nonce',
		address: ADDRESS,
	};
	if ( fraudProtection ) {
		window.wcFraudProtection = {
			config: { sessionIdField: 'wc_fraud_protection_session_id' },
			prepareSessionId: mockPrepareSessionId,
			takePreparedSessionId: jest.fn(),
			addSessionIdToExtensions: mockAddSessionIdToExtensions,
		};
	}

	jest.isolateModules( () => {
		require( '../../examples/fraud-protection-example-gateway/assets/express' );
	} );
}

function clickAndGetRequest() {
	document.querySelector( '.fp-example-express-button' ).click();

	expect( mockFetch ).toHaveBeenCalledTimes( 1 );
	const [ url, options ] = mockFetch.mock.calls[ 0 ];
	return { url, options, body: JSON.parse( options.body ) };
}

describe( 'example gateway express button', () => {
	it( 'prepares a session ID once when the button is set up', () => {
		setupAndLoad();

		expect( mockPrepareSessionId ).toHaveBeenCalledTimes( 1 );
		expect( mockAddSessionIdToExtensions ).not.toHaveBeenCalled();
	} );

	it( 'places the order with a Store API checkout request', () => {
		setupAndLoad();

		const { url, options, body } = clickAndGetRequest();

		expect( url ).toBe(
			'https://example.test/wp-json/wc/store/v1/checkout'
		);
		expect( options.method ).toBe( 'POST' );
		expect( options.headers ).toEqual( {
			'Content-Type': 'application/json',
			Nonce: 'store-api-nonce',
		} );
		expect( body.payment_method ).toBe( 'fp_example' );
		expect( body.payment_data ).toEqual( [
			{ key: 'fp_example_wallet', value: 'apple_pay' },
		] );
		expect( body.billing_address ).toEqual( ADDRESS );
		expect( body.shipping_address.email ).toBeUndefined();
	} );

	it( 'adds the prepared session ID to the extension data of every request', () => {
		setupAndLoad();

		const { body } = clickAndGetRequest();

		expect( mockAddSessionIdToExtensions ).toHaveBeenCalledTimes( 1 );
		expect( mockAddSessionIdToExtensions ).toHaveBeenCalledWith( {} );
		expect( body.extensions ).toEqual( {
			[ NAMESPACE ]: { blackbox_session_id: 'prepared-session-id' },
		} );
	} );

	it( 'works without Fraud Protection on the page', () => {
		setupAndLoad( { fraudProtection: false } );

		const { body } = clickAndGetRequest();

		expect( body.extensions ).toEqual( {} );
	} );

	it( 'shows the error message of a refused checkout', async () => {
		mockFetch.mockResolvedValue( {
			json: () =>
				Promise.resolve( {
					code: 'blocked',
					message: 'We are unable to process this request online.',
				} ),
		} );
		setupAndLoad();

		clickAndGetRequest();
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( window.alert ).toHaveBeenCalledWith(
			'We are unable to process this request online.'
		);
		expect(
			document.querySelector( '.fp-example-express-button' ).disabled
		).toBe( false );
	} );
} );
