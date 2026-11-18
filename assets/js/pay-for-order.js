/**
 * Pay-for-order fraud protection integration.
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
 * A gateway can also start the submit with jQuery's trigger(), as Square's
 * wallet and Cash App Pay buttons do. That runs the jQuery handlers and then
 * posts the form without a submit event, so the capture listener never sees it.
 * The jQuery handlers cannot be held and run again, so a jQuery handler adds
 * the session ID prepared in advance, or the `not_ready` reason when none is
 * ready, and lets the submit continue.
 *
 * A payment can end without leaving the page, for example a declined card
 * shown inline or a payment window that the shopper closes, and the page can
 * come back from the back-forward cache. The session ID in the form was
 * already verified for the earlier attempt, so a submit by the shopper
 * acquires a new one, and a restored page drops it. A submit is the shopper's
 * when it is trusted and follows a trusted click or Enter key press in the
 * same task. Gateways that submit again after tokenization use
 * dispatchEvent(), jQuery's trigger(), or form.submit(), which are not trusted
 * or fire no submit event. A gateway that clicks the Pay button from a script
 * gets a trusted submit, but its click is not trusted. These keep the session
 * ID of the attempt they complete.
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

	/**
	 * Add the session ID prepared in advance to the form.
	 *
	 * Without one, adds only the `not_ready` reason, unless the form has the
	 * reason from an earlier acquisition, so a later submit can still acquire
	 * a session ID. Errors leave the form unchanged.
	 *
	 * @param {Object} fraudProtection The wcFraudProtection API.
	 */
	function addPreparedSessionId( fraudProtection ) {
		try {
			const { sessionIdField, sessionIdErrorField } =
				fraudProtection.config;
			const sessionId =
				typeof fraudProtection.takePreparedSessionId === 'function'
					? fraudProtection.takePreparedSessionId()
					: '';
			const $errorField = sessionIdErrorField
				? $( form )
						.find( 'input[name="' + sessionIdErrorField + '"]' )
						.first()
				: $();

			if ( sessionId ) {
				$errorField.remove();
				$( '<input>', {
					type: 'hidden',
					name: sessionIdField,
					id: sessionIdField,
					value: sessionId,
				} ).appendTo( form );
			} else if ( sessionIdErrorField && ! $errorField.length ) {
				$( '<input>', {
					type: 'hidden',
					name: sessionIdErrorField,
					value: 'not_ready',
				} ).appendTo( form );
			}
		} catch {
			// Fail open: the form is posted without a session ID.
		}
	}

	// True from the first held submit until its re-submit is dispatched.
	let resubmitPending = false;

	// True from the shopper's click or Enter key press until the end of its
	// task. A submit that the shopper starts this way is dispatched in that
	// same task.
	let isShopperAction = false;

	/**
	 * Note a trusted click or key press until the end of the current task.
	 *
	 * @param {Event} e Click or keydown event.
	 */
	function noteShopperAction( e ) {
		if ( ! e.isTrusted ) {
			return;
		}

		isShopperAction = true;
		setTimeout( function () {
			isShopperAction = false;
		}, 0 );
	}

	form.addEventListener( 'click', noteShopperAction, true );
	form.addEventListener(
		'keydown',
		function ( e ) {
			if ( e.key === 'Enter' ) {
				noteShopperAction( e );
			}
		},
		true
	);

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

			// Session ID already injected — let through our re-submit and
			// gateway re-triggers after tokenization. A submit by the shopper
			// is a new attempt after a payment that stayed on the page, so it
			// acquires a new session ID. The current fields stay until the new
			// ones are ready, in case the gateway still posts the form for the
			// earlier attempt.
			if (
				document.getElementById( sessionIdField ) &&
				! ( e.isTrusted && isShopperAction )
			) {
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

	// Native submits stop at the capture listener until the session ID field
	// is present, so without the field this is a submit from jQuery's trigger().
	$( form ).on( 'submit', function ( e ) {
		const fraudProtection = window.wcFraudProtection;

		// An error here would stop trigger() before it posts the form.
		if (
			! fraudProtection ||
			! fraudProtection.config ||
			( ! fraudProtection.acquireSessionIdResult &&
				! fraudProtection.acquireSessionId )
		) {
			return;
		}

		// The held submit's re-submit will post the form.
		if ( resubmitPending ) {
			e.preventDefault();
			e.stopImmediatePropagation();
			return;
		}

		if (
			! document.getElementById( fraudProtection.config.sessionIdField )
		) {
			addPreparedSessionId( fraudProtection );
		}
	} );

	// A page restored from the back-forward cache keeps the fields of the
	// attempt made before the shopper left it. The next submit needs a new
	// session ID.
	window.addEventListener( 'pageshow', function ( e ) {
		const fraudProtection = window.wcFraudProtection;
		if ( ! e.persisted || ! fraudProtection || ! fraudProtection.config ) {
			return;
		}

		const { sessionIdField, sessionIdErrorField } = fraudProtection.config;
		[ sessionIdField, sessionIdErrorField ].forEach( function ( name ) {
			if ( name ) {
				$( form )
					.find( 'input[name="' + name + '"]' )
					.remove();
			}
		} );
	} );

	// Keep a session ID ready for a submit that cannot wait for one.
	if (
		window.wcFraudProtection &&
		typeof window.wcFraudProtection.prepareSessionId === 'function'
	) {
		window.wcFraudProtection.prepareSessionId();
	}
} )( jQuery );
