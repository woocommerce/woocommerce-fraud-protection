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

	// A fresh jQuery, so AJAX prefilters and transports do not pile up.
	jest.isolateModules( () => {
		$ = require( 'jquery' );
	} );
	window.jQuery = $;
	$form = $( 'form.checkout' );

	delete window.wcFraudProtection;
	jest.useFakeTimers();

	mockAcquireSessionId = jest.fn( () => Promise.resolve( 'sess-shortcode' ) );
	mockReset = jest.fn();
} );

afterEach( () => {
	$( document ).off( 'ajaxSend ajaxComplete' );
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

/**
 * Answer AJAX requests without a network, when the test completes them.
 *
 * @return {{sent: string[], completeNext: Function}} Data of each sent request, and a function that completes the oldest pending one.
 */
function stubTransport() {
	const sent = [];
	const pending = [];
	$.ajaxTransport( '+*', function ( options ) {
		return {
			send( headers, complete ) {
				sent.push( options.data );
				pending.push( complete );
			},
			abort() {},
		};
	} );

	return {
		sent,
		completeNext() {
			pending.shift()( 200, 'success', { text: '{"result":"failure"}' } );
		},
	};
}

/**
 * Send the checkout request the way WooCommerce does, reporting its error.
 */
function sendCheckoutRequest() {
	$.ajax( {
		type: 'POST',
		url: '/?wc-ajax=checkout',
		data: $form.serialize(),
		dataType: 'json',
		success() {
			$( document.body ).trigger( 'checkout_error' );
		},
	} );
}

/**
 * Read a field from the data of a sent request.
 *
 * @param {string} data Sent data.
 * @param {string} name Field name.
 * @return {string|null} Field value, or null when absent.
 */
function sentField( data, name ) {
	return new URLSearchParams( data ).get( name );
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

	describe( 'checkout requests', () => {
		let transport;
		let mockTakePreparedSessionId;
		let resolveAcquisition;

		beforeEach( () => {
			$form.append(
				'<input name="billing_email" value="a@example.com">'
			);
			transport = stubTransport();
			mockTakePreparedSessionId = jest.fn( () => '' );
			// The session ID arrives when the test decides.
			mockAcquireSessionId.mockImplementation(
				() =>
					new Promise( ( resolve ) => {
						resolveAcquisition = resolve;
					} )
			);
		} );

		function setup() {
			setupFraudProtection( {
				prepareSessionId: jest.fn(),
				takePreparedSessionId: mockTakePreparedSessionId,
			} );
			loadScript();
		}

		it( 'keeps a session ID ready when loaded', () => {
			setup();

			expect(
				window.wcFraudProtection.prepareSessionId
			).toHaveBeenCalledTimes( 1 );
		} );

		it( 'sends the session ID from the form unchanged, then resets', async () => {
			setup();
			$form.triggerHandler( 'checkout_place_order' );
			resolveAcquisition( 'sess-form' );
			await flushPromises();

			expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe(
				true
			);
			sendCheckoutRequest();
			transport.completeNext();

			expect( sentField( transport.sent[ 0 ], SESSION_ID_FIELD ) ).toBe(
				'sess-form'
			);
			expect(
				sentField( transport.sent[ 0 ], SESSION_ID_ERROR_FIELD )
			).toBeNull();
			expect( mockTakePreparedSessionId ).not.toHaveBeenCalled();
			expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'adds the prepared session ID to a checkout request sent before the session ID is ready', async () => {
			mockTakePreparedSessionId.mockReturnValue( 'sess-prepared' );
			setup();

			// Another handler lets the submit continue.
			expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe(
				false
			);
			sendCheckoutRequest();

			expect( sentField( transport.sent[ 0 ], SESSION_ID_FIELD ) ).toBe(
				'sess-prepared'
			);
			expect(
				sentField( transport.sent[ 0 ], SESSION_ID_ERROR_FIELD )
			).toBeNull();
			expect( sentField( transport.sent[ 0 ], 'billing_email' ) ).toBe(
				'a@example.com'
			);

			// The same session ID arrives for the gated submit.
			resolveAcquisition( 'sess-prepared' );
			await flushPromises();
			expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
			expect(
				document.querySelector( 'form.checkout' ).submit
			).not.toHaveBeenCalled();

			transport.completeNext();

			// Taking the prepared ID already started a fresh session.
			expect( mockReset ).not.toHaveBeenCalled();
			expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe(
				false
			);
		} );

		it( 'reports not_ready when no session ID is prepared', () => {
			setup();

			$form.triggerHandler( 'checkout_place_order' );
			sendCheckoutRequest();

			expect( sentField( transport.sent[ 0 ], SESSION_ID_FIELD ) ).toBe(
				''
			);
			expect(
				sentField( transport.sent[ 0 ], SESSION_ID_ERROR_FIELD )
			).toBe( 'not_ready' );
		} );

		it( 'keeps a session ID that arrives after the request left for the next attempt', async () => {
			setup();

			$form.triggerHandler( 'checkout_place_order' );
			sendCheckoutRequest();
			resolveAcquisition( 'sess-late' );
			await flushPromises();
			expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
				'sess-late'
			);
			expect(
				document.querySelector( 'form.checkout' ).submit
			).not.toHaveBeenCalled();

			// The request reports its error, then completes.
			transport.completeNext();

			expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
				'sess-late'
			);
			expect( mockReset ).not.toHaveBeenCalled();

			// The next attempt sends it.
			expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe(
				true
			);
			sendCheckoutRequest();
			transport.completeNext();

			expect( sentField( transport.sent[ 1 ], SESSION_ID_FIELD ) ).toBe(
				'sess-late'
			);
			expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
			expect( mockReset ).toHaveBeenCalledTimes( 1 );
		} );

		it( 'keeps a session ID that arrives after the request completed, without submitting again', async () => {
			setup();

			$form.triggerHandler( 'checkout_place_order' );
			sendCheckoutRequest();
			transport.completeNext();
			resolveAcquisition( 'sess-later' );
			await flushPromises();

			expect(
				document.querySelector( 'form.checkout' ).submit
			).not.toHaveBeenCalled();
			expect( transport.sent ).toHaveLength( 1 );
			expect( $form.find( '#' + SESSION_ID_FIELD ).val() ).toBe(
				'sess-later'
			);

			// The next attempt sends it.
			expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe(
				true
			);
			sendCheckoutRequest();

			expect( sentField( transport.sent[ 1 ], SESSION_ID_FIELD ) ).toBe(
				'sess-later'
			);
		} );

		it( 'drops a missing-session reason that arrives after the request completed, without submitting again', async () => {
			mockAcquireSessionId.mockImplementation( () => undefined );
			setupFraudProtection( {
				prepareSessionId: jest.fn(),
				takePreparedSessionId: mockTakePreparedSessionId,
				acquireSessionIdResult: () =>
					new Promise( ( resolve ) => {
						resolveAcquisition = resolve;
					} ),
			} );
			loadScript();

			$form.triggerHandler( 'checkout_place_order' );
			sendCheckoutRequest();
			transport.completeNext();
			resolveAcquisition( { sessionId: '', error: 'timeout' } );
			await flushPromises();

			expect(
				document.querySelector( 'form.checkout' ).submit
			).not.toHaveBeenCalled();
			expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
			expect( $form.find( '#' + SESSION_ID_ERROR_FIELD ).length ).toBe(
				0
			);
			expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe(
				false
			);
		} );

		it( 'drops a missing-session reason that arrives after the request left', async () => {
			mockAcquireSessionId.mockImplementation( () => undefined );
			setupFraudProtection( {
				prepareSessionId: jest.fn(),
				takePreparedSessionId: mockTakePreparedSessionId,
				acquireSessionIdResult: () =>
					new Promise( ( resolve ) => {
						resolveAcquisition = resolve;
					} ),
			} );
			loadScript();

			$form.triggerHandler( 'checkout_place_order' );
			sendCheckoutRequest();
			resolveAcquisition( { sessionId: '', error: 'timeout' } );
			await flushPromises();
			transport.completeNext();

			// The next attempt tries again.
			expect( $form.find( '#' + SESSION_ID_FIELD ).length ).toBe( 0 );
			expect( $form.find( '#' + SESSION_ID_ERROR_FIELD ).length ).toBe(
				0
			);
			expect( $form.triggerHandler( 'checkout_place_order' ) ).toBe(
				false
			);
		} );

		it( 'leaves other requests unchanged', () => {
			setup();

			$.ajax( {
				type: 'POST',
				url: '/?wc-ajax=update_order_review',
				data: 'billing_email=a%40example.com',
			} );

			expect( transport.sent[ 0 ] ).toBe(
				'billing_email=a%40example.com'
			);
			expect( mockTakePreparedSessionId ).not.toHaveBeenCalled();
		} );
	} );
} );
