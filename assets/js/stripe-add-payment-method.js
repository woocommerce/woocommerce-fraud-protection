/**
 * Stripe add-payment-method fraud protection integration.
 *
 * Stripe saves the payment method through an AJAX request that runs before
 * the add-payment-method form is posted, so the session ID (or the reason
 * why there is none) that add-payment-method.js adds to the form is copied
 * to that request. Errors leave the request unchanged.
 *
 * A failed setup intent request, or a setup that fails in the browser
 * afterwards (for example in card authentication), leaves the form open for
 * another attempt. Each attempt needs its own session ID, so the attempt
 * fields are removed and the session is reset: right away when the request
 * fails, and otherwise on the next submit that is not Stripe's final form
 * post. A successful setup keeps the session ID for that form post.
 *
 * Depends on add-payment-method.js (and through it wcFraudProtection) and jQuery.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const SETUP_INTENT_ACTION = 'wc_stripe_create_and_confirm_setup_intent';

	// Stripe adds this field once the setup succeeds, right before it posts the form.
	const SETUP_INTENT_FIELD = 'wc-stripe-setup-intent';

	const FORM_ID = 'add_payment_method';

	const fraudProtection = window.wcFraudProtection;

	if (
		! fraudProtection ||
		! fraudProtection.config ||
		typeof $.ajaxPrefilter !== 'function'
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
	 * @return {Object} jQuery object for the form.
	 */
	function getForm() {
		return $( '#' + FORM_ID );
	}

	/**
	 * Read a field value from the add-payment-method form.
	 *
	 * @param {string} name Field name.
	 * @return {string} The field value, or an empty string.
	 */
	function getFormFieldValue( name ) {
		const value = getForm()
			.find( 'input[name="' + name + '"]' )
			.first()
			.val();

		return typeof value === 'string' ? value : '';
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
					getForm()
						.find( 'input[name="' + name + '"]' )
						.remove();
				}
			} );

			if ( resetSession && typeof fraudProtection.reset === 'function' ) {
				fraudProtection.reset();
			}
		} catch {
			// Fail open.
		}
	}

	$.ajaxPrefilter( function ( options, originalOptions, jqXHR ) {
		try {
			if ( typeof options.data !== 'string' ) {
				return;
			}

			const params = new URLSearchParams( options.data );
			if ( params.get( 'action' ) !== SETUP_INTENT_ACTION ) {
				return;
			}

			const sessionIdField = fraudProtection.config.sessionIdField;
			const added = new URLSearchParams();
			[
				sessionIdField,
				fraudProtection.config.sessionIdErrorField,
			].forEach( function ( name ) {
				if ( ! name || params.has( name ) ) {
					return;
				}

				const value = getFormFieldValue( name );
				if ( value ) {
					added.set( name, value );
				}
			} );

			const addedData = added.toString();
			if ( ! addedData ) {
				return;
			}

			options.data += '&' + addedData;
			attemptFieldsWereSent = true;
			sessionIdWasSent = added.has( sessionIdField );

			if ( jqXHR && typeof jqXHR.then === 'function' ) {
				jqXHR.then( function ( response ) {
					if ( ! response || response.success !== true ) {
						endAttempt();
					}
				}, endAttempt );
			}
		} catch {
			// Fail open.
		}
	} );

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
} )( jQuery );
