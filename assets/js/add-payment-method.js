/**
 * Add payment method fraud protection integration.
 *
 * Intercepts form submit (capture phase) to acquire a Blackbox session ID,
 * injects it as a hidden field, then re-dispatches submit so gateway
 * handlers proceed. Capture phase + stopImmediatePropagation ensures this
 * fires first and prevents gateway handlers (e.g. Stripe tokenization)
 * from starting concurrent async work that would cause a double-POST.
 *
 * No Blackbox.reset() needed — every outcome is a full page navigation.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';
	const TIMEOUT_MS = 5000;

	const form = document.getElementById( 'add_payment_method' );
	if ( ! form ) {
		return;
	}

	/**
	 * Acquire a Blackbox session ID (fail-open: empty string on timeout/error).
	 *
	 * @return {Promise<string>} Session ID or empty string.
	 */
	function acquireSessionId() {
		const timeout = new Promise( function ( resolve ) {
			setTimeout( function () {
				resolve( '' );
			}, TIMEOUT_MS );
		} );

		return Promise.race( [
			window.Blackbox.getSessionId(),
			timeout,
		] ).catch( function () {
			return '';
		} );
	}

	// Capture phase: fires before all bubble-phase (jQuery) handlers.
	form.addEventListener(
		'submit',
		function ( e ) {
			// Fail-open: let submit proceed if Blackbox unavailable.
			if ( ! window.Blackbox || ! window.Blackbox.getSessionId ) {
				return;
			}

			// Session ID already injected — let through (handles both
			// our re-dispatch and gateway re-triggers after tokenization).
			if ( document.getElementById( SESSION_ID_FIELD ) ) {
				return;
			}

			e.preventDefault();
			e.stopImmediatePropagation();

			acquireSessionId().then( function ( sessionId ) {
				$(
					'<input type="hidden" name="' +
						SESSION_ID_FIELD +
						'" id="' +
						SESSION_ID_FIELD +
						'">'
				)
					.val( sessionId )
					.appendTo( form );

				form.dispatchEvent(
					new Event( 'submit', {
						bubbles: true,
						cancelable: true,
					} )
				);
			} );
		},
		true
	);
} )( jQuery );
