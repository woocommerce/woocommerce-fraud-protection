/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for the example gateway's "Pay with provider" button script: a session
 * ID is taken when the shopper clicks, and added to the gateway's own request.
 */

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

let mockTakeSessionId;
let mockFetch;
let documentListeners;

beforeEach( () => {
	// The script listens for clicks on the document; remove what each test adds.
	documentListeners = [];
	const addEventListener = document.addEventListener.bind( document );
	jest.spyOn( document, 'addEventListener' ).mockImplementation(
		( ...args ) => {
			documentListeners.push( args );
			addEventListener( ...args );
		}
	);
	delete window.wcFraudProtection;
	delete window.fpExampleProviderCheckout;
	document.body.innerHTML =
		'<button type="button" class="fp-example-provider-button">Pay with provider</button>';

	mockTakeSessionId = jest.fn( () =>
		Promise.resolve( 'browser-session-id' )
	);
	mockFetch = jest.fn( () =>
		Promise.resolve( {
			json: () => Promise.resolve( { success: false, data: {} } ),
		} )
	);
	window.fetch = mockFetch;
	window.alert = jest.fn();
} );

afterEach( () => {
	documentListeners.forEach( ( args ) =>
		document.removeEventListener( ...args )
	);
	jest.restoreAllMocks();
	document.body.innerHTML = '';
	delete window.fetch;
	delete window.alert;
} );

function setupAndLoad( { fraudProtection = true } = {} ) {
	window.fpExampleProviderCheckout = {
		url: 'https://example.test/?wc-ajax=fp_example_create_provider_order',
		nonce: 'nonce-123',
	};
	if ( fraudProtection ) {
		window.wcFraudProtection = {
			config: { sessionIdField: SESSION_ID_FIELD },
			takeSessionId: mockTakeSessionId,
		};
	}

	jest.isolateModules( () => {
		require( '../../examples/fraud-protection-example-gateway/assets/provider-checkout' );
	} );
}

async function clickAndGetSentBody() {
	document.querySelector( '.fp-example-provider-button' ).click();
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	expect( mockFetch ).toHaveBeenCalledTimes( 1 );
	expect( mockFetch.mock.calls[ 0 ][ 0 ] ).toBe(
		'https://example.test/?wc-ajax=fp_example_create_provider_order'
	);
	return Object.fromEntries( mockFetch.mock.calls[ 0 ][ 1 ].body.entries() );
}

describe( 'example gateway provider checkout button', () => {
	it( 'takes no session ID before the shopper clicks', () => {
		setupAndLoad();

		expect( mockTakeSessionId ).not.toHaveBeenCalled();
	} );

	it( 'adds a session ID taken at the click to the request', async () => {
		setupAndLoad();

		const body = await clickAndGetSentBody();

		expect( mockTakeSessionId ).toHaveBeenCalledTimes( 1 );
		expect( body ).toEqual( {
			security: 'nonce-123',
			[ SESSION_ID_FIELD ]: 'browser-session-id',
		} );
	} );

	it( 'sends the request with an empty session ID when none is available', async () => {
		mockTakeSessionId.mockResolvedValue( '' );
		setupAndLoad();

		expect( await clickAndGetSentBody() ).toEqual( {
			security: 'nonce-123',
			[ SESSION_ID_FIELD ]: '',
		} );
	} );

	it( 'works without Fraud Protection on the page', async () => {
		setupAndLoad( { fraudProtection: false } );

		expect( await clickAndGetSentBody() ).toEqual( {
			security: 'nonce-123',
		} );
	} );
} );
