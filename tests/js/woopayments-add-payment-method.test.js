/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for the WooPayments add-payment-method fraud protection integration.
 *
 * window.fetch is replaced by a mock before the script wraps it, so the body
 * that the server would receive can be asserted without a transport.
 */

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';
const SESSION_ID_ERROR_FIELD = 'wc_fraud_protection_session_id_error';

let documentListeners;
let originalFetch;
let fetchMock;

beforeEach( () => {
	document.body.innerHTML = '<form id="add_payment_method"></form>';

	// Each test loads the script again, so its document listeners are removed afterwards.
	documentListeners = [];
	const addEventListener = document.addEventListener.bind( document );
	jest.spyOn( document, 'addEventListener' ).mockImplementation(
		( ...args ) => {
			documentListeners.push( args );
			addEventListener( ...args );
		}
	);

	originalFetch = window.fetch;
	fetchMock = jest.fn();
	window.fetch = fetchMock;

	window.wcFraudProtection = {
		config: {
			sessionIdField: SESSION_ID_FIELD,
			sessionIdErrorField: SESSION_ID_ERROR_FIELD,
		},
		reset: jest.fn(),
	};
} );

afterEach( () => {
	jest.restoreAllMocks();
	documentListeners.forEach( ( args ) =>
		document.removeEventListener( ...args )
	);
	document.body.innerHTML = '';
	window.fetch = originalFetch;
	delete window.wcFraudProtection;
} );

function loadScript() {
	jest.isolateModules( () => {
		require( '../../assets/js/woopayments-add-payment-method' );
	} );
}

function addFormField( name, value ) {
	const input = document.createElement( 'input' );
	input.type = 'hidden';
	input.name = name;
	input.value = value;
	document.getElementById( 'add_payment_method' ).appendChild( input );
}

function formHasField( name ) {
	return document
		.getElementById( 'add_payment_method' )
		.querySelectorAll( `input[name="${ name }"]` ).length;
}

/**
 * Build a request body as WooPayments does.
 *
 * @param {Object} fields Body fields.
 * @return {FormData} The body.
 */
function buildBody( fields ) {
	const body = new FormData();
	Object.entries( fields ).forEach( ( [ key, value ] ) =>
		body.append( key, value )
	);

	return body;
}

const setupIntentFields = {
	action: 'create_setup_intent',
	'wcpay-payment-method': 'pm_123',
	_ajax_nonce: 'nonce-value',
	'wcpay-fraud-prevention-token': '',
	'wcpay-fingerprint': 'fp',
};

/**
 * Send a request through the wrapped fetch and return the body it received.
 *
 * @param {Object} fields Body fields.
 * @return {FormData} The body that reached fetch.
 */
function sentBody( fields ) {
	fetchMock.mockReturnValue( new Promise( () => {} ) );

	window.fetch( '/wp-admin/admin-ajax.php', {
		method: 'POST',
		body: buildBody( fields ),
	} );

	return fetchMock.mock.calls[ 0 ][ 1 ].body;
}

/**
 * Send a setup intent request that the server answers with the given body,
 * or that fails when the body is an Error.
 *
 * @param {Object|Error} body          JSON response body, or the fetch failure.
 * @param {Object}       requestFields Fields that the request has besides the setup intent fields.
 * @return {Promise<Object|undefined>} What the caller reads from the response.
 */
async function sendSetupIntentRequest( body, requestFields = {} ) {
	if ( body instanceof Error ) {
		fetchMock.mockRejectedValue( body );
	} else {
		const response = {
			json: () => Promise.resolve( body ),
			clone: () => response,
		};
		fetchMock.mockResolvedValue( response );
	}

	let read;
	try {
		const response = await window.fetch( '/wp-admin/admin-ajax.php', {
			method: 'POST',
			body: buildBody( { ...setupIntentFields, ...requestFields } ),
		} );
		read = await response.json();
	} catch {
		// The caller handles the failure.
	}

	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

	return read;
}

function submitForm() {
	document
		.getElementById( 'add_payment_method' )
		.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
}

