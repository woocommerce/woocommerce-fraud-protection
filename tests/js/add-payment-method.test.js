/** @jest-environment jsdom */ // eslint-disable-line jsdoc/check-tag-names

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
 */

const flushPromises = () =>
	new Promise( jest.requireActual( 'timers' ).setImmediate );

const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';
const SESSION_ID_ERROR_FIELD = 'wc_fraud_protection_session_id_error';

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
		config: {
			sessionIdField: SESSION_ID_FIELD,
			sessionIdErrorField: SESSION_ID_ERROR_FIELD,
		},
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

		// Run the deferred re-dispatch.
		jest.runOnlyPendingTimers();

		// Re-dispatch reached bubble phase, then native submit fallback fired.
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'skips native submit when a gateway handler calls preventDefault', async () => {
		setupFraudProtection();
		loadScript();

		// Simulate a gateway handler (e.g. Stripe) that prevents default
		// to handle submission itself (tokenize, then submit).
		let fieldDuringGatewaySubmit;
		form.addEventListener( 'submit', ( e ) => {
			fieldDuringGatewaySubmit =
				document.getElementById( SESSION_ID_FIELD );
			e.preventDefault();
		} );

		dispatchSubmit();
		await flushPromises();
		jest.runOnlyPendingTimers();

		expect( fieldDuringGatewaySubmit ).not.toBeNull();
		expect( fieldDuringGatewaySubmit.value ).toBe( 'sess-add-pm' );
		expect( form.submit ).not.toHaveBeenCalled();
	} );

	it( 're-fires submit in a new task after the session ID resolves', async () => {
		setupFraudProtection();
		loadScript();

		dispatchSubmit();
		await flushPromises();

		// Firefox ignores a submit event fired on the form while the original
		// one is still being dispatched, so the re-fire must not happen in the
		// same task as the session ID callback.
		expect( document.getElementById( SESSION_ID_FIELD ) ).not.toBeNull();
		expect( bubbleSubmitSpy ).not.toHaveBeenCalled();
		expect( form.submit ).not.toHaveBeenCalled();

		jest.runOnlyPendingTimers();

		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
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

	it( 'cancels another submit while the session ID is pending', async () => {
		let resolveSessionId;
		mockAcquireSessionId.mockImplementationOnce(
			() =>
				new Promise( ( resolve ) => {
					resolveSessionId = resolve;
				} )
		);
		setupFraudProtection();
		loadScript();

		dispatchSubmit();
		expect( dispatchSubmit() ).toBe( false );
		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
		expect( bubbleSubmitSpy ).not.toHaveBeenCalled();

		resolveSessionId( 'sess-pending' );
		await flushPromises();
		jest.runOnlyPendingTimers();

		expect(
			form.querySelectorAll( 'input[name="' + SESSION_ID_FIELD + '"]' )
		).toHaveLength( 1 );
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'cancels another submit until the deferred re-fire runs', async () => {
		setupFraudProtection();
		loadScript();

		dispatchSubmit();
		await flushPromises();

		// The field is already in the form, but the re-fire has not run yet.
		expect( document.getElementById( SESSION_ID_FIELD ) ).not.toBeNull();
		expect( dispatchSubmit() ).toBe( false );
		expect( bubbleSubmitSpy ).not.toHaveBeenCalled();

		jest.runOnlyPendingTimers();
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 1 );
		expect( form.submit ).toHaveBeenCalledTimes( 1 );

		// After the re-fire, a gateway re-trigger passes through.
		expect( dispatchSubmit() ).toBe( true );
		expect( bubbleSubmitSpy ).toHaveBeenCalledTimes( 2 );
		expect( mockAcquireSessionId ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'removes an empty temporary field after replay and acquires again later', async () => {
		mockAcquireSessionId
			.mockReturnValueOnce( Promise.resolve( '' ) )
			.mockReturnValueOnce( Promise.resolve( 'sess-later' ) );
		setupFraudProtection();
		loadScript();

		let fieldDuringReplay;
		let fieldDuringNativeSubmit;
		form.addEventListener( 'submit', () => {
			fieldDuringReplay = document.getElementById( SESSION_ID_FIELD );
		} );
		form.submit = jest.fn( () => {
			fieldDuringNativeSubmit =
				document.getElementById( SESSION_ID_FIELD );
		} );

		const notCancelled = dispatchSubmit();
		expect( notCancelled ).toBe( false );
		await flushPromises();
		jest.runOnlyPendingTimers();

		expect( fieldDuringReplay ).not.toBeNull();
		expect( fieldDuringReplay.value ).toBe( '' );
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
		expect( fieldDuringNativeSubmit ).toBe( fieldDuringReplay );
		expect( fieldDuringNativeSubmit.value ).toBe( '' );

		const temporaryField = fieldDuringReplay;
		const otherField = document.createElement( 'input' );
		otherField.id = SESSION_ID_FIELD;
		otherField.name = SESSION_ID_FIELD;
		form.appendChild( otherField );

		// Run the empty-value cleanup scheduled by the re-fire.
		jest.runOnlyPendingTimers();
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

	it( 'keeps the reason for a gateway that submits the form after a delay', async () => {
		setupFraudProtection();
		window.wcFraudProtection.acquireSessionIdResult = jest.fn( () =>
			Promise.resolve( { sessionId: '', error: 'sdk_unavailable' } )
		);
		loadScript();

		// Like Stripe: stop the re-fired submit, prepare the payment, then
		// submit the form natively.
		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			setTimeout( () => form.submit(), 1000 );
		} );
		let fieldsDuringNativeSubmit;
		form.submit = jest.fn( () => {
			fieldsDuringNativeSubmit = {
				sessionId: document.getElementById( SESSION_ID_FIELD ),
				error: form.querySelector(
					'input[name="' + SESSION_ID_ERROR_FIELD + '"]'
				),
			};
		} );

		dispatchSubmit();
		await flushPromises();
		jest.runAllTimers();

		expect( mockAcquireSessionId ).not.toHaveBeenCalled();
		expect( form.submit ).toHaveBeenCalledTimes( 1 );
		expect( fieldsDuringNativeSubmit.sessionId ).toBeNull();
		expect( fieldsDuringNativeSubmit.error.value ).toBe(
			'sdk_unavailable'
		);
	} );

	it.each( [
		[
			'updates the reason when the next acquisition has none either',
			{ sessionId: '', error: 'sdk_error' },
			[ 'sdk_error' ],
		],
		[
			'removes the reason when the next acquisition has a session ID',
			{ sessionId: 'sess-later', error: '' },
			[],
		],
	] )( '%s', async ( _, secondResult, expectedErrors ) => {
		setupFraudProtection();
		window.wcFraudProtection.acquireSessionIdResult = jest
			.fn()
			.mockResolvedValueOnce( { sessionId: '', error: 'timeout' } )
			.mockResolvedValueOnce( secondResult );
		loadScript();
		form.submit = jest.fn();

		dispatchSubmit();
		await flushPromises();
		jest.runAllTimers();

		dispatchSubmit();
		await flushPromises();
		jest.runOnlyPendingTimers();

		const errorFields = form.querySelectorAll(
			'input[name="' + SESSION_ID_ERROR_FIELD + '"]'
		);
		expect( Array.from( errorFields, ( field ) => field.value ) ).toEqual(
			expectedErrors
		);
		expect( document.getElementById( SESSION_ID_FIELD ).value ).toBe(
			secondResult.sessionId
		);
	} );

	it( 'does not send a reason with a session ID', async () => {
		setupFraudProtection();
		window.wcFraudProtection.acquireSessionIdResult = jest.fn( () =>
			Promise.resolve( { sessionId: 'sess-result', error: '' } )
		);
		loadScript();

		dispatchSubmit();
		await flushPromises();
		jest.runOnlyPendingTimers();

		expect( document.getElementById( SESSION_ID_FIELD ).value ).toBe(
			'sess-result'
		);
		expect(
			form.querySelector( 'input[name="' + SESSION_ID_ERROR_FIELD + '"]' )
		).toBeNull();
	} );
} );
