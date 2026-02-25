/**
 * @jest-environment jsdom
 */

/**
 * Tests for add-payment-method Blackbox integration.
 *
 * Uses real jQuery with jsdom. Each test:
 * 1. Sets up a <form id="add_payment_method"> in the DOM
 * 2. Requires the IIFE (which binds the capture-phase submit handler)
 * 3. Dispatches a native submit event and asserts behavior
 *
 * bubbleSubmitSpy is registered on document.body (not the form) so it
 * fires during the actual bubble phase — after the script's capture handler
 * has had a chance to call stopImmediatePropagation().
 *
 * @package WooCommerce\FraudProtection
 */

const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

let $;
let form;
let mockGetSessionId;
let bubbleSubmitSpy;

beforeEach( () => {
	document.body.innerHTML = '<form id="add_payment_method"></form>';
	form = document.getElementById( 'add_payment_method' );

	$ = require( 'jquery' );
	window.jQuery = $;

	delete window.Blackbox;
	jest.useFakeTimers();

	mockGetSessionId = jest.fn( () => Promise.resolve( 'sess-add-pm' ) );

	// Bubble-phase spy on body: fires only when the event propagates past
	// the form (i.e. the capture handler did not stop it).
	bubbleSubmitSpy = jest.fn();
	document.body.addEventListener( 'submit', bubbleSubmitSpy );
} );

afterEach( () => {
	document.body.removeEventListener( 'submit', bubbleSubmitSpy );
	document.body.innerHTML = '';
	delete window.jQuery;
	delete window.Blackbox;
	jest.useRealTimers();
} );

function loadScript() {
	jest.isolateModules( () => {
		require( '../../assets/js/add-payment-method' );
	} );
}

function setupBlackbox( overrides = {} ) {
	window.Blackbox = {
		getSessionId: mockGetSessionId,
		...overrides,
	};
}

function dispatchSubmit() {
	return form.dispatchEvent(
		new Event( 'submit', { bubbles: true, cancelable: true } )
	);
}

describe( 'add-payment-method', () => {
	it( 'lets submit through when Blackbox is missing (fail-open)', () => {
		loadScript();

		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( true );
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'lets submit through when Blackbox.getSessionId is missing (fail-open)', () => {
		window.Blackbox = {};
		loadScript();

		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( true );
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'blocks first submission, acquires session_id, injects field, re-dispatches', async () => {
		setupBlackbox();
		loadScript();

		// First pass: blocks submission.
		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( false );
		expect( mockGetSessionId ).toHaveBeenCalledTimes( 1 );
		expect( bubbleSubmitSpy ).not.toHaveBeenCalled();

		// Wait for the getSessionId promise to resolve.
		await flushPromises();

		// Hidden field injected with correct value.
		const field = document.getElementById( SESSION_ID_FIELD );
		expect( field ).not.toBeNull();
		expect( field.value ).toBe( 'sess-add-pm' );

		// Re-dispatch reached bubble phase.
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'stops other handlers via stopImmediatePropagation on first submission', () => {
		setupBlackbox();
		loadScript();

		// Handler added after the script — should not fire.
		const laterCaptureSpy = jest.fn();
		form.addEventListener( 'submit', laterCaptureSpy, true );

		dispatchSubmit();

		expect( laterCaptureSpy ).not.toHaveBeenCalled();
		expect( bubbleSubmitSpy ).not.toHaveBeenCalled();
	} );

	it( 'allows submission on timeout when getSessionId takes too long', async () => {
		mockGetSessionId.mockReturnValue( new Promise( () => {} ) );
		setupBlackbox();
		loadScript();

		dispatchSubmit();

		await jest.advanceTimersByTimeAsync( 5000 );

		const field = document.getElementById( SESSION_ID_FIELD );
		expect( field ).not.toBeNull();
		expect( field.value ).toBe( '' );
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'fails open when getSessionId rejects', async () => {
		mockGetSessionId.mockReturnValue( Promise.reject( new Error( 'SDK error' ) ) );
		setupBlackbox();
		loadScript();

		dispatchSubmit();
		await flushPromises();

		const field = document.getElementById( SESSION_ID_FIELD );
		expect( field ).not.toBeNull();
		expect( field.value ).toBe( '' );
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'lets through when hidden field already exists', () => {
		setupBlackbox();
		loadScript();

		// Pre-inject hidden field.
		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'pre-existing',
		} ).appendTo( form );

		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( true );
		expect( mockGetSessionId ).not.toHaveBeenCalled();
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );
} );
