/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for the Stripe add-payment-method fraud protection integration.
 *
 * Uses real jQuery with jsdom. Requests are aborted from beforeSend, which
 * runs after the prefilters, so the data the server would receive can be
 * asserted without a transport.
 */

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';
const SESSION_ID_ERROR_FIELD = 'wc_fraud_protection_session_id_error';

let $;
let documentListeners;

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

	jest.isolateModules( () => {
		$ = require( 'jquery' );
	} );
	window.jQuery = $;

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
	delete window.jQuery;
	delete window.wcFraudProtection;
} );

function loadScript() {
	jest.isolateModules( () => {
		require( '../../assets/js/stripe-add-payment-method' );
	} );
}

function addFormField( name, value ) {
	$( '<input>', { type: 'hidden', name, value } ).appendTo(
		'#add_payment_method'
	);
}

function sentData( data ) {
	let sent;
	$.ajax( {
		url: '/wp-admin/admin-ajax.php',
		type: 'POST',
		data,
		beforeSend( jqXHR, settings ) {
			sent = settings.data;
			return false;
		},
	} );

	return new URLSearchParams( sent );
}

const setupIntentData = {
	action: 'wc_stripe_create_and_confirm_setup_intent',
	'wc-stripe-payment-method': 'pm_123',
	'wc-stripe-payment-type': 'card',
	_ajax_nonce: 'nonce-value',
};

/**
 * Send a setup intent request that the server answers with the given status and body.
 *
 * @param {number} status HTTP status.
 * @param {Object} body   JSON response body.
 * @return {Promise<void>} Resolves after the response callbacks have run.
 */
function sendSetupIntentRequest( status, body ) {
	$.ajaxTransport( '+*', () => ( {
		send( headers, complete ) {
			complete( status, 'response', { text: JSON.stringify( body ) } );
		},
		abort() {},
	} ) );

	$.ajax( {
		url: '/wp-admin/admin-ajax.php',
		type: 'POST',
		dataType: 'json',
		data: setupIntentData,
	} ).catch( () => {} );

	// jQuery runs then() callbacks in a new task.
	return new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
}

function submitForm() {
	document
		.getElementById( 'add_payment_method' )
		.dispatchEvent(
			new Event( 'submit', { bubbles: true, cancelable: true } )
		);
}

function formHasField( name ) {
	return $( '#add_payment_method' ).find( `input[name="${ name }"]` ).length;
}

describe( 'stripe-add-payment-method', () => {
	it( 'adds the form session ID to the setup intent request', () => {
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();

		const params = sentData( setupIntentData );

		expect( params.get( SESSION_ID_FIELD ) ).toBe( 'sess-stripe' );
		expect( params.get( 'wc-stripe-payment-method' ) ).toBe( 'pm_123' );
		expect( params.has( SESSION_ID_ERROR_FIELD ) ).toBe( false );
	} );

	it( 'adds the session ID error when the form has no session ID', () => {
		addFormField( SESSION_ID_ERROR_FIELD, 'timeout' );
		loadScript();

		const params = sentData( setupIntentData );

		expect( params.get( SESSION_ID_ERROR_FIELD ) ).toBe( 'timeout' );
		expect( params.has( SESSION_ID_FIELD ) ).toBe( false );
	} );

	it( 'does not add an empty session ID', () => {
		addFormField( SESSION_ID_FIELD, '' );
		loadScript();

		const params = sentData( setupIntentData );

		expect( params.has( SESSION_ID_FIELD ) ).toBe( false );
	} );

	it( 'keeps a session ID the request already has', () => {
		addFormField( SESSION_ID_FIELD, 'sess-form' );
		loadScript();

		const params = sentData( {
			...setupIntentData,
			[ SESSION_ID_FIELD ]: 'sess-request',
		} );

		expect( params.getAll( SESSION_ID_FIELD ) ).toEqual( [
			'sess-request',
		] );
	} );

	it( 'leaves other requests unchanged', () => {
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();

		const params = sentData( {
			action: 'wc_stripe_create_setup_intent',
		} );

		expect( params.has( SESSION_ID_FIELD ) ).toBe( false );
	} );

	it.each( [
		[ 'is blocked', 200, { success: false, data: { error: {} } } ],
		[ 'fails', 500, {} ],
	] )(
		'removes the attempt fields and resets the session when the setup intent request %s',
		async ( _, status, body ) => {
			addFormField( SESSION_ID_FIELD, 'sess-stripe' );
			loadScript();

			await sendSetupIntentRequest( status, body );

			expect( formHasField( SESSION_ID_FIELD ) ).toBe( 0 );
			expect( window.wcFraudProtection.reset ).toHaveBeenCalledTimes( 1 );
		}
	);

	it( 'removes the session ID error without a reset when the request fails without a session ID', async () => {
		addFormField( SESSION_ID_ERROR_FIELD, 'timeout' );
		loadScript();

		await sendSetupIntentRequest( 200, { success: false } );

		expect( formHasField( SESSION_ID_ERROR_FIELD ) ).toBe( 0 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'keeps the session ID after a successful setup intent request', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();

		await sendSetupIntentRequest( 200, { success: true, data: {} } );

		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'ends the attempt on the next submit after a setup that did not complete', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();
		await sendSetupIntentRequest( 200, { success: true, data: {} } );

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

	it( 'keeps the session ID for the form post of a completed setup', async () => {
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();
		await sendSetupIntentRequest( 200, { success: true, data: {} } );

		addFormField( 'wc-stripe-setup-intent', 'seti_123' );
		submitForm();

		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'keeps the session ID on a submit before it is sent', () => {
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();

		submitForm();

		expect( formHasField( SESSION_ID_FIELD ) ).toBe( 1 );
		expect( window.wcFraudProtection.reset ).not.toHaveBeenCalled();
	} );

	it( 'does nothing when wcFraudProtection is missing', () => {
		delete window.wcFraudProtection;
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();

		const params = sentData( setupIntentData );

		expect( params.has( SESSION_ID_FIELD ) ).toBe( false );
	} );
} );
