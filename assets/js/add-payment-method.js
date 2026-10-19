/**
 * Add payment method fraud protection integration.
 *
 * Intercepts form submit (capture phase) via
 * wcFraudProtection.acquireSessionId(), injects the session ID as a
 * hidden field (or, without one, the reason why), then re-submits. Uses dispatchEvent to fire gateway
 * handlers, falling back to form.submit() for native POST when no
 * handler prevents default (simple gateways like Check Payments).
 * The re-submit runs in a new task, after the original submit event has
 * finished dispatching, because Firefox does not deliver a submit event
 * fired on a form while another one is being dispatched on it.
 * Submits that arrive before the re-submit runs are cancelled, so repeated
 * clicks cannot start gateway handlers more than once.
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

	const form = document.getElementById( 'add_payment_method' );
	if ( ! form ) {
		return;
	}

	/**
	 * Acquire the session ID and, without one, the reason why.
	 *
	 * @param {Object} fraudProtection The wcFraudProtection API.
	 * @return {Promise<{sessionId: string, error: string}>} Session ID and error.
	 */
	function acquireSessionIdResult( fraudProtection ) {
		if ( typeof fraudProtection.acquireSessionIdResult === 'function' ) {
			return fraudProtection.acquireSessionIdResult();
		}

		return fraudProtection.acquireSessionId().then( function ( sessionId ) {
			return { sessionId, error: '' };
		} );
	}

	// True from the first held submit until its re-submit is dispatched.
	let resubmitPending = false;

	// Capture phase: fires before all bubble-phase (jQuery) handlers.
	form.addEventListener(
		'submit',
		function ( e ) {
			const fraudProtection = window.wcFraudProtection;

			if (
				! fraudProtection ||
				( ! fraudProtection.acquireSessionIdResult &&
					! fraudProtection.acquireSessionId )
			) {
				return;
			}

			const sessionIdField = fraudProtection.config.sessionIdField;

			// A held submit is still waiting for its re-submit. Cancel this
			// one so gateway handlers do not run for it as well.
			if ( resubmitPending ) {
				e.preventDefault();
				e.stopImmediatePropagation();
				return;
			}

			// Session ID already injected — let through (handles both
			// our re-submit and gateway re-triggers after tokenization).
			if ( document.getElementById( sessionIdField ) ) {
				return;
			}

			e.preventDefault();
			e.stopImmediatePropagation();
			resubmitPending = true;

			acquireSessionIdResult( fraudProtection ).then(
				function ( result ) {
					const sessionId = result.sessionId;
					const errorField =
						fraudProtection.config.sessionIdErrorField;
					// The reason describes the latest acquisition and stays
					// until the next one. A gateway can submit the form
					// natively after the empty session ID field is removed.
					const $errorField = errorField
						? $( form )
								.find( 'input[name="' + errorField + '"]' )
								.first()
						: $();
					if ( ! sessionId && result.error && errorField ) {
						if ( $errorField.length ) {
							$errorField.val( result.error );
						} else {
							$( '<input>', {
								type: 'hidden',
								name: errorField,
								value: result.error,
							} ).appendTo( form );
						}
					} else {
						$errorField.remove();
					}

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
					// Run it in a new task: when the session ID resolves at once,
					// this callback runs while the original submit event is still
					// being dispatched, and Firefox ignores a submit event fired on
					// the form during that time.
					setTimeout( function () {
						resubmitPending = false;
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
					}, 0 );
				}
			);
		},
		true
	);
} )( jQuery );
