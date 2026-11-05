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
 * Only the latest request's attempt is ended, and only the form fields that
 * still hold the values it sent are removed. A request that fails after a
 * newer one was sent, for example after a double submit, leaves the newer
 * attempt's fields for its form post.
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

	/**
	 * The form's attempt fields that the latest setup intent request carried,
	 * or null when that attempt has ended.
	 *
	 * @type {?{fields: Object<string, string>}}
	 */
	let latestAttempt = null;

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
	 * End an attempt when it is the latest one: remove the form fields that
	 * still hold the values it sent, and reset the session when its session ID
	 * was removed, so that the next submit acquires a new one.
	 *
	 * @param {?{fields: Object<string, string>}} attempt The attempt to end.
	 */
	function endAttempt( attempt ) {
		if ( ! attempt || attempt !== latestAttempt ) {
			return;
		}

		latestAttempt = null;

		try {
			const sessionIdField = fraudProtection.config.sessionIdField;
			let sessionIdRemoved = false;
			Object.keys( attempt.fields ).forEach( function ( name ) {
				getFormFields( name ).forEach( function ( field ) {
					if ( field.value === attempt.fields[ name ] ) {
						field.remove();
						sessionIdRemoved =
							sessionIdRemoved || name === sessionIdField;
					}
				} );
			} );

			if (
				sessionIdRemoved &&
				typeof fraudProtection.reset === 'function'
			) {
				fraudProtection.reset();
			}
		} catch {
			// Fail open.
		}
	}

	/**
	 * Add the form's attempt fields to a setup intent request body.
	 *
	 * A field that the request already has is left as it is. It still counts
	 * as sent when it has the form's value, so that a failed request ends the
	 * attempt whoever added the field.
	 *
	 * @param {*} body The request body.
	 * @return {?{fields: Object<string, string>}} The attempt that the request carries, or null when it carries none of the form's attempt fields.
	 */
	function addAttemptFields( body ) {
		if (
			! ( body instanceof window.FormData ) ||
			body.get( 'action' ) !== SETUP_INTENT_ACTION
		) {
			return null;
		}

		const fields = {};
		[
			fraudProtection.config.sessionIdField,
			fraudProtection.config.sessionIdErrorField,
		].forEach( function ( name ) {
			if ( ! name ) {
				return;
			}

			const value = getFormFieldValue( name );
			if ( ! value ) {
				return;
			}

			if ( ! body.has( name ) ) {
				body.append( name, value );
			} else if ( body.get( name ) !== value ) {
				return;
			}

			fields[ name ] = value;
		} );

		if ( ! Object.keys( fields ).length ) {
			return null;
		}

		latestAttempt = { fields };
		return latestAttempt;
	}

	window.fetch = function ( resource, options ) {
		let attempt = null;
		try {
			attempt = addAttemptFields( options && options.body );
		} catch {
			// Fail open.
		}

		const result = originalFetch.apply( window, arguments );

		if ( attempt ) {
			try {
				const end = function () {
					endAttempt( attempt );
				};

				// Registered before the caller gets the response, so the body
				// is cloned before the caller reads it.
				Promise.resolve( result )
					.then( function ( response ) {
						return response.clone().json();
					} )
					.then( function ( data ) {
						if ( ! data || data.success !== true ) {
							end();
						}
					} )
					.catch( end );
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

				endAttempt( latestAttempt );
			} catch {
				// Fail open.
			}
		},
		true
	);
} )();
