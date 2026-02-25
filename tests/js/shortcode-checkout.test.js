/**
 * @jest-environment jsdom
 */

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
 *
 * @package WooCommerce\FraudProtection
 */

const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

let $;
let $form;
let mockAcquireSessionId;
let mockReset;
let submitSpy;

beforeEach( () => {
	document.body.innerHTML = '<form class="checkout"></form>';

	// Stub native submit on this specific form element — jsdom doesn't
	// implement it and jQuery's trigger('submit') calls it after firing
	// jQuery handlers.
	submitSpy = jest.fn();
	document.querySelector( 'form.checkout' ).submit = submitSpy;

	$ = require( 'jquery' );
	window.jQuery = $;
	$form = $( 'form.checkout' );

	delete window.wcFraudProtection;
	jest.useFakeTimers();

	mockAcquireSessionId = jest.fn( () => Promise.resolve( 'sess-shortcode' ) );
	mockReset = jest.fn();
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.jQuery;
	delete window.wcFraudProtection;
	jest.useRealTimers();
} );

function setupFraudProtection() {
	window.wcFraudProtection = {
		acquireSessionId: mockAcquireSessionId,
		reset: mockReset,
	};
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
		expect( submitSpy ).not.toHaveBeenCalled();

		// Wait for the acquireSessionId promise to resolve.
		await flushPromises();

		// Hidden field injected into the form with correct value.
		const $field = $form.find( '#' + SESSION_ID_FIELD );
		expect( $field.length ).toBe( 1 );
		expect( $field.val() ).toBe( 'sess-shortcode' );

		// Form re-submitted.
		expect( submitSpy ).toHaveBeenCalled();
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

	it( 'removes hidden field and calls reset after allowing through', () => {
		setupFraudProtection();
		loadScript();

		// Inject the hidden field.
		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'sess-123',
		} ).appendTo( $form );

		// Second pass allows through.
		$form.triggerHandler( 'checkout_place_order' );

		// Field still exists, reset not called yet.
		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 1 );
		expect( mockReset ).not.toHaveBeenCalled();

		// Fire the setTimeout(0).
		jest.advanceTimersByTime( 0 );

		// Now the field should be gone and reset called.
		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
		expect( mockReset ).toHaveBeenCalledTimes( 1 );
	} );
} );
