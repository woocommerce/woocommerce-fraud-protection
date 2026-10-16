/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

/**
 * Tests for shortcode checkout fraud protection integration.
 *
 * Uses real jQuery with jsdom. Each test:
 * 1. Sets up a <form class="checkout"> in the DOM
 * 2. Sets up window.wcFraudProtection with mocked acquireSessionId/reset
 * 3. Loads shortcode-checkout.js (which binds the checkout_place_order handler)
 * 4. Triggers the event via jQuery and asserts behavior
 *
 * acquireSessionId and reset are tested in blackbox-init.test.js.
 * Consumer tests mock wcFraudProtection directly.
 */

const flushPromises = () =>
	new Promise( jest.requireActual( 'timers' ).setImmediate );

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';
const SESSION_ID_ERROR_FIELD = 'wc_fraud_protection_session_id_error';

let $;
let $form;
let mockAcquireSessionId;
let mockReset;

beforeEach( () => {
	document.body.innerHTML = '<form class="checkout"></form>';

	$ = require( 'jquery' );
	window.jQuery = $;
	$form = $( 'form.checkout' );

	delete window.wcFraudProtection;
	jest.useFakeTimers();

	mockAcquireSessionId = jest.fn( () => Promise.resolve( 'sess-shortcode' ) );
	mockReset = jest.fn();
} );

afterEach( () => {
	$( document ).off( 'ajaxComplete' );
	$( document.body ).off( 'checkout_error' );
	document.body.innerHTML = '';
	delete window.jQuery;
	delete window.wcFraudProtection;
	jest.useRealTimers();
} );

function setupFraudProtection( overrides = {} ) {
	window.wcFraudProtection = {
		config: {
			sessionIdField: SESSION_ID_FIELD,
			sessionIdErrorField: SESSION_ID_ERROR_FIELD,
		},
		acquireSessionId: mockAcquireSessionId,
		reset: mockReset,
		...overrides,
	};
}

function addField( $target, name, value ) {
	$( '<input>', { type: 'hidden', id: name, name, value } ).appendTo(
		$target
	);
}

function completeAjax( url ) {
	$( document ).trigger( 'ajaxComplete', [ {}, { url } ] );
}

function loadScript() {
	jest.isolateModules( () => {
		require( '../../assets/js/shortcode-checkout' );
	} );
}