describe( 'woopayments-add-payment-method', () => {
	it( 'adds the form session ID to the setup intent request', () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();

		const body = sentBody( setupIntentFields );

		expect( body.get( SESSION_ID_FIELD ) ).toBe( 'sess-wcpay' );
		expect( body.get( 'wcpay-payment-method' ) ).toBe( 'pm_123' );
		expect( body.has( SESSION_ID_ERROR_FIELD ) ).toBe( false );
	} );

	it( 'adds the session ID error when the form has no session ID', () => {
		addFormField( SESSION_ID_ERROR_FIELD, 'timeout' );
		loadScript();

		const body = sentBody( setupIntentFields );

		expect( body.get( SESSION_ID_ERROR_FIELD ) ).toBe( 'timeout' );
		expect( body.has( SESSION_ID_FIELD ) ).toBe( false );
	} );

	it( 'does not add an empty session ID', () => {
		addFormField( SESSION_ID_FIELD, '' );
		loadScript();

		const body = sentBody( setupIntentFields );

		expect( body.has( SESSION_ID_FIELD ) ).toBe( false );
	} );

	it( 'keeps a session ID the request already has', () => {
		addFormField( SESSION_ID_FIELD, 'sess-form' );
		loadScript();

		const body = sentBody( {
			...setupIntentFields,
			[ SESSION_ID_FIELD ]: 'sess-request',
		} );

		expect( body.getAll( SESSION_ID_FIELD ) ).toEqual( [ 'sess-request' ] );
	} );

	it.each( [
		[
			'the form session ID',
			SESSION_ID_FIELD,
			'sess-wcpay',
			{ [ SESSION_ID_FIELD ]: 'sess-wcpay' },
			1,
		],
		[
			'the form session ID error',
			SESSION_ID_ERROR_FIELD,
			'timeout',
			{ [ SESSION_ID_ERROR_FIELD ]: 'timeout' },
			0,
		],
	] )(
		'ends the attempt when a request that already has %s fails',
		async ( _, name, value, requestFields, resets ) => {
			addFormField( name, value );
			loadScript();

			await sendSetupIntentRequest( { success: false }, requestFields );

			expect(
				fetchMock.mock.calls[ 0 ][ 1 ].body.getAll( name )
			).toEqual( [ value ] );
			expect( formHasField( name ) ).toBe( 0 );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes(
				resets
			);
		}
	);

	it( 'keeps the attempt when a request with another session ID fails', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-form' );
		loadScript();

		await sendSetupIntentRequest(
			{ success: false },
			{ [ SESSION_ID_FIELD ]: 'sess-request' }
		);

		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'leaves other requests unchanged', () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();

		const body = sentBody( { action: 'update_order_status' } );

		expect( body.has( SESSION_ID_FIELD ) ).toBe( false );
	} );

	it( 'passes requests without a form body to fetch unchanged', () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();
		fetchMock.mockReturnValue( new Promise( () => {} ) );

		window.fetch( '/wp-json/wc/store/v1/cart' );
		window.fetch( '/wp-admin/admin-ajax.php', {
			method: 'POST',
			body: 'action=create_setup_intent',
		} );

		expect( fetchMock.mock.calls[ 0 ] ).toEqual( [
			'/wp-json/wc/store/v1/cart',
		] );
		expect( fetchMock.mock.calls[ 1 ][ 1 ].body ).toBe(
			'action=create_setup_intent'
		);
	} );

	it( 'returns the response of fetch to the caller', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();

		const read = await sendSetupIntentRequest( {
			success: true,
			data: { id: 'seti_123' },
		} );

		expect( read ).toEqual( { success: true, data: { id: 'seti_123' } } );
	} );

	it.each( [
		[ 'is blocked', { success: false, data: { error: {} } } ],
		[ 'fails', new TypeError( 'Failed to fetch' ) ],
	] )(
		'removes the attempt fields and resets the session when the setup intent request %s',
		async ( _, body ) => {
			addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
			loadScript();

			await sendSetupIntentRequest( body );

			expect( formHasField( SESSION_ID_FIELD ) ).toBe( 0 );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'removes the session ID error without a reset when the request fails without a session ID', async () => {
		addFormField( SESSION_ID_ERROR_FIELD, 'timeout' );
		loadScript();

		await sendSetupIntentRequest( { success: false } );

		expect( formHasField( SESSION_ID_ERROR_FIELD ) ).toBe( 0 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'keeps the session ID after a successful setup intent request', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();

		await sendSetupIntentRequest( { success: true, data: {} } );

		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'ends the attempt on the next submit after a setup that did not complete', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();
		await sendSetupIntentRequest( { success: true, data: {} } );

		// add-payment-method.js listens on the form in the capture phase.
		let fieldSeenByForm;
		document.getElementById( 'add_payment_method' ).addEventListener(
			'submit',
			() => {
				fieldSeenByForm = formHasField( SESSION_ID_FIELD );
			},
			true
		);
		submitForm();

		expect( fieldSeenByForm ).toBe( 0 );
		expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps the session ID of a newer attempt when an earlier request fails after it', async () => {
		const respond = [];
		fetchMock.mockImplementation(
			() =>
				new Promise( ( resolve ) => {
					respond.push( ( body ) => {
						const response = {
							json: () => Promise.resolve( body ),
							clone: () => response,
						};
						resolve( response );
					} );
				} )
		);
		const send = () =>
			window.fetch( '/wp-admin/admin-ajax.php', {
				method: 'POST',
				body: buildBody( setupIntentFields ),
			} );
		const settle = () =>
			new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		addFormField( SESSION_ID_FIELD, 'sess-first' );
		loadScript();
		send();

		// A second submit while the first request runs starts a new attempt.
		submitForm();
		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 0 );
		addFormField( SESSION_ID_FIELD, 'sess-second' );
		send();

		respond[ 0 ]( { success: false, data: { error: {} } } );
		await settle();
		respond[ 1 ]( { success: true, data: {} } );
		await settle();

		expect(
			fetchMock.mock.calls[ 1 ][ 1 ].body.get( SESSION_ID_FIELD )
		).toBe( 'sess-second' );
		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect(
			document.querySelector( `input[name="${ SESSION_ID_FIELD }"]` )
				.value
		).toBe( 'sess-second' );
		expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'keeps the session ID for the form post of a completed setup', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();
		await sendSetupIntentRequest( { success: true, data: {} } );

		addFormField( 'wcpay-setup-intent', 'seti_123' );
		submitForm();

		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'keeps the session ID on a submit before it is sent', () => {
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();

		submitForm();

		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'does nothing when wcFraudProtection is missing', () => {
		delete window.wcFraudProtection;
		addFormField( SESSION_ID_FIELD, 'sess-wcpay' );
		loadScript();

		expect( window.fetch ).toBe( fetchMock );
	} );
} );
