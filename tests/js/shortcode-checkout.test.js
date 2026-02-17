/**
 * @jest-environment jsdom
 */

/**
 * Tests for shortcode checkout Blackbox integration.
 *
 * Uses real jQuery with jsdom. Each test:
 * 1. Sets up a <form class="checkout"> in the DOM
 * 2. Requires the IIFE (which binds the checkout_place_order handler)
 * 3. Triggers the event via jQuery and asserts behavior
 *
 * @package WooCommerce\FraudProtection
 */

const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

let $;
let $form;
let mockGetSessionId;
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

	delete window.Blackbox;
	jest.useFakeTimers();

	mockGetSessionId = jest.fn( () => Promise.resolve( 'sess-shortcode' ) );
	mockReset = jest.fn( () => Promise.resolve() );
} );

afterEach( () => {
	document.body.innerHTML = '';
	delete window.jQuery;
	delete window.Blackbox;
	jest.useRealTimers();
} );

function loadScript() {
	jest.isolateModules( () => {
		require( '../../assets/js/shortcode-checkout' );
	} );
}

function setupBlackbox( overrides = {} ) {
	window.Blackbox = {
		getSessionId: mockGetSessionId,
		reset: mockReset,
		...overrides,
	};
}

describe( 'shortcode-checkout', () => {
	it( 'registers a checkout_place_order handler on form.checkout', () => {
		setupBlackbox();
		loadScript();

		const events = $._data( $form[ 0 ], 'events' );
		expect( events.checkout_place_order ).toHaveLength( 1 );
	} );

	it( 'allows submission when Blackbox is missing (fail-open)', () => {
		loadScript();

		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( true );
	} );

	it( 'allows submission when Blackbox.getSessionId is missing (fail-open)', () => {
		window.Blackbox = { reset: mockReset };
		loadScript();

		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( true );
	} );

	it( 'allows submission when Blackbox.reset is missing (fail-open)', () => {
		window.Blackbox = { getSessionId: mockGetSessionId };
		loadScript();

		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( true );
	} );

	it( 'blocks first submission, acquires session_id, injects field, re-submits', async () => {
		setupBlackbox();
		loadScript();

		// First pass: blocks submission.
		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( false );
		expect( mockGetSessionId ).toHaveBeenCalledTimes( 1 );
		expect( submitSpy ).not.toHaveBeenCalled();

		// Wait for the getSessionId promise to resolve.
		await flushPromises();

		// Hidden field injected into the form with correct value.
		const $field = $form.find( '#' + SESSION_ID_FIELD );
		expect( $field.length ).toBe( 1 );
		expect( $field.val() ).toBe( 'sess-shortcode' );

		// Form re-submitted.
		expect( submitSpy ).toHaveBeenCalled();
	} );

	it( 'allows submission on timeout when getSessionId takes too long', async () => {
		mockGetSessionId.mockReturnValue( new Promise( () => {} ) ); // Never resolves.

		setupBlackbox();
		loadScript();

		// First pass blocks.
		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( false );
		expect( submitSpy ).not.toHaveBeenCalled();

		// Timeout fires after 5 seconds.
		await jest.advanceTimersByTimeAsync( 5000 );

		// After timeout, form is re-submitted with empty session_id.
		expect( submitSpy ).toHaveBeenCalled();
		expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe( '' );
	} );

	it( 'fails open when getSessionId rejects', async () => {
		mockGetSessionId.mockReturnValue( Promise.reject( new Error( 'SDK error' ) ) );

		setupBlackbox();
		loadScript();

		// First pass blocks.
		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( false );

		// Wait for the rejection to propagate.
		await flushPromises();

		// Should still re-submit with empty session_id (fail-open).
		expect( submitSpy ).toHaveBeenCalled();
		expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe( '' );
	} );

	it( 'allows through on second pass when hidden field exists', () => {
		setupBlackbox();
		loadScript();

		// Inject the hidden field as if first pass did it.
		$( '<input type="hidden" id="' + SESSION_ID_FIELD + '" name="' + SESSION_ID_FIELD + '">' )
			.val( 'sess-123' )
			.appendTo( $form );

		const result = $form.triggerHandler( 'checkout_place_order' );
		expect( result ).toBe( true );
		expect( mockGetSessionId ).not.toHaveBeenCalled();
	} );

	it( 'removes hidden field and calls reset after allowing through', () => {
		setupBlackbox();
		loadScript();

		// Inject the hidden field.
		$( '<input type="hidden" id="' + SESSION_ID_FIELD + '" name="' + SESSION_ID_FIELD + '">' )
			.val( 'sess-123' )
			.appendTo( $form );

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