describe( 'shortcode-checkout', () => {
	it( 'allows submission when wcFraudProtection is missing (fail-open)', () => {
		loadScript();

		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( true );
	} );

	it( 'blocks first submission, acquires session_id, injects field, re-submits', async () => {
		setupFraudProtection();
		loadScript();

		// First pass: blocks submission.
		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( false );
		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		expect(
			document.querySelector( 'form.checkout' ).submit
		).not.toHaveBeenCalled();

		// Wait for the acquireSessionId promise to resolve.
		await flushPromises();

		// Hidden field injected into the form with correct value.
		const $field = $form.find( '#' + SESSION_ID_FIELD );
		expect( $field.length ).toBe( 1 );
		expect( $field.val() ).toBe( 'sess-shortcode' );

		// Form re-submitted.
		expect(
			document.querySelector( 'form.checkout' ).submit
		).toHaveBeenCalled();
	} );

	it( 'allows through on second pass when hidden field exists', () => {
		setupFraudProtection();
		loadScript();

		// Inject the hidden field as if first pass did it.
		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'sess-123',
		} ).appendTo( $form );

		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( true );
		expect( mockAcquireSessionId ).not.toHaveBeenCalled();
	} );

	it( 'keeps the field and the session when a submit re-enters', () => {
		setupFraudProtection();
		loadScript();
		addField( $form, SESSION_ID_FIELD, 'sess-123' );

		expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe( true );
		jest.runAllTimers();

		expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe( 'sess-123' );
		expect( mockReset ).not.toHaveBeenCalled();
	} );

	it( 'reuses the field when a gateway stops the submit and submits again', async () => {
		setupFraudProtection();
		loadScript();

		$form.triggerHandler( 'checkout_place_order' );
		await flushPromises();
		// The gateway stops the re-submit, then submits the form again itself.
		expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe( true );
		expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe( true );

		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		expect( mockReset ).not.toHaveBeenCalled();
	} );

	it( 'removes the fields and resets after the checkout request completes', async () => {
		setupFraudProtection();
		loadScript();
		$form.triggerHandler( 'checkout_place_order' );
		await flushPromises();

		completeAjax( '/?wc-ajax=checkout' );

		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
		expect( mockReset ).toHaveBeenCalledTimes( 1 );
		expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe( false );
		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 2 );
	} );

	it.each( [
		[ 'another wc-ajax request', '/?wc-ajax=update_order_review' ],
		[ 'a REST request', '/wp-json/wc/store/v1/cart' ],
	] )( 'keeps the fields after %s completes', async ( _, url ) => {
		setupFraudProtection();
		loadScript();
		$form.triggerHandler( 'checkout_place_order' );
		await flushPromises();

		completeAjax( url );

		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 1 );
		expect( mockReset ).not.toHaveBeenCalled();
	} );

	it( 'removes the fields and resets on checkout_error', async () => {
		setupFraudProtection();
		loadScript();
		$form.triggerHandler( 'checkout_place_order' );
		await flushPromises();

		$( document.body ).trigger( 'checkout_error' );

		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
		expect( mockReset ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'resets once when checkout_error and the request completion both fire', async () => {
		setupFraudProtection();
		loadScript();
		$form.triggerHandler( 'checkout_place_order' );
		await flushPromises();

		$( document.body ).trigger( 'checkout_error' );
		completeAjax( '/?wc-ajax=checkout' );

		expect( mockReset ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'sends the reason for a missing session ID and removes it after the request', async () => {
		setupFraudProtection( {
			acquireSessionIdResult: jest.fn( () =>
				Promise.resolve( { sessionId: '', error: 'timeout' } )
			),
		} );
		loadScript();

		$form.triggerHandler( 'checkout_place_order' );
		await flushPromises();

		expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe( '' );
		expect( $form.find( '#' + SESSION_ID_ERROR_FIELD ).val() ).toBe(
			'timeout'
		);
		expect( mockAcquireSessionId ).not.toHaveBeenCalled();

		completeAjax( '/?wc-ajax=checkout' );

		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
		expect( $form.find( '#' + SESSION_ID_ERROR_FIELD ).length ).toBe( 0 );
		// No session ID was sent, so the current session is kept.
		expect( mockReset ).not.toHaveBeenCalled();
	} );

	it( 'does not send a reason with a session ID', async () => {
		setupFraudProtection( {
			acquireSessionIdResult: jest.fn( () =>
				Promise.resolve( { sessionId: 'sess-result', error: '' } )
			),
		} );
		loadScript();

		$form.triggerHandler( 'checkout_place_order' );
		await flushPromises();

		expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
			'sess-result'
		);
		expect( $form.find( '#' + SESSION_ID_ERROR_FIELD ).length ).toBe( 0 );
	} );

	it( 'reports a missing SDK when only acquireSessionIdResult is available', async () => {
		window.wcFraudProtection = {
			config: {
				sessionIdField: SESSION_ID_FIELD,
				sessionIdErrorField: SESSION_ID_ERROR_FIELD,
			},
			acquireSessionIdResult: () =>
				Promise.resolve( { sessionId: '', error: 'sdk_unavailable' } ),
		};
		loadScript();

		expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe( false );
		await flushPromises();

		expect( $form.find( '#' + SESSION_ID_ERROR_FIELD ).val() ).toBe(
			'sdk_unavailable'
		);
		expect( () => completeAjax( '/?wc-ajax=checkout' ) ).not.toThrow();
	} );

	it( 'keeps one field with the latest completed value', async () => {
		let resolveFirst;
		let resolveSecond;
		mockAcquireSessionId
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						resolveFirst = resolve;
					} )
			)
			.mockImplementationOnce(
				() =>
					new Promise( ( resolve ) => {
						resolveSecond = resolve;
					} )
			);
		setupFraudProtection();
		loadScript();

		$form.triggerHandler( 'checkout_place_order' );
		$form.triggerHandler( 'checkout_place_order' );

		resolveSecond( 'sess-second' );
		await flushPromises();
		let $fields = $form.find( 'input[name="' + SESSION_ID_FIELD + '"]' );
		expect( $fields.length ).toBe( 1 );
		expect( $fields.val() ).toBe( 'sess-second' );
		const field = $fields[ 0 ];

		resolveFirst( 'sess-first' );
		await flushPromises();

		$fields = $form.find( 'input[name="' + SESSION_ID_FIELD + '"]' );
		expect( $fields.length ).toBe( 1 );
		expect( $fields.val() ).toBe( 'sess-first' );
		expect( $fields[ 0 ] ).toBe( field );
	} );

	it( 'scopes carrier updates and cleanup to the owned form', async () => {
		document.body.innerHTML =
			'<form class="checkout" id="first"></form>' +
			'<form class="checkout" id="second"></form>';
		$form = $( '#first' );
		const $secondForm = $( '#second' );
		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'other-form',
		} ).appendTo( $secondForm );
		setupFraudProtection();
		loadScript();

		expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe( false );
		await flushPromises();

		expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
			'sess-shortcode'
		);
		expect( $secondForm.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
			'other-form'
		);
		expect( mockReset ).not.toHaveBeenCalled();

		expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe( true );
		completeAjax( '/?wc-ajax=checkout' );

		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
		expect( $secondForm.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
			'other-form'
		);
		expect( mockReset ).toHaveBeenCalledTimes( 1 );
	} );
} );
