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
	document.body.innerHTML = '';
	delete window.jQuery;
	delete window.wcFraudProtection;
	jest.useRealTimers();
} );

function setupFraudProtection() {
	window.wcFraudProtection = {
		config: { sessionIdField: SESSION_ID_FIELD },
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
		expect( document.querySelector( 'form.checkout' ).submit ).not.toHaveBeenCalled();

		// Wait for the acquireSessionId promise to resolve.
		await flushPromises();

		// Hidden field injected into the form with correct value.
		const $field = $form.find( '#' + SESSION_ID_FIELD );
		expect( $field.length ).toBe( 1 );
		expect( $field.val() ).toBe( 'sess-shortcode' );

		// Form re-submitted.
		expect( document.querySelector( 'form.checkout' ).submit ).toHaveBeenCalled();
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

		$form.triggerHandler( 'checkout_place_order' );
		$form.triggerHandler( 'checkout_place_order' );

		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'stale-first',
		} ).appendTo( $form );
		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'stale-second',
		} ).appendTo( $form );

		resolveSecond( 'sess-second' );
		await flushPromises();
		let $fields = $form.find(
			'input[name="' + SESSION_ID_FIELD + '"]'
		);
		expect( $fields.length ).toBe( 1 );
		expect( $fields.val() ).toBe( 'sess-second' );

		resolveFirst( 'sess-first' );
		await flushPromises();

		$fields = $form.find(
			'input[name="' + SESSION_ID_FIELD + '"]'
		);
		expect( $fields.length ).toBe( 1 );
		expect( $fields.val() ).toBe( 'sess-first' );
	} );

	it( 'only cleans up the form that owns the field', () => {
		document.body.innerHTML =
			'<form class="checkout" id="first"></form>' +
			'<form class="checkout" id="second"></form>';
		$form = $( '#first' );
		const $secondForm = $( '#second' );
		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'owned-form',
		} ).appendTo( $form );
		$( '<input>', {
			type: 'hidden',
			id: SESSION_ID_FIELD,
			name: SESSION_ID_FIELD,
			value: 'other-form',
		} ).appendTo( $secondForm );
		setupFraudProtection();
		loadScript();

		$form.triggerHandler( 'checkout_place_order' );
		jest.advanceTimersByTime( 0 );

		expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
		expect( $secondForm.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
			'other-form'
		);
		expect( mockReset ).toHaveBeenCalledTimes( 1 );
	} );
} );
