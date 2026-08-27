/**
 * @jest-environment jsdom
 */

/**
 * Tests for pay-for-order fraud protection integration.
 *
 * Uses real jQuery with jsdom. Each test:
 * 1. Sets up a <form id="order_review"> in the DOM
 * 2. Sets up window.wcFraudProtection with mocked acquireSessionId
 * 3. Loads pay-for-order.js (which binds the capture-phase submit handler)
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
	document.body.innerHTML = '<form id="order_review"></form>';
	form = document.getElementById( 'order_review' );

	$ = require( 'jquery' );
	window.jQuery = $;

	delete window.wcFraudProtection;
	jest.useFakeTimers();

	mockAcquireSessionId = jest.fn( () => Promise.resolve( 'sess-pay-order' ) );

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
		require( '../../assets/js/pay-for-order' );
	} );
}

function dispatchSubmit() {
	return form.dispatchEvent(
		new Event( 'submit', { bubbles: true, cancelable: true } )
	);
}

describe( 'pay-for-order', () => {
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
		expect( field.value ).toBe( 'sess-pay-order' );

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

	it( 'keeps one field with the latest completed value', async () => {
		let resolveFirst;
		let resolveSecond;
		mockAcquireSessionId
			.mockImplementationOnce(
				() => new Promise( ( resolve ) => {
					resolveFirst = resolve;
				} )
			)
			.mockImplementationOnce(
				() => new Promise( ( resolve ) => {
					resolveSecond = resolve;
				} )
			);
		setupFraudProtection();
		loadScript();

		dispatchSubmit();
		dispatchSubmit();
		resolveSecond( 'sess-second' );
		await flushPromises();

		let fields = form.querySelectorAll(
			'input[name="' + SESSION_ID_FIELD + '"]'
		);
		expect( fields ).toHaveLength( 1 );
		expect( fields[ 0 ].value ).toBe( 'sess-second' );
		const field = fields[ 0 ];

		resolveFirst( 'sess-first' );
		await flushPromises();

		fields = form.querySelectorAll(
			'input[name="' + SESSION_ID_FIELD + '"]'
		);
		expect( fields ).toHaveLength( 1 );
		expect( fields[ 0 ] ).toBe( field );
		expect( field.value ).toBe( 'sess-first' );
	} );

	it( 'keeps a later nonempty value after empty cleanup', async () => {
		let resolveEmpty;
		let resolveNonempty;
		mockAcquireSessionId
			.mockImplementationOnce(
				() => new Promise( ( resolve ) => {
					resolveEmpty = resolve;
				} )
			)
			.mockImplementationOnce(
				() => new Promise( ( resolve ) => {
					resolveNonempty = resolve;
				} )
			);
		setupFraudProtection();
		loadScript();

		dispatchSubmit();
		dispatchSubmit();
		resolveEmpty( '' );
		await flushPromises();
		const field = document.getElementById( SESSION_ID_FIELD );
		expect( field.value ).toBe( '' );

		resolveNonempty( 'sess-valid' );
		await flushPromises();
		expect( field.value ).toBe( 'sess-valid' );

		jest.advanceTimersByTime( 0 );
		expect( field.isConnected ).toBe( true );
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
		expect( form.submit ).toHaveBeenCalledTimes( 1 );

		const temporaryField = fieldDuringReplay;
		const otherField = document.createElement( 'input' );
		otherField.id = SESSION_ID_FIELD;
		otherField.name = SESSION_ID_FIELD;
		form.appendChild( otherField );

		jest.advanceTimersByTime( 0 );
		expect( temporaryField.isConnected ).toBe( false );
		expect( otherField.isConnected ).toBe( true );
		otherField.remove();

		expect( dispatchSubmit() ).toBe( false );
		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 2 );
		await flushPromises();

		expect( document.getElementById( SESSION_ID_FIELD ).value ).toBe(
			'sess-later'
		);
	} );
} );
