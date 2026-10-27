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

beforeEach( () => {
	document.body.innerHTML = '<form id="add_payment_method"></form>';

	jest.isolateModules( () => {
		$ = require( 'jquery' );
	} );
	window.jQuery = $;

	window.wcFraudProtection = {
		config: {
			sessionIdField: SESSION_ID_FIELD,
			sessionIdErrorField: SESSION_ID_ERROR_FIELD,
		},
	};
} );

afterEach( () => {
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

	it( 'does nothing when wcFraudProtection is missing', () => {
		delete window.wcFraudProtection;
		addFormField( SESSION_ID_FIELD, 'sess-stripe' );
		loadScript();

		const params = sentData( setupIntentData );

		expect( params.has( SESSION_ID_FIELD ) ).toBe( false );
	} );
} );
