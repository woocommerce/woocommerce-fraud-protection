/**
 * Add payment method fraud protection integration.
 *
 * Intercepts form submit (capture phase) via
 * wcFraudProtection.acquireSessionId(), injects the session ID as a
 * hidden field, then re-dispatches submit so gateway handlers proceed.
 * Capture phase + stopImmediatePropagation ensures this fires first and
 * prevents gateway handlers (e.g. Stripe tokenization) from starting
 * concurrent async work that would cause a double-POST.
 *
 * No reset needed — every outcome is a full page navigation.
 *
 * Depends on blackbox-init.js (wcFraudProtection) and jQuery.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	const SESSION_ID_FIELD = 'wc_fraud_protection_session_id';

	const form = document.getElementById( 'add_payment_method' );
	if ( ! form ) {
		return;
	}

	// Capture phase: fires before all bubble-phase (jQuery) handlers.
	form.addEventListener(
		'submit',
		function ( e ) {
			const fraudProtection = window.wcFraudProtection;

			if ( ! fraudProtection || ! fraudProtection.acquireSessionId ) {
				return;
			}

			// Session ID already injected — let through (handles both
			// our re-dispatch and gateway re-triggers after tokenization).
			if ( document.getElementById( SESSION_ID_FIELD ) ) {
				return;
			}

			e.preventDefault();
			e.stopImmediatePropagation();

			fraudProtection.acquireSessionId().then( function ( sessionId ) {
				$( '<input>', {
					type: 'hidden',
					name: SESSION_ID_FIELD,
					id: SESSION_ID_FIELD,
					value: sessionId,
				} ).appendTo( form );

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
