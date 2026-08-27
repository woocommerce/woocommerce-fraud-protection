/**
 * Pay-for-order fraud protection integration.
 *
 * Intercepts form submit (capture phase) via
 * wcFraudProtection.acquireSessionId(), injects the session ID as a
 * hidden field, then re-submits. Uses dispatchEvent to fire gateway
 * handlers, falling back to form.submit() for native POST when no
 * handler prevents default (simple gateways like Check Payments).
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

	const form = document.getElementById( 'order_review' );
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

			const sessionIdField = fraudProtection.config.sessionIdField;

			// Session ID already injected — let through (handles both
			// our re-submit and gateway re-triggers after tokenization).
			if ( document.getElementById( sessionIdField ) ) {
				return;
			}

			e.preventDefault();
			e.stopImmediatePropagation();

			fraudProtection.acquireSessionId().then( function ( sessionId ) {
				let $sessionIdField = $( form )
					.find( 'input[name="' + sessionIdField + '"]' )
					.first();

				if ( $sessionIdField.length ) {
					$sessionIdField.val( sessionId );
				} else {
					$sessionIdField = $( '<input>', {
						type: 'hidden',
						name: sessionIdField,
						id: sessionIdField,
						value: sessionId,
					} ).appendTo( form );
				}

				// Re-fire submit so gateway handlers (Stripe, etc.) can
				// intercept. If no handler prevents, fall back to native POST.
				const submitEvent = new Event( 'submit', {
					bubbles: true,
					cancelable: true,
				} );
				if ( form.dispatchEvent( submitEvent ) ) {
					form.submit();
				}

				if ( ! sessionId ) {
					setTimeout( function () {
						if ( $sessionIdField.val() === '' ) {
							$sessionIdField.remove();
						}
					}, 0 );
				}
			} );
		},
		true
	);
} )( jQuery );
