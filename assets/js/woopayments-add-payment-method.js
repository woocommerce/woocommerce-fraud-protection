/**
 * WooPayments add-payment-method fraud protection integration.
 *
 * WooPayments saves the payment method through a fetch() request that runs
 * before the add-payment-method form is posted, so the session ID (or the
 * reason why there is none) that add-payment-method.js adds to the form is
 * copied to that request. Errors leave the request unchanged.
 *
 * A failed setup intent request, or a setup that fails in the browser
 * afterwards (for example in card authentication), leaves the form open for
 * another attempt. Each attempt needs its own session ID, so the attempt
 * fields are removed and the session is reset: right away when the request
 * fails, and otherwise on the next submit that is not WooPayments' final form
 * post. A successful setup keeps the session ID for that form post.
 *
 * Depends on add-payment-method.js (and through it wcFraudProtection).
 */
( function () {
	'use strict';

	const SETUP_INTENT_ACTION = 'create_setup_intent';

	// WooPayments adds this field once the setup succeeds, right before it posts the form.
	const SETUP_INTENT_FIELD = 'wcpay-setup-intent';

	const FORM_ID = 'add_payment_method';

	const fraudProtection = window.wcFraudProtection;
	const originalFetch = window.fetch;

	if (
		! fraudProtection ||
		! fraudProtection.config ||
		typeof originalFetch !== 'function' ||
		typeof window.FormData !== 'function'
	) {
		return;
	}

	// True when the form's attempt fields were sent with a setup intent request.
	let attemptFieldsWereSent = false;

	// True when the session ID in the form was sent with a setup intent request.
	let sessionIdWasSent = false;

	/**
	 * Get the add-payment-method form.
	 *
	 * @return {HTMLFormElement|null} The form, or null.
	 */
	function getForm() {
		return document.getElementById( FORM_ID );
	}

	/**
	 * Get the form fields with a given name.
	 *
	 * @param {string} name Field name.
	 * @return {HTMLInputElement[]} The fields.
	 */
	function getFormFields( name ) {
		const form = getForm();
		if ( ! form ) {
			return [];
		}

		return Array.prototype.filter.call(
			form.querySelectorAll( 'input' ),
			function ( input ) {
				return input.name === name;
			}
		);
	}

	/**
	 * Read a field value from the add-payment-method form.
	 *
	 * @param {string} name Field name.
	 * @return {string} The field value, or an empty string.
	 */
	function getFormFieldValue( name ) {
		const field = getFormFields( name )[ 0 ];
		return field && typeof field.value === 'string' ? field.value : '';
	}

	/**
	 * Remove the attempt fields that were sent, and reset the session when a
	 * session ID was sent, so that the next submit acquires a new one.
	 */
	function endAttempt() {
		if ( ! attemptFieldsWereSent ) {
			return;
		}

		const resetSession = sessionIdWasSent;
		attemptFieldsWereSent = false;
		sessionIdWasSent = false;

		try {
			[
				fraudProtection.config.sessionIdField,
				fraudProtection.config.sessionIdErrorField,
			].forEach( function ( name ) {
				if ( name ) {
					getFormFields( name ).forEach( function ( field ) {
						field.remove();
					} );
				}
			} );

			if ( resetSession && typeof fraudProtection.reset === 'function' ) {
				fraudProtection.reset();
			}
		} catch {
			// Fail open.
		}
	}

	/**
	 * Add the form's attempt fields to a setup intent request body.
	 *
	 * @param {*} body The request body.
	 * @return {boolean} True when fields were added.
	 */
	function addAttemptFields( body ) {
		if (
			! ( body instanceof window.FormData ) ||
			body.get( 'action' ) !== SETUP_INTENT_ACTION
		) {
			return false;
		}

		const sessionIdField = fraudProtection.config.sessionIdField;
		let added = false;
		let sessionIdAdded = false;
		[ sessionIdField, fraudProtection.config.sessionIdErrorField ].forEach(
			function ( name ) {
				if ( ! name || body.has( name ) ) {
					return;
				}

				const value = getFormFieldValue( name );
				if ( value ) {
					body.append( name, value );
					added = true;
					sessionIdAdded = sessionIdAdded || name === sessionIdField;
				}
			}
		);

		if ( added ) {
			attemptFieldsWereSent = true;
			sessionIdWasSent = sessionIdAdded;
		}

		return added;
	}

	window.fetch = function ( resource, options ) {
		let added = false;
		try {
			added = addAttemptFields( options && options.body );
		} catch {
			// Fail open.
		}

		const result = originalFetch.apply( window, arguments );

		if ( added ) {
			try {
				// Registered before the caller gets the response, so the body
				// is cloned before the caller reads it.
				Promise.resolve( result )
					.then( function ( response ) {
						return response.clone().json();
					} )
					.then( function ( data ) {
						if ( ! data || data.success !== true ) {
							endAttempt();
						}
					} )
					.catch( endAttempt );
			} catch {
				// Fail open.
			}
		}

		return result;
	};

	// Capture phase on the document: runs before the add-payment-method.js
	// listener on the form, so that it acquires a new session ID.
	document.addEventListener(
		'submit',
		function ( e ) {
			try {
				if (
					! e.target ||
					e.target.id !== FORM_ID ||
					getFormFieldValue( SETUP_INTENT_FIELD )
				) {
					return;
				}

				endAttempt();
			} catch {
				// Fail open.
			}
		},
		true
	);
} )();
