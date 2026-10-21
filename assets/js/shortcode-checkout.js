/**
 * Shortcode checkout fraud protection integration.
 *
 * Gates the classic checkout form submission via
 * wcFraudProtection.acquireSessionId(), injects the session ID as a
 * hidden field (or, without one, the reason why), then re-triggers submit.
 * On re-entry (field present), lets through.
 *
 * The field stays until the checkout request completes, because a gateway
 * can stop a submit and submit the form again itself, for example after
 * creating a payment method in the browser. Once the request completes,
 * its session ID was used: the fields are removed and the session is reset
 * so the next attempt gets a fresh one. A checkout_error event does the
 * same, for failures reported without that request.
 *
 * Another checkout_place_order handler can let the submit continue before
 * the session ID is ready. The checkout request then leaves without the
 * field, so the request itself gets the session ID prepared in advance, or
 * the `not_ready` reason when none is ready. A session ID that arrives
 * after such a request was sent is unused, and stays for the next attempt.
 *
 * Depends on blackbox-init.js (wcFraudProtection) and jQuery.
 */
/* global jQuery */
( function ( $ ) {
	'use strict';

	// Forms that hold fields added by this script.
	const formsWithFields = new Set();
	// What each checkout request carried when it was sent, by its settings.
	const checkoutRequests = new WeakMap();
	// Prepared session IDs that checkout requests took because they left
	// without the field. A session ID acquired for the same attempt is not sent again.
	const usedSessionIds = new Set();
	// The settings of the checkout request in flight.
	let checkoutRequestInFlight = null;

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
	 * Set a hidden field on the form, adding it when missing.
	 *
	 * @param {Object} $form Form.
	 * @param {string} name  Field name, also used as its ID.
	 * @param {string} value Field value.
	 */
	function setField( $form, name, value ) {
		const $existingField = $form
			.find( 'input[name="' + name + '"]' )
			.first();

		if ( $existingField.length ) {
			$existingField.val( value );
		} else {
			$( '<input>', {
				type: 'hidden',
				name,
				id: name,
				value,
			} ).appendTo( $form );
		}
	}

	/**
	 * Remove the fields of a finished attempt and start a fresh session.
	 *
	 * @param {Object}  [request]         What the finished checkout request carried, when known.
	 * @param {string}  request.sessionId Session ID the request carried, or an empty string.
	 * @param {boolean} request.fromForm  Whether the fields came from the form.
	 */
	function resetAfterAttempt( request ) {
		const fraudProtection = window.wcFraudProtection;
		if ( ! fraudProtection || ! fraudProtection.config ) {
			return;
		}

		const config = fraudProtection.config;
		let sessionIdWasSent = false;

		formsWithFields.forEach( function ( form ) {
			const $sessionIdField = $( form ).find(
				'input[name="' + config.sessionIdField + '"]'
			);
			const sessionId = $sessionIdField.val();
			// Added after the request was sent, so no request used it yet.
			if (
				request &&
				! request.fromForm &&
				sessionId &&
				! usedSessionIds.has( sessionId )
			) {
				return;
			}

			if ( sessionId ) {
				sessionIdWasSent = true;
			}
			$sessionIdField.remove();
			if ( config.sessionIdErrorField ) {
				$( form )
					.find( 'input[name="' + config.sessionIdErrorField + '"]' )
					.remove();
			}
			formsWithFields.delete( form );
		} );

		// Without a session ID, the current session was not used. A request
		// that took the prepared session ID already started a fresh one.
		if ( request ) {
			sessionIdWasSent = request.fromForm && !! request.sessionId;
		}
		if ( sessionIdWasSent && typeof fraudProtection.reset === 'function' ) {
			fraudProtection.reset();
		}
	}

	/**
	 * Add the session ID to a checkout request that left without it.
	 *
	 * Takes the session ID prepared in advance, or reports `not_ready` when
	 * none is ready. Records what the request carries for the cleanup after
	 * it completes.
	 *
	 * @param {Object} settings jQuery AJAX settings, before the request is sent.
	 */
	function completeCheckoutRequest( settings ) {
		const fraudProtection = window.wcFraudProtection;
		if (
			! fraudProtection ||
			! fraudProtection.config ||
			( ! fraudProtection.acquireSessionIdResult &&
				! fraudProtection.acquireSessionId ) ||
			typeof settings.data !== 'string'
		) {
			return;
		}

		const config = fraudProtection.config;
		const params = new URLSearchParams( settings.data );
		const request = {
			sessionId: params.get( config.sessionIdField ) || '',
			fromForm: params.has( config.sessionIdField ),
		};

		if ( ! request.fromForm ) {
			request.sessionId =
				typeof fraudProtection.takePreparedSessionId === 'function'
					? fraudProtection.takePreparedSessionId()
					: '';

			const fields = { [ config.sessionIdField ]: request.sessionId };
			if ( ! request.sessionId && config.sessionIdErrorField ) {
				fields[ config.sessionIdErrorField ] = 'not_ready';
			}
			settings.data += ( settings.data ? '&' : '' ) + $.param( fields );
			if ( request.sessionId ) {
				usedSessionIds.add( request.sessionId );
			}
		}

		checkoutRequests.set( settings, request );
	}

	/**
	 * Check whether an AJAX request is WooCommerce's checkout request.
	 *
	 * @param {Object} settings jQuery AJAX settings.
	 * @return {boolean} Whether it is the checkout request.
	 */
	function isCheckoutRequest( settings ) {
		try {
			return (
				new URL(
					settings && settings.url,
					window.location.href
				).searchParams.get( 'wc-ajax' ) === 'checkout'
			);
		} catch {
			return false;
		}
	}

	$( 'form.checkout' ).on( 'checkout_place_order', function () {
		const fraudProtection = window.wcFraudProtection;

		if (
			! fraudProtection ||
			( ! fraudProtection.acquireSessionIdResult &&
				! fraudProtection.acquireSessionId )
		) {
			return true;
		}

		const config = fraudProtection.config;
		const $form = $( this );

		// Re-entry: the field is present, so this attempt has its session ID.
		if (
			$form.find( 'input[name="' + config.sessionIdField + '"]' ).length
		) {
			return true;
		}

		acquireSessionIdResult( fraudProtection ).then( function ( result ) {
			// A checkout request that left without waiting already took it.
			if ( usedSessionIds.has( result.sessionId ) ) {
				return;
			}

			setField( $form, config.sessionIdField, result.sessionId );
			if (
				! result.sessionId &&
				result.error &&
				config.sessionIdErrorField
			) {
				setField( $form, config.sessionIdErrorField, result.error );
			}
			formsWithFields.add( $form[ 0 ] );

			$form.trigger( 'submit' );
		} );

		return false;
	} );

	// Keep a session ID ready for a checkout request that leaves without one.
	if (
		window.wcFraudProtection &&
		typeof window.wcFraudProtection.prepareSessionId === 'function'
	) {
		window.wcFraudProtection.prepareSessionId();
	}

	$.ajaxPrefilter( function ( settings ) {
		if ( isCheckoutRequest( settings ) ) {
			completeCheckoutRequest( settings );
		}
	} );

	$( document ).on( 'ajaxSend', function ( event, xhr, settings ) {
		if ( checkoutRequests.has( settings ) ) {
			checkoutRequestInFlight = settings;
		}
	} );

	$( document ).on( 'ajaxComplete', function ( event, xhr, settings ) {
		if ( isCheckoutRequest( settings ) ) {
			if ( checkoutRequestInFlight === settings ) {
				checkoutRequestInFlight = null;
			}
			resetAfterAttempt( checkoutRequests.get( settings ) );
		}
	} );

	$( document.body ).on( 'checkout_error', function () {
		// The checkout request reports its error before it completes, and
		// the cleanup after it knows what the request carried.
		if ( ! checkoutRequestInFlight ) {
			resetAfterAttempt();
		}
	} );
} )( jQuery );
