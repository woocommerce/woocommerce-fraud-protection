/**
 * @jest-environment jsdom
 */

/**
 * Tests for add-payment-method fraud protection integration.
 *
 * Uses real jQuery with jsdom. Each test:
 * 1. Sets up a <form id="add_payment_method"> in the DOM
 * 2. Sets up window.wcFraudProtection with mocked acquireSessionId
 * 3. Loads add-payment-method.js (which binds the capture-phase submit handler)
 * 4. Dispatches a native submit event and asserts behavior
 *
 * bubbleSubmitSpy is registered on document.body (not the form) so it
 * fires during the actual bubble phase — after the script's capture handler
 * has had a chance to call stopImmediatePropagation().
 *
 * acquireSessionId is tested in blackbox-init.test.js.
 * Consumer tests mock wcFraudProtection directly.
 *
 * @package WooCommerce\FraudProtection
 */

const flushPromises = () => new Promise( jest.requireActual( 'timers' ).setImmediate );

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

let $;
let form;
let mockAcquireSessionId;
let bubbleSubmitSpy;

beforeEach( () => {
	document.body.innerHTML = '<form id="add_payment_method"></form>';
	form = document.getElementById( 'add_payment_method' );

	$ = require( 'jquery' );
	window.jQuery = $;

	delete window.wcFraudProtection;
	jest.useFakeTimers();

	mockAcquireSessionId = jest.fn( () => Promise.resolve( 'sess-add-pm' ) );

	// Bubble-phase spy on body: fires only when the event propagates past
	// the form (i.e. the capture handler did not stop it).
	bubbleSubmitSpy = jest.fn();
	document.body.addEventListener( 'submit', bubbleSubmitSpy );
} );

afterEach( () => {
	document.body.removeEventListener( 'submit', bubbleSubmitSpy );
	document.body.innerHTML = '';
	delete window.jQuery;
	delete window.wcFraudProtection;
	jest.useRealTimers();
} );

function setupFraudProtection() {
	window.wcFraudProtection = {
		config: { sessionIdField: SESSION_ID_FIELD },
		acquireSessionId: mockAcquireSessionId,
	};
}

function loadScript() {
	jest.isolateModules( () => {
		require( '../../assets/js/add-payment-method' );
	} );
}

function dispatchSubmit() {
	return form.dispatchEvent(
		new Event( 'submit', { bubbles: true, cancelable: true } )
	);
}

describe( 'add-payment-method', () => {
	it( 'lets submit through when wcFraudProtection is missing (fail-open)', () => {
		loadScript();

		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( true );
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'blocks first submission, acquires session_id, injects field, re-submits', async () => {
		setupFraudProtection();
		loadScript();

		// First pass: blocks submission.
		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( false );
		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		expect( bubbleSubmitSpy ).not.toHaveBeenCalled();

		// Wait for the acquireSessionId promise to resolve.
		await flushPromises();

		// Hidden field injected with correct value.
		const field = document.getElementById( SESSION_ID_FIELD );
		expect( field ).not.toBeNull();
		expect( field.value ).toBe( 'sess-add-pm' );

		// Re-dispatch reached bubble phase, then native submit fallback fired.
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'skips native submit when a gateway handler calls preventDefault', async () => {
		setupFraudProtection();
		loadScript();

		// Simulate a gateway handler (e.g. Stripe) that prevents default
		// to handle submission itself (tokenize, then submit).
		form.addEventListener( 'submit', ( e ) => e.preventDefault() );

		dispatchSubmit();
		await flushPromises();

		expect( form.submit ).not.toHaveBeenCalled();
	} );

	it( 'stops other handlers via stopImmediatePropagation on first submission', () => {
		setupFraudProtection();
		loadScript();

		// Handler added after the script — should not fire.
		const laterCaptureSpy = jest.fn();
		form.addEventListener( 'submit', laterCaptureSpy, true );

		dispatchSubmit();

		expect( laterCaptureSpy ).not.toHaveBeenCalled();
		expect( bubbleSubmitSpy ).not.toHaveBeenCalled();
	} );

	it( 'lets through when hidden field already exists', () => {
		setupFraudProtection();
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
		expect( mockAcquireSessionId ).not.toHaveBeenCalled();
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'removes an empty temporary field after replay and acquires again later', async () => {
		mockAcquireSessionId
			.mockReturnValueOnce( Promise.resolve( '' ) )
			.mockReturnValueOnce( Promise.resolve( 'sess-later' ) );
		setupFraudProtection();
		loadScript();

		let fieldDuringReplay;
		form.addEventListener( 'submit', () => {
			fieldDuringReplay = document.getElementById( SESSION_ID_FIELD );
		} );

		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( false );
		await flushPromises();

		expect( fieldDuringReplay ).not.toBeNull();
		expect( fieldDuringReplay.value ).toBe( '' );
		expect( document.getElementById( SESSION_ID_FIELD ) ).not.toBeNull();

		jest.advanceTimersByTime( 0 );
		expect( document.getElementById( SESSION_ID_FIELD ) ).toBeNull();

		expect( dispatchSubmit() ).toBe( false );
		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 2 );
		await flushPromises();

		expect( document.getElementById( SESSION_ID_FIELD ).value ).toBe(
			'sess-later'
		);
	} );
} );
